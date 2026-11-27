<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;

/**
 * The merged state of a replicated row (collab_rows): the winning value of
 * each field in global ids, the concurrent latest writes per field (heads),
 * who created the row, and this node's own changes still waiting for review.
 * It is a cache: everything can be recomputed from the operation log.
 */
final class Shadow
{
    public static function get(string $tbl, string $gid): ?object
    {
        $row = DB::table('collab_rows')->where('tbl', $tbl)->where('gid', $gid)->first();
        if (! $row) {
            return null;
        }
        $row->data = json_decode($row->data ?? '[]', true) ?: [];
        $row->heads = json_decode($row->heads ?? '[]', true) ?: [];
        $row->self_pending = json_decode($row->self_pending ?? '[]', true) ?: [];
        $row->deleted = (bool) $row->deleted;

        return $row;
    }

    /** Head op ids per field (the "base" a new local change replaces). */
    public static function base(?object $shadow, array $fields): array
    {
        $base = [];
        foreach ($fields as $field) {
            $ids = array_map(fn ($h) => $h[0], $shadow->heads[$field] ?? []);
            if ($ids) {
                $base[$field] = $ids;
            }
        }

        return $base;
    }

    public static function save(string $tbl, string $gid, string $projectGid, array $state): void
    {
        DB::table('collab_rows')->updateOrInsert(['tbl' => $tbl, 'gid' => $gid], [
            'project_gid' => $projectGid,
            'creator' => $state['creator'],
            'data' => json_encode($state['data'], JSON_UNESCAPED_UNICODE),
            'heads' => json_encode($state['heads'], JSON_UNESCAPED_UNICODE),
            'self_pending' => $state['selfPending'] ? json_encode($state['selfPending'], JSON_UNESCAPED_UNICODE) : null,
            'deleted' => $state['deleted'],
            'updated_at' => now(),
        ]);
    }

    public static function setState(string $tbl, string $gid, string $state, ?string $waitingOn = null): void
    {
        DB::table('collab_rows')->where('tbl', $tbl)->where('gid', $gid)->update(['state' => $state, 'waiting_on' => $waitingOn]);
    }

    /** @return array<int, object> rows held back until $gid is present again */
    public static function waitingOn(string $gid): array
    {
        return DB::table('collab_rows')->where('waiting_on', $gid)->whereIn('state', ['waiting', 'orphan'])->get(['tbl', 'gid', 'project_gid'])->all();
    }
}
