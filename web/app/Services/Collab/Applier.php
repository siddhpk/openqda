<?php

namespace App\Services\Collab;

use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Applies operations received from a peer (through the node agent).
 *
 * Each op must be well formed, belong to the project, carry a valid
 * signature of its origin (the key comes from the roster) and not be
 * stamped too far in the future. Ops already here are ignored, so the
 * same op may arrive any number of times and through any peer. The rows
 * they touch are refolded and written in one transaction.
 */
final class Applier
{
    private const ID = '/^n[0-9a-f]{20}$/';

    /**
     * @param  array<int, mixed>  $ops
     * @return array{new: int[], events: array, need_blobs: string[], roster: bool}
     */
    public static function apply(string $projectGid, array $ops): array
    {
        $result = ['new' => [], 'events' => [], 'need_blobs' => [], 'roster' => false];
        if (! Collab::projectId($projectGid)) {
            return $result;
        }
        // roster changes first: they decide which keys are valid
        $indexed = [];
        foreach ($ops as $i => $op) {
            if (self::wellFormed($op, $projectGid)) {
                $indexed[] = [$i, $op];
            }
        }
        usort($indexed, fn ($a, $b) => [$a[1]['t'] !== '_members', $a[1]['h'], $a[1]['id']] <=> [$b[1]['t'] !== '_members', $b[1]['h'], $b[1]['id']]);

        Engine::applying(function () use ($indexed, $projectGid, &$result) {
            $touched = [];
            $rosterNodes = [];
            $sources = false;
            foreach ($indexed as [$i, $op]) {
                if (OpLog::exists($projectGid, $op['id']) || Hlc::tooFarAhead($op['h'])) {
                    continue;
                }
                // unknown origins are skipped, not stored: they are sent again
                // with the next comparison of version vectors, after the roster op
                $pub = Roster::pubOf($projectGid, $op['o']);
                if ($pub === null || ! OpLog::verify($op, $pub)) {
                    continue;
                }
                $status = $op['k'] === 'yupd' ? self::docStatus($projectGid, $op) : 'applied';
                OpLog::insert($op, $status);
                Hlc::observe($op['h']);
                $result['new'][] = $i;
                if ($op['t'] === '_members') {
                    Roster::forget($projectGid);
                    $rosterNodes[(string) $op['r']] = true;
                } elseif ($op['t'] === '_users') {
                    if (Roster::roleAt($projectGid, $op['o'], $op['h']) !== null && isset($op['f']['name'])) {
                        Users::applyName((string) $op['r'], $projectGid, (string) $op['f']['name']);
                    }
                } elseif ($op['k'] === 'yupd') {
                    // editors that were offline get the updates they missed
                    if ($status === 'applied') {
                        $result['events'][] = ['kind' => 'yupd', 'doc' => "{$op['t']}:{$op['r']}:".($op['f']['field'] ?? ''),
                            'gen' => $op['f']['gen'] ?? '', 'u' => array_values((array) ($op['f']['u'] ?? [])), 'project' => Collab::projectId($projectGid)];
                    }
                } else {
                    $touched["{$op['t']}|{$op['r']}"] = [$op['t'], (string) $op['r'], $projectGid];
                    $sources = $sources || $op['t'] === 'sources';
                }
            }

            if ($rosterNodes) {
                Roster::persistStatuses($projectGid);
                $result['roster'] = true;
                // a role change can change which of that member's ops take effect
                $rows = DB::table('collab_ops')->where('project_gid', $projectGid)->whereIn('origin', array_keys($rosterNodes))
                    ->whereIn('kind', ['put', 'reject'])->select('tbl', 'row_gid')->distinct()->get();
                foreach ($rows as $r) {
                    $touched["{$r->tbl}|{$r->row_gid}"] = [$r->tbl, $r->row_gid, $projectGid];
                }
            }
            $touched = array_filter($touched, fn ($t) => SyncSchema::has($t[0]));
            $result['events'] = array_merge($result['events'], Engine::materializeAll(array_values($touched)));
            if ($sources) {
                $result['need_blobs'] = array_column(Blobs::missing($projectGid), 'sha');
            }
        });

        return $result;
    }

    private static function wellFormed(mixed $op, string $projectGid): bool
    {
        if (! is_array($op)) {
            return false;
        }
        foreach (['id', 'p', 'o', 'h', 't', 'r', 'k', 'sig'] as $key) {
            if (! isset($op[$key]) || ! is_string($op[$key])) {
                return false;
            }
        }

        return ($op['v'] ?? null) === 1
            && $op['p'] === $projectGid
            && preg_match(self::ID, $op['o'])
            && is_int($op['s'] ?? null) && $op['s'] > 0
            && $op['id'] === $op['o'].':'.$op['s']
            && preg_match('/^\d{16}-\d{4}-n[0-9a-f]{20}$/', $op['h'])
            && in_array($op['k'], ['put', 'reject', 'yupd'], true)
            && isset($op['f']) && is_array($op['f'])
            && ($op['t'] === '_members' || $op['t'] === '_users' || SyncSchema::has($op['t']))
            && strlen($op['r']) <= 191;
    }

    /** Co-editing updates count only from Primaries and the text's creator. */
    private static function docStatus(string $projectGid, array $op): string
    {
        $shadow = Shadow::get($op['t'], (string) $op['r']);

        return ReviewPolicy::mayCoEdit(Roster::roleAt($projectGid, $op['o'], $op['h']), $op['o'], $shadow?->creator)
            ? 'applied' : 'pending';
    }
}
