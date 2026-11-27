<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local id <-> global id of replicated rows. Rows with uuid keys keep their
 * key as gid; numeric keys (projects, codebooks, memos, users) get a uuid;
 * tables with natural keys get a gid derived from them. Several gids may
 * point to one local row (aliases after a unique-name collision).
 */
final class IdMap
{
    private static array $toGid = [];

    private static array $toLocal = [];

    public static function reset(): void
    {
        self::$toGid = [];
        self::$toLocal = [];
    }

    public static function gid(string $tbl, string|int $local): ?string
    {
        $k = $tbl.'|'.$local;
        if (! array_key_exists($k, self::$toGid)) {
            self::$toGid[$k] = DB::table('collab_ids')->where('tbl', $tbl)->where('local_id', (string) $local)
                ->orderByDesc('canonical')->orderByDesc('id')->value('gid');
        }

        return self::$toGid[$k];
    }

    public static function local(string $tbl, string $gid): ?string
    {
        $k = $tbl.'|'.$gid;
        if (! array_key_exists($k, self::$toLocal)) {
            self::$toLocal[$k] = DB::table('collab_ids')->where('tbl', $tbl)->where('gid', $gid)->value('local_id');
        }

        return self::$toLocal[$k];
    }

    public static function put(string $tbl, string|int $local, string $gid, bool $canonical = true): void
    {
        if ($canonical) {
            // one current gid per local row; older ones stay as aliases
            DB::table('collab_ids')->where('tbl', $tbl)->where('local_id', (string) $local)->where('gid', '!=', $gid)
                ->update(['canonical' => false]);
        }
        DB::table('collab_ids')->updateOrInsert(['tbl' => $tbl, 'gid' => $gid], ['local_id' => (string) $local, 'canonical' => $canonical]);
        self::$toLocal[$tbl.'|'.$gid] = (string) $local;
        if ($canonical || ! isset(self::$toGid[$tbl.'|'.$local])) {
            self::$toGid[$tbl.'|'.$local] = $gid;
        }
    }

    public static function forgetLocal(string $tbl, string|int $local): void
    {
        DB::table('collab_ids')->where('tbl', $tbl)->where('local_id', (string) $local)->delete();
        self::reset();
    }

    /** The gid of a local row, assigning one if it has none yet. */
    public static function ensureGid(string $tbl, string|int $local): string
    {
        if ($gid = self::gid($tbl, $local)) {
            return $gid;
        }
        if ($tbl === 'users') {
            $gid = (string) Str::uuid();
        } else {
            $def = SyncSchema::def($tbl);
            $row = null;
            if (! empty($def['natural'])) {
                $row = SyncSchema::load($tbl, (string) $local);
            }
            $gid = $row ? SyncSchema::naturalGid($tbl, $row) : null;
            $gid ??= empty($def['int']) && ! SyncSchema::composite($tbl) ? (string) $local : (string) Str::uuid();
            // a different local row may already hold this natural gid (merged duplicate)
            if (self::local($tbl, $gid) !== null && self::local($tbl, $gid) !== (string) $local) {
                $gid = (string) Str::uuid();
            }
        }
        self::put($tbl, $local, $gid);

        return $gid;
    }
}
