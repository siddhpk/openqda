<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;

/**
 * Turns local edits into signed operations.
 *
 * SQLite triggers (see Triggers) note every changed row of a replicated
 * table in collab_dirty, whatever made the change (Eloquent, query builder
 * bulk deletes, foreign key cascades). flush() compares each noted row with
 * its merged state and writes one operation with the fields that differ.
 * It runs after every request (CollabCapture middleware) and when the node
 * agent asks (every 30 s), and its result is handed to the agent.
 */
final class ChangeCapture
{
    /** @var array<string, array> extra fields per "table|local id" for this request */
    private static array $virtual = [];

    public static function virtual(string $tbl, string $localId, array $fields): void
    {
        self::$virtual["{$tbl}|{$localId}"] = array_merge(self::$virtual["{$tbl}|{$localId}"] ?? [], $fields);
    }

    /**
     * @return array<int, array{project:string, ops:array, events:array}>
     */
    public static function flush(): array
    {
        if (! Collab::enabled() || ! DB::table('collab_dirty')->exists()) {
            return [];
        }
        $batches = [];
        DB::transaction(function () use (&$batches) {
            $dirty = DB::table('collab_dirty')->get()->all();
            usort($dirty, fn ($a, $b) => SyncSchema::order($a->tbl) <=> SyncSchema::order($b->tbl));
            foreach ($dirty as $d) {
                DB::table('collab_dirty')->where('tbl', $d->tbl)->where('pk', $d->pk)->delete();
                if (! SyncSchema::has($d->tbl)) {
                    continue;
                }
                foreach (self::capture($d->tbl, (string) $d->pk) as [$projectGid, $op, $event]) {
                    $batches[$projectGid] ??= ['project' => $projectGid, 'ops' => [], 'events' => []];
                    $batches[$projectGid]['ops'][] = $op;
                    if ($event) {
                        $batches[$projectGid]['events'][] = $event;
                    }
                }
            }
        });
        self::$virtual = [];

        return array_values($batches);
    }

    /** @return array<int, array{0:string,1:array,2:?array}> */
    private static function capture(string $tbl, string $pk): array
    {
        $row = SyncSchema::load($tbl, $pk);
        $gid = IdMap::gid($tbl, $pk);

        if ($row === null) {
            return $gid ? self::deletion($tbl, $pk, $gid) : [];
        }
        $projectGid = Collab::projectGid(SyncSchema::projectIdOf($tbl, $row));
        if (! $projectGid) {
            return [];
        }
        if (! SyncSchema::replicable($tbl, $row)) {
            // for example a memo made private: it leaves the other laptops
            return $gid ? self::deletion($tbl, $pk, $gid) : [];
        }

        $gid ??= IdMap::ensureGid($tbl, $pk);
        [$data, $users] = SyncSchema::toGlobal($tbl, $row);
        if (! empty(SyncSchema::def($tbl)['files'])) {
            $data += Blobs::fields($row);
        }
        $virtual = self::$virtual["{$tbl}|{$pk}"] ?? [];
        if (isset($virtual['_ygen'])) {
            $virtual['_yhash'] = Docs::contentHash($tbl, $pk);
        }
        $data = array_merge($data, $virtual);

        $shadow = Shadow::get($tbl, $gid);
        $current = $shadow ? array_merge($shadow->data, $shadow->self_pending) : [];
        if ($shadow && ! empty($current['_deleted'])) {
            $data['_deleted'] = false; // present here again
        }
        $changed = [];
        foreach ($data as $field => $value) {
            if (! $shadow || Canonical::norm($value) !== Canonical::norm($current[$field] ?? null)) {
                $changed[$field] = $value;
            }
        }
        if (! $changed) {
            return [];
        }

        $out = [];
        foreach ($users as $userGid => $localUser) {
            if ($op = Users::publish($projectGid, $userGid, $localUser)) {
                $out[] = [$projectGid, $op, null];
            }
        }
        $op = OpLog::append($projectGid, $tbl, $gid, 'put', $changed, Shadow::base($shadow, array_keys($changed)));
        Engine::refold($tbl, $gid, $projectGid);
        $out[] = [$projectGid, $op, Events::upsert($tbl, $pk, $projectGid, $row)];

        return $out;
    }

    private static function deletion(string $tbl, string $pk, string $gid): array
    {
        $shadow = Shadow::get($tbl, $gid);
        // a project is never deleted for everyone: deleting it here means leaving
        if (! $shadow || $shadow->deleted || $tbl === 'projects' || ! Collab::projectId($shadow->project_gid)) {
            return [];
        }
        $op = OpLog::append($shadow->project_gid, $tbl, $gid, 'put', ['_deleted' => true], Shadow::base($shadow, ['_deleted']));
        Engine::refold($tbl, $gid, $shadow->project_gid);

        return [[$shadow->project_gid, $op, Events::deleted($tbl, $pk, $shadow->project_gid)]];
    }
}
