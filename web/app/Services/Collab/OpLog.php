<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;

/**
 * The append-only, signed operation log of collaborative projects.
 *
 * An operation (op) is one change made on one node:
 *   v  1                 id  "<origin>:<seq>"      p  project gid
 *   o  origin node       s   sequence per origin   h  hybrid logical time
 *   t  table             r   row gid               k  put | reject | yupd
 *   f  fields            b   {field: [op ids it replaced]}
 *   a  (put by a Primary) ids of reviewed changes it accepts
 *   sig  Ed25519 signature of the op without sig (canonical JSON)
 *
 * The roster is the virtual table "_members" (row = node id), shadow users
 * the virtual table "_users" (row = user gid).
 */
final class OpLog
{
    public static function payload(array $op): string
    {
        unset($op['sig']);

        return Canonical::encode($op);
    }

    public static function verify(array $op, string $pubB64): bool
    {
        return isset($op['sig']) && is_string($op['sig']) && NodeIdentity::verify($pubB64, self::payload($op), $op['sig']);
    }

    /**
     * Create, sign and store a local op. Call inside a transaction.
     */
    public static function append(string $projectGid, string $tbl, string $row, string $kind, array $fields, array $base = [], array $extra = []): array
    {
        $node = Collab::node();
        $seq = (int) DB::table('collab_ops')->where('project_gid', $projectGid)->where('origin', $node)->max('seq') + 1;
        $op = ['v' => 1, 'id' => "{$node}:{$seq}", 'p' => $projectGid, 'o' => $node, 's' => $seq, 'h' => Hlc::next(),
            't' => $tbl, 'r' => $row, 'k' => $kind, 'f' => $fields];
        if ($base) {
            $op['b'] = $base;
        }
        $op += $extra;
        $op['sig'] = Collab::identity()->sign(self::payload($op));
        self::insert($op, 'applied');

        return $op;
    }

    /** Store an op (local or verified remote); false if it was already here. */
    public static function insert(array $op, string $status): bool
    {
        $inserted = DB::table('collab_ops')->insertOrIgnore([
            'op_id' => $op['id'],
            'project_gid' => $op['p'],
            'origin' => $op['o'],
            'seq' => $op['s'],
            'hlc' => $op['h'],
            'tbl' => $op['t'],
            'row_gid' => (string) $op['r'],
            'kind' => $op['k'],
            'payload' => json_encode($op, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'created_at' => now(),
        ]);
        if ($inserted && $status !== 'waiting') {
            self::advance($op['p'], $op['o']);
        }

        return $inserted > 0;
    }

    /** Op ids count per project ("<node>:<seq>"), so they are unique per project only. */
    public static function exists(string $projectGid, string $opId): bool
    {
        return DB::table('collab_ops')->where('project_gid', $projectGid)->where('op_id', $opId)->exists();
    }

    /** Raise the version vector entry while the next sequence is present. */
    public static function advance(string $projectGid, string $origin): void
    {
        $have = (int) DB::table('collab_vv')->where('project_gid', $projectGid)->where('origin', $origin)->value('seq');
        $next = $have + 1;
        while (DB::table('collab_ops')->where('project_gid', $projectGid)->where('origin', $origin)
            ->where('seq', $next)->where('status', '!=', 'waiting')->exists()) {
            $next++;
        }
        if ($next - 1 !== $have) {
            DB::table('collab_vv')->updateOrInsert(['project_gid' => $projectGid, 'origin' => $origin], ['seq' => $next - 1]);
        }
    }

    /** @return array<string, int> origin => highest contiguous sequence */
    public static function vv(string $projectGid): array
    {
        return DB::table('collab_vv')->where('project_gid', $projectGid)->pluck('seq', 'origin')
            ->map(fn ($s) => (int) $s)->all();
    }

    /**
     * Ops a peer lacks, given its version vector (oldest first).
     *
     * @return array{ops: array, more: bool}
     */
    public static function since(string $projectGid, array $vv, int $limit = 500): array
    {
        // every verified op is relayed: whether it takes effect is decided by
        // each node's fold (a status like "invalid" can change with the roster)
        $q = DB::table('collab_ops')->where('project_gid', $projectGid)->where('status', '!=', 'waiting')
            ->where(function ($w) use ($vv) {
                $w->whereNotIn('origin', array_keys($vv) ?: ['']);
                foreach ($vv as $origin => $seq) {
                    $w->orWhere(fn ($x) => $x->where('origin', $origin)->where('seq', '>', (int) $seq));
                }
            })
            ->orderBy('hlc')->orderBy('id')->limit($limit + 1);
        $rows = $q->pluck('payload')->all();
        $more = count($rows) > $limit;

        return ['ops' => array_map(fn ($p) => json_decode($p, true), array_slice($rows, 0, $limit)), 'more' => $more];
    }

    /** @return array<int, array> ops of one row (oldest first), with their status */
    public static function forRow(string $tbl, string $row, array $kinds = ['put', 'reject']): array
    {
        return DB::table('collab_ops')->where('tbl', $tbl)->where('row_gid', $row)->whereIn('kind', $kinds)
            ->where('status', '!=', 'waiting')->orderBy('hlc')->orderBy('id')->get(['payload', 'status'])
            ->map(fn ($r) => json_decode($r->payload, true) + ['_status' => $r->status])->all();
    }

    public static function setStatus(string $projectGid, string $opId, string $status): void
    {
        DB::table('collab_ops')->where('project_gid', $projectGid)->where('op_id', $opId)
            ->where('status', '!=', $status)->update(['status' => $status]);
    }
}
