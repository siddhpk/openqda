<?php

namespace App\Services\Collab;

use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The review list of a collaborative project: Assistants' changes to items
 * someone else created, and items held back because their parent was
 * deleted. Primaries accept or reject; decisions are operations too.
 */
final class Reviews
{
    public static function count(string $projectGid): int
    {
        return DB::table('collab_ops')->where('project_gid', $projectGid)->where('status', 'pending')->where('kind', 'put')->count()
            + DB::table('collab_rows')->where('project_gid', $projectGid)->where('state', 'orphan')->count();
    }

    public static function mine(string $projectGid): int
    {
        return DB::table('collab_ops')->where('project_gid', $projectGid)->where('status', 'pending')
            ->where('origin', Collab::node())->count();
    }

    public static function list(string $projectGid): array
    {
        $members = collect(Roster::members($projectGid))->keyBy('node');
        $changes = [];
        $ops = DB::table('collab_ops')->where('project_gid', $projectGid)->where('status', 'pending')->where('kind', 'put')
            ->orderBy('hlc')->get(['payload']);
        foreach ($ops as $row) {
            $op = json_decode($row->payload, true);
            $shadow = Shadow::get($op['t'], (string) $op['r']);
            $fields = array_diff_key((array) $op['f'], array_flip(['_ygen', '_yhash', '_file', '_html', '_ext']));
            $changes[] = [
                'op' => $op['id'],
                'tbl' => $op['t'],
                'row' => $op['r'],
                'by' => $members[$op['o']]['name'] ?? $op['o'],
                'mine' => $op['o'] === Collab::node(),
                'at' => self::time($op['h']),
                'label' => self::label($op['t'], $shadow?->data ?? []),
                'delete' => ! empty($fields['_deleted']),
                'fields' => collect($fields)->except('_deleted')->map(fn ($v, $k) => [
                    'old' => self::show($shadow?->data[$k] ?? null),
                    'new' => self::show($v),
                ])->all(),
            ];
        }
        $orphans = [];
        foreach (DB::table('collab_rows')->where('project_gid', $projectGid)->where('state', 'orphan')->get() as $row) {
            $parent = DB::table('collab_rows')->where('gid', $row->waiting_on)->first();
            $orphans[] = [
                'tbl' => $row->tbl,
                'gid' => $row->gid,
                'label' => self::label($row->tbl, json_decode($row->data ?? '[]', true) ?: []),
                'parent' => $parent ? self::label($parent->tbl, json_decode($parent->data ?? '[]', true) ?: []) : null,
            ];
        }

        return ['changes' => $changes, 'orphans' => $orphans];
    }

    private static function time(string $hlc): string
    {
        [$ms] = Hlc::parse($hlc);

        return date(DATE_ATOM, intdiv($ms, 1000));
    }

    private static function show(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_array($value) ? json_encode($value) : (string) $value;

        return Str::limit(trim(strip_tags($text)), 160);
    }

    public static function label(string $tbl, array $data): string
    {
        $text = $data['name'] ?? $data['title'] ?? $data['text'] ?? $data['content'] ?? null;
        $kind = ['codes' => 'Code', 'codebooks' => 'Codebook', 'sources' => 'Document', 'selections' => 'Coding', 'notes' => 'Memo',
            'variables' => 'Attribute', 'code_relations' => 'Relationship', 'concepts' => 'Concept', 'propositions' => 'Proposition',
            'cases' => 'Case', 'projects' => 'Project', 'analytic_evidence' => 'Evidence'][$tbl] ?? Str::headline($tbl);

        return $text ? $kind.': '.Str::limit(trim(strip_tags((string) $text)), 80) : $kind;
    }

    /**
     * Accept or reject pending changes (Primaries only).
     *
     * @return array<int, array> batches for the node agent
     */
    public static function decide(string $projectGid, array $opIds, string $decision): array
    {
        abort_unless(Roster::myRole($projectGid) === ReviewPolicy::PRIMARY, 403, 'Only a Primary can review changes.');
        $ops = DB::table('collab_ops')->where('project_gid', $projectGid)->whereIn('op_id', $opIds)->where('status', 'pending')
            ->orderBy('hlc')->get(['payload'])->map(fn ($r) => json_decode($r->payload, true));
        $groups = $ops->groupBy(fn ($op) => $op['t'].'|'.$op['r']);
        $out = [];
        $events = Engine::applying(function () use ($groups, $projectGid, $decision, &$out) {
            $rows = [];
            foreach ($groups as $group) {
                $first = $group->first();
                $ids = $group->pluck('id')->all();
                if ($decision === 'accept') {
                    $fields = $group->reduce(fn ($carry, $op) => array_merge($carry, (array) $op['f']), []);
                    $shadow = Shadow::get($first['t'], (string) $first['r']);
                    $out[] = OpLog::append($projectGid, $first['t'], (string) $first['r'], 'put', $fields, Shadow::base($shadow, array_keys($fields)), ['a' => $ids]);
                } else {
                    $out[] = OpLog::append($projectGid, $first['t'], (string) $first['r'], 'reject', ['ops' => $ids]);
                }
                $rows[] = [$first['t'], (string) $first['r'], $projectGid];
            }

            return Engine::materializeAll($rows);
        });

        return [['project' => $projectGid, 'ops' => $out, 'events' => $events]];
    }

    /**
     * Bring back the deleted parent of a held-back item, or delete the item.
     */
    public static function orphan(string $projectGid, string $tbl, string $gid, string $action): array
    {
        abort_unless(Roster::myRole($projectGid) === ReviewPolicy::PRIMARY, 403, 'Only a Primary can review changes.');
        $row = Shadow::get($tbl, $gid);
        abort_unless($row && $row->state === 'orphan' && $row->project_gid === $projectGid, 404);
        $targetTbl = $tbl;
        $targetGid = $gid;
        $deleted = true;
        if ($action === 'restore') {
            $parent = DB::table('collab_rows')->where('gid', $row->waiting_on)->first();
            abort_unless($parent, 404);
            [$targetTbl, $targetGid, $deleted] = [$parent->tbl, $parent->gid, false];
        }
        $ops = [];
        $events = Engine::applying(function () use ($projectGid, $targetTbl, $targetGid, $deleted, &$ops) {
            $shadow = Shadow::get($targetTbl, $targetGid);
            $ops[] = OpLog::append($projectGid, $targetTbl, $targetGid, 'put', ['_deleted' => $deleted], Shadow::base($shadow, ['_deleted']));

            return Engine::materializeAll([[$targetTbl, $targetGid, $projectGid]]);
        });

        return [['project' => $projectGid, 'ops' => $ops, 'events' => $events]];
    }
}
