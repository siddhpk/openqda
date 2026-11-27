<?php

namespace App\Services\Collab;

use App\Services\Collab\Merge\RowFolder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the operation log into rows: refold recomputes a row's merged
 * state from all its operations, materialize writes that state into the
 * application's tables.
 *
 * A row whose parent has not arrived yet waits; a row whose parent was
 * deleted elsewhere (for example a coding on a code a Primary deleted) is
 * held back as an orphan for review. Both come back as soon as the parent
 * is present again.
 */
final class Engine
{
    private static int $depth = 0;

    private static array $retry = [];

    /** Run $fn in a transaction with the capture triggers silenced. */
    public static function applying(callable $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            if (self::$depth++ === 0) {
                DB::table('collab_ctx')->where('id', 1)->update(['applying' => 1]);
            }
            try {
                return $fn();
            } finally {
                if (--self::$depth === 0) {
                    DB::table('collab_ctx')->where('id', 1)->update(['applying' => 0]);
                }
            }
        });
    }

    public static function refold(string $tbl, string $gid, string $projectGid): array
    {
        $ops = OpLog::forRow($tbl, $gid);
        $state = RowFolder::fold($ops, fn ($node, $hlc) => Roster::roleAt($projectGid, $node, $hlc), Collab::node());
        foreach ($ops as $op) {
            $status = $state['statuses'][$op['id']] ?? $op['_status'];
            if ($status !== $op['_status']) {
                OpLog::setStatus($projectGid, $op['id'], $status);
            }
        }
        Shadow::save($tbl, $gid, $projectGid, $state);

        return $state;
    }

    /**
     * Write a row's merged state (plus this node's own pending changes) to
     * its table. Returns a browser event, or null when nothing changed here.
     */
    public static function materialize(string $tbl, string $gid, string $projectGid, array $state): ?array
    {
        $values = array_merge($state['data'], $state['selfPending']);
        $local = IdMap::local($tbl, $gid);

        if ($tbl === 'projects') {
            if ($local === null) {
                return null;
            }
            $cols = array_intersect_key($values, array_flip(SyncSchema::fields('projects')));
            if ($cols) {
                DB::table('projects')->where('id', $local)->update($cols + ['updated_at' => now()]);
            }

            return Events::upsert('projects', $local, $projectGid);
        }

        $existing = $local !== null ? SyncSchema::load($tbl, $local) : null;
        if (! empty($values['_deleted'])) {
            Shadow::setState($tbl, $gid, 'ok');
            if ($existing && SyncSchema::replicable($tbl, $existing)) {
                self::deleteLocal($tbl, $local);

                return Events::deleted($tbl, $local, $projectGid);
            }

            return null;
        }
        if (! $values) {
            return null; // only pending or invalid changes so far
        }

        $t = SyncSchema::toLocal($tbl, $values, $projectGid);
        if ($t['missing']) {
            Shadow::setState($tbl, $gid, 'waiting', $t['missing'][1]);

            return null;
        }
        if ($t['deleted']) {
            Shadow::setState($tbl, $gid, 'orphan', $t['deleted'][1]);
            if ($existing) {
                self::deleteLocal($tbl, $local);

                return Events::deleted($tbl, $local, $projectGid);
            }

            return null;
        }

        $cols = $t['cols'];
        if (Schema::hasColumn($tbl, 'updated_at')) {
            $cols['updated_at'] = now();
        }
        if ($existing) {
            $update = array_diff_key($cols, array_flip(SyncSchema::pkCols($tbl)));
            if ($update) {
                SyncSchema::query($tbl, $local)->update($update);
            }
        } else {
            if (Schema::hasColumn($tbl, 'created_at') && empty($cols['created_at'])) {
                $cols['created_at'] = now();
            }
            $local = self::insert($tbl, $gid, $cols);
        }
        if (! empty(SyncSchema::def($tbl)['files'])) {
            Blobs::afterSource($local, $values, $projectGid);
        }
        Shadow::setState($tbl, $gid, 'ok');
        self::$retry[] = $gid;

        return Events::upsert($tbl, $local, $projectGid);
    }

    private static function insert(string $tbl, string $gid, array $cols): string
    {
        $def = SyncSchema::def($tbl);
        try {
            if (! empty($def['int'])) {
                $id = DB::table($tbl)->insertGetId($cols);
                IdMap::put($tbl, $id, $gid);

                return (string) $id;
            }
            if (SyncSchema::composite($tbl)) {
                DB::table($tbl)->insert($cols);
                $local = SyncSchema::pkString($tbl, $cols);
                IdMap::put($tbl, $local, $gid);

                return $local;
            }
            $cols[$def['pk']] = $gid;
            DB::table($tbl)->insert($cols);
            IdMap::put($tbl, $gid, $gid);

            return $gid;
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }
            // the same item was created on two laptops: merge into the one here
            foreach (array_merge(SyncSchema::uniques($tbl), [SyncSchema::pkCols($tbl)]) as $uniqueCols) {
                $where = array_intersect_key($cols, array_flip($uniqueCols));
                if (count($where) !== count($uniqueCols)) {
                    continue;
                }
                $match = DB::table($tbl)->where($where)->first();
                if ($match) {
                    $local = SyncSchema::pkString($tbl, (array) $match);
                    IdMap::put($tbl, $local, $gid, false);
                    $update = array_diff_key($cols, array_flip(SyncSchema::pkCols($tbl)));
                    if ($update) {
                        SyncSchema::query($tbl, $local)->update($update);
                    }

                    return $local;
                }
            }
            throw $e;
        }
    }

    /** Delete a local row; replicated children that are still alive become orphans. */
    public static function deleteLocal(string $tbl, string $local): void
    {
        $gid = IdMap::gid($tbl, $local);
        foreach (SyncSchema::children($tbl) as [$child, $col]) {
            $nullable = in_array($col, SyncSchema::def($child)['nullable_fk'] ?? [], true);
            $rows = DB::table($child)->where($col, $local)->get(SyncSchema::pkCols($child));
            foreach ($rows as $r) {
                $cpk = SyncSchema::pkString($child, (array) $r);
                if ($nullable) {
                    SyncSchema::query($child, $cpk)->update([$col => null]);

                    continue;
                }
                $cgid = IdMap::gid($child, $cpk);
                $cs = $cgid ? Shadow::get($child, $cgid) : null;
                if ($cs && ! $cs->deleted && $gid) {
                    Shadow::setState($child, $cgid, 'orphan', $gid);
                }
                self::deleteLocal($child, $cpk);
            }
        }
        SyncSchema::query($tbl, $local)->delete();
    }

    /**
     * Materialize rows that waited for a parent that is now present.
     *
     * @return array<int, array> events
     */
    public static function drainWaiting(): array
    {
        $events = [];
        $guard = 0;
        while (self::$retry && $guard++ < 10000) {
            $gid = array_shift(self::$retry);
            foreach (Shadow::waitingOn($gid) as $w) {
                $state = self::refold($w->tbl, $w->gid, $w->project_gid);
                if ($e = self::materialize($w->tbl, $w->gid, $w->project_gid, $state)) {
                    $events[] = $e;
                }
            }
        }
        self::$retry = [];

        return $events;
    }

    /** Refold and write several rows: deletions children first, the rest parents first. */
    public static function materializeAll(array $rows): array
    {
        $states = [];
        foreach ($rows as [$tbl, $gid, $projectGid]) {
            $states[] = [$tbl, $gid, $projectGid, self::refold($tbl, $gid, $projectGid)];
        }
        usort($states, function ($a, $b) {
            $da = ! empty(array_merge($a[3]['data'], $a[3]['selfPending'])['_deleted']);
            $db = ! empty(array_merge($b[3]['data'], $b[3]['selfPending'])['_deleted']);
            if ($da !== $db) {
                return $da ? -1 : 1;
            }
            $order = SyncSchema::order($a[0]) <=> SyncSchema::order($b[0]);

            return $da ? -$order : $order;
        });
        $events = [];
        foreach ($states as [$tbl, $gid, $projectGid, $state]) {
            if ($e = self::materialize($tbl, $gid, $projectGid, $state)) {
                $events[] = $e;
            }
        }

        return array_merge($events, self::drainWaiting());
    }
}
