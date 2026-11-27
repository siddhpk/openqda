<?php

namespace Tests\Unit\Collab;

use App\Services\Collab\Merge\ReviewPolicy;
use App\Services\Collab\Merge\RowFolder;
use PHPUnit\Framework\TestCase;

/**
 * The merge of one row is a pure function of its operations: these tests
 * need no database and check convergence, roles and reviews.
 */
class RowFolderTest extends TestCase
{
    private const P = 'nprimary000000000000a';

    private const A = 'nassist0000000000000b';

    private const C = 'nassist0000000000000c';

    private static function roles(string $node, string $hlc): ?string
    {
        return match ($node) {
            self::P => ReviewPolicy::PRIMARY,
            self::A, self::C => ReviewPolicy::ASSISTANT,
            default => null,
        };
    }

    private static function op(string $origin, int $seq, int $ms, array $fields, array $base = [], array $extra = []): array
    {
        return ['id' => "{$origin}:{$seq}", 'o' => $origin, 's' => $seq, 'h' => sprintf('%016d-0000-%s', $ms, $origin),
            'k' => 'put', 'f' => $fields, 'b' => $base] + $extra;
    }

    private static function fold(array $ops, string $self = self::P): array
    {
        return RowFolder::fold($ops, fn ($node, $hlc) => self::roles($node, $hlc), $self);
    }

    public function test_a_later_edit_that_saw_the_value_replaces_it(): void
    {
        $create = self::op(self::P, 1, 100, ['name' => 'Trust', 'color' => '#f00']);
        $rename = self::op(self::P, 2, 200, ['name' => 'Confidence'], ['name' => ['nprimary000000000000a:1']]);
        $state = self::fold([$rename, $create]);
        $this->assertSame(['name' => 'Confidence', 'color' => '#f00'], $state['data']);
        $this->assertSame(self::P, $state['creator']);
        $this->assertCount(1, $state['heads']['name']);
    }

    public function test_a_primary_beats_a_concurrent_assistant_even_when_later(): void
    {
        $create = self::op(self::A, 1, 100, ['name' => 'Coping']);
        // both edit the assistant's own code without seeing each other's change
        $primary = self::op(self::P, 1, 200, ['name' => 'Coping strategies'], ['name' => [self::A.':1']]);
        $assistant = self::op(self::A, 2, 300, ['name' => 'Coping (revised)'], ['name' => [self::A.':1']]);
        $state = self::fold([$create, $assistant, $primary]);
        $this->assertSame('Coping strategies', $state['data']['name']);
        $this->assertCount(2, $state['heads']['name'], 'both concurrent writes stay as heads');

        // the assistant edits again after seeing the primary's value: that wins
        $later = self::op(self::A, 3, 400, ['name' => 'Coping, final'], ['name' => [self::P.':1', self::A.':2']]);
        $this->assertSame('Coping, final', self::fold([$create, $assistant, $primary, $later])['data']['name']);
    }

    public function test_equal_roles_resolve_by_time(): void
    {
        $create = self::op(self::A, 1, 100, ['note' => 'x']);
        $one = self::op(self::A, 2, 200, ['note' => 'mine'], ['note' => [self::A.':1']]);
        $two = self::op(self::A, 3, 150, ['note' => 'earlier'], ['note' => [self::A.':1']]);
        $this->assertSame('mine', self::fold([$create, $two, $one])['data']['note']);
    }

    public function test_an_assistant_change_to_someone_elses_item_waits_for_review(): void
    {
        $create = self::op(self::P, 1, 100, ['name' => 'Trust']);
        $edit = self::op(self::A, 1, 200, ['name' => 'Distrust'], ['name' => [self::P.':1']]);
        $state = self::fold([$create, $edit]);
        $this->assertSame('Trust', $state['data']['name']);
        $this->assertSame('pending', $state['statuses'][self::A.':1']);
        $this->assertSame([self::A.':1'], $state['pending']);
        $this->assertSame([], $state['selfPending'], 'the primary does not see it as its own');
        // on the assistant's laptop the change stays visible while pending
        $this->assertSame(['name' => 'Distrust'], self::fold([$create, $edit], self::A)['selfPending']);
    }

    public function test_accepting_applies_the_change_as_the_primary_and_rejecting_drops_it(): void
    {
        $create = self::op(self::P, 1, 100, ['name' => 'Trust']);
        $edit = self::op(self::A, 1, 200, ['name' => 'Distrust'], ['name' => [self::P.':1']]);
        $accept = self::op(self::P, 2, 300, ['name' => 'Distrust'], ['name' => [self::P.':1']], ['a' => [self::A.':1']]);
        $state = self::fold([$create, $edit, $accept], self::A);
        $this->assertSame('Distrust', $state['data']['name']);
        $this->assertSame('accepted', $state['statuses'][self::A.':1']);
        $this->assertSame([], $state['selfPending']);

        $reject = ['id' => self::P.':2', 'o' => self::P, 's' => 2, 'h' => sprintf('%016d-0000-%s', 300, self::P), 'k' => 'reject',
            'f' => ['ops' => [self::A.':1']]];
        $state = self::fold([$create, $edit, $reject], self::A);
        $this->assertSame('Trust', $state['data']['name']);
        $this->assertSame('rejected', $state['statuses'][self::A.':1']);
        $this->assertSame([], $state['selfPending'], 'the assistant laptop restores the shared value');
    }

    public function test_additions_and_own_items_of_an_assistant_merge_at_once(): void
    {
        $create = self::op(self::A, 1, 100, ['text' => 'a coding', 'code_id' => 'c1']);
        $edit = self::op(self::A, 2, 200, ['text' => 'a longer coding'], ['text' => [self::A.':1']]);
        $state = self::fold([$create, $edit]);
        $this->assertSame('a longer coding', $state['data']['text']);
        $this->assertSame([], $state['pending']);
    }

    public function test_deletion_is_a_field_and_another_assistant_cannot_delete_silently(): void
    {
        $create = self::op(self::A, 1, 100, ['text' => 'coding']);
        $deleteByOther = self::op(self::C, 1, 200, ['_deleted' => true]);
        $this->assertFalse(self::fold([$create, $deleteByOther])['deleted']);
        $deleteByPrimary = self::op(self::P, 1, 200, ['_deleted' => true]);
        $this->assertTrue(self::fold([$create, $deleteByPrimary])['deleted']);
    }

    public function test_unknown_nodes_and_non_primary_rejections_have_no_effect(): void
    {
        $create = self::op(self::P, 1, 100, ['name' => 'Trust']);
        $stranger = self::op('nstranger00000000000z', 1, 200, ['name' => 'Hacked'], ['name' => [self::P.':1']]);
        $edit = self::op(self::A, 1, 300, ['name' => 'Distrust'], ['name' => [self::P.':1']]);
        $fakeReject = ['id' => self::C.':1', 'o' => self::C, 's' => 1, 'h' => sprintf('%016d-0000-%s', 400, self::C), 'k' => 'reject',
            'f' => ['ops' => [self::A.':1']]];
        $state = self::fold([$create, $stranger, $edit, $fakeReject]);
        $this->assertSame('Trust', $state['data']['name']);
        $this->assertSame('invalid', $state['statuses']['nstranger00000000000z:1']);
        $this->assertSame('invalid', $state['statuses'][self::C.':1']);
        $this->assertSame('pending', $state['statuses'][self::A.':1']);
    }

    /**
     * Property: every delivery order of the same operations gives the same
     * row (simulated three laptops editing concurrently with partial views).
     */
    public function test_every_order_of_the_same_operations_gives_the_same_row(): void
    {
        mt_srand(20261124);
        for ($round = 0; $round < 60; $round++) {
            $ops = [self::op(self::A, 1, 1000, ['name' => 'n0', 'color' => 'c0', 'memo' => 'm0'])];
            $seq = [self::P => 0, self::A => 1, self::C => 0];
            $views = [self::P => [0], self::A => [0], self::C => [0]]; // indices each node has seen
            for ($i = 0; $i < 14; $i++) {
                $node = [self::P, self::A, self::C][mt_rand(0, 2)];
                // a node sometimes learns some of what others did
                foreach (array_keys($ops) as $k) {
                    if (mt_rand(0, 2) === 0 && ! in_array($k, $views[$node], true)) {
                        $views[$node][] = $k;
                    }
                }
                $seen = array_map(fn ($k) => $ops[$k], $views[$node]);
                $mine = self::fold($seen, $node);
                $field = ['name', 'color', 'memo', '_deleted'][mt_rand(0, 3)];
                $base = [$field => array_map(fn ($h) => $h[0], $mine['heads'][$field] ?? [])];
                $value = $field === '_deleted' ? (bool) mt_rand(0, 1) : $field.$i;
                $maxSeen = max(array_map(fn ($o) => (int) substr($o['h'], 0, 16), $seen));
                $seq[$node]++;
                $ops[] = self::op($node, $seq[$node], $maxSeen + mt_rand(1, 50), [$field => $value], $base);
                $views[$node][] = count($ops) - 1;
            }
            $reference = self::fold($ops);
            for ($perm = 0; $perm < 8; $perm++) {
                $shuffled = $ops;
                shuffle($shuffled);
                $state = self::fold($shuffled);
                $this->assertSame($reference['data'], $state['data'], "round {$round}: data differs");
                $this->assertSame($reference['statuses'], $state['statuses'], "round {$round}: statuses differ");
            }
        }
    }
}
