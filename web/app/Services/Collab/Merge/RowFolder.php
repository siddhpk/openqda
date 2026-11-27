<?php

namespace App\Services\Collab\Merge;

/**
 * Folds all operations of one row into its merged state. Pure: the result
 * depends only on the set of operations (and the roster), never on the
 * order they arrived in, so every laptop ends with the same row.
 *
 * Each field is a multi-value register: it keeps the concurrent latest
 * writes ("heads"). A write lists the heads it saw ("base") and replaces
 * them; writes made without seeing each other stay side by side and the
 * visible value is the head with the highest (role rank, time, op id). So
 * a change made after seeing another always replaces it, a Primary beats a
 * concurrent Assistant, and among equals the later change wins.
 *
 * Operations are processed in time order, which is also a causal order
 * (hybrid logical clocks stamp an op later than everything its node saw).
 */
final class RowFolder
{
    /**
     * @param  array<int, array>  $ops  decoded ops (put / reject) of one row
     * @param  callable(string $node, string $hlc): ?string  $roleAt  role of a node at a time, null if not a member
     * @return array{data: array, heads: array, creator: ?string, deleted: bool, statuses: array<string,string>, selfPending: array, pending: array}
     */
    public static function fold(array $ops, callable $roleAt, string $selfNode): array
    {
        usort($ops, fn ($a, $b) => [$a['h'], $a['id']] <=> [$b['h'], $b['id']]);
        $heads = [];
        $creator = null;
        $statuses = [];
        $decided = [];
        $pending = [];

        foreach ($ops as $op) {
            $role = $roleAt($op['o'], $op['h']);
            if ($role === null) {
                $statuses[$op['id']] = 'invalid';

                continue;
            }
            if ($op['k'] === 'reject') {
                if ($role === ReviewPolicy::PRIMARY) {
                    foreach ((array) ($op['f']['ops'] ?? []) as $id) {
                        $decided[$id] ??= 'rejected';
                    }
                    $statuses[$op['id']] = 'applied';
                } else {
                    $statuses[$op['id']] = 'invalid';
                }

                continue;
            }
            if ($op['k'] !== 'put') {
                continue;
            }
            $creator ??= $op['o'];
            if ($role === ReviewPolicy::PRIMARY) {
                foreach ((array) ($op['a'] ?? []) as $id) {
                    $decided[$id] ??= 'accepted';
                }
            }
            if (ReviewPolicy::needsReview($role, $op['o'], $creator)) {
                $statuses[$op['id']] = 'pending';
                $pending[] = $op;

                continue;
            }
            $rank = ReviewPolicy::rank($role);
            foreach ((array) $op['f'] as $field => $value) {
                $base = (array) ($op['b'][$field] ?? []);
                $kept = array_values(array_filter($heads[$field] ?? [], fn ($h) => ! in_array($h[0], $base, true)));
                $kept[] = [$op['id'], $rank, $op['h'], $value];
                $heads[$field] = $kept;
            }
            $statuses[$op['id']] = 'applied';
        }

        $selfPending = [];
        $open = [];
        foreach ($pending as $op) {
            $status = $decided[$op['id']] ?? 'pending';
            $statuses[$op['id']] = $status;
            if ($status === 'pending') {
                $open[] = $op['id'];
                if ($op['o'] === $selfNode) {
                    $selfPending = array_merge($selfPending, (array) $op['f']);
                }
            }
        }

        $data = [];
        foreach ($heads as $field => $list) {
            $data[$field] = self::winner($list)[3];
        }

        return [
            'data' => $data,
            'heads' => $heads,
            'creator' => $creator,
            'deleted' => (bool) ($data['_deleted'] ?? false),
            'statuses' => $statuses,
            'selfPending' => $selfPending,
            'pending' => $open,
        ];
    }

    /** The visible head: highest (rank, time, op id). */
    public static function winner(array $heads): array
    {
        usort($heads, fn ($a, $b) => [$b[1], $b[2], $b[0]] <=> [$a[1], $a[2], $a[0]]);

        return $heads[0];
    }
}
