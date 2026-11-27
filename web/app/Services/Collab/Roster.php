<?php

namespace App\Services\Collab;

use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Who belongs to a collaborative project, with which role, from when.
 *
 * The roster is part of the operation log (virtual table "_members", row =
 * node id), so it replicates and verifies like everything else. Rules:
 *   - the founder (the node that started the collaboration, known from
 *     every invitation) registers itself as the first Primary;
 *   - only a Primary adds, removes or changes the role of a member;
 *   - a member may update its own name and address, and leave.
 * A node's role at a time decides whether its changes take effect there.
 */
final class Roster
{
    private static array $cache = [];

    private const MEMBER_FIELDS = ['role', 'name', 'pub', 'addr', 'dns', 'removed'];

    public static function forget(?string $projectGid = null): void
    {
        if ($projectGid === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$projectGid]);
        }
    }

    /**
     * @return array{members: array<string, array>, roles: array<string, array<int, array{0:string,1:?string}>>, statuses: array<string,string>}
     */
    public static function state(string $projectGid): array
    {
        if (isset(self::$cache[$projectGid])) {
            return self::$cache[$projectGid];
        }
        $project = Collab::project($projectGid);
        $members = [];
        $roles = [];
        $statuses = [];
        if ($project) {
            $members[$project->founder] = ['node' => $project->founder, 'pub' => $project->founder_pub];
            $ops = DB::table('collab_ops')->where('project_gid', $projectGid)->where('tbl', '_members')
                ->where('status', '!=', 'waiting')->orderBy('hlc')->orderBy('id')->pluck('payload');
            foreach ($ops as $payload) {
                $op = json_decode($payload, true);
                $author = $op['o'];
                $target = (string) $op['r'];
                $fields = array_intersect_key((array) $op['f'], array_flip(self::MEMBER_FIELDS));
                $authorRole = self::roleIn($roles, $author, $op['h']);
                $self = $author === $target;
                $valid = match (true) {
                    $author === $project->founder && $self && empty($roles[$author]) && ($fields['role'] ?? null) === ReviewPolicy::PRIMARY => true,
                    $authorRole === ReviewPolicy::PRIMARY => true,
                    $authorRole !== null && $self && ! array_diff(array_keys($fields), ['name', 'addr', 'dns']) => true,
                    $authorRole !== null && $self && array_keys($fields) === ['removed'] && $fields['removed'] === true => true,
                    default => false,
                };
                $statuses[$op['id']] = $valid ? 'applied' : 'invalid';
                if (! $valid) {
                    continue;
                }
                $members[$target] = array_merge($members[$target] ?? ['node' => $target], $fields);
                if (array_key_exists('role', $fields) || array_key_exists('removed', $fields)) {
                    $m = $members[$target];
                    $roles[$target][] = [$op['h'], ! empty($m['removed']) ? null : ($m['role'] ?? null)];
                }
            }
        }

        return self::$cache[$projectGid] = ['members' => $members, 'roles' => $roles, 'statuses' => $statuses];
    }

    private static function roleIn(array $roles, string $node, string $hlc): ?string
    {
        $role = null;
        foreach ($roles[$node] ?? [] as [$at, $r]) {
            if (strcmp($at, $hlc) > 0) {
                break;
            }
            $role = $r;
        }

        return $role;
    }

    public static function roleAt(string $projectGid, string $node, string $hlc): ?string
    {
        return self::roleIn(self::state($projectGid)['roles'], $node, $hlc);
    }

    /** The current role of a node (null: not a member). */
    public static function role(string $projectGid, string $node): ?string
    {
        $entries = self::state($projectGid)['roles'][$node] ?? [];

        return $entries ? end($entries)[1] : null;
    }

    public static function myRole(string $projectGid): ?string
    {
        return self::role($projectGid, Collab::node());
    }

    /** The key that verifies a node's ops (removed members' old ops stay verifiable). */
    public static function pubOf(string $projectGid, string $node): ?string
    {
        $pub = self::state($projectGid)['members'][$node]['pub'] ?? null;

        return $pub && NodeIdentity::nodeOfPub($pub) === $node ? $pub : null;
    }

    /** @return array<int, array> members with their current role (removed ones included) */
    public static function members(string $projectGid): array
    {
        $out = [];
        foreach (self::state($projectGid)['members'] as $node => $m) {
            if (! isset($m['role']) && empty($m['removed'])) {
                continue; // founder record before its roster op arrived
            }
            $out[] = [
                'node' => $node,
                'name' => $m['name'] ?? $node,
                'pub' => $m['pub'] ?? '',
                'role' => self::role($projectGid, $node) ?? ($m['role'] ?? null),
                'addr' => $m['addr'] ?? '',
                'dns' => $m['dns'] ?? '',
                'removed' => ! empty($m['removed']),
            ];
        }
        usort($out, fn ($a, $b) => [$a['removed'], $a['role'] !== 'primary', $a['name']] <=> [$b['removed'], $b['role'] !== 'primary', $b['name']]);

        return $out;
    }

    /** @return string[] nodes that are Primary now */
    public static function primaries(string $projectGid): array
    {
        return array_values(array_map(fn ($m) => $m['node'], array_filter(self::members($projectGid),
            fn ($m) => ! $m['removed'] && $m['role'] === ReviewPolicy::PRIMARY)));
    }

    /** Write the validity of roster ops to the log (after roster changes). */
    public static function persistStatuses(string $projectGid): void
    {
        foreach (self::state($projectGid)['statuses'] as $id => $status) {
            OpLog::setStatus($projectGid, $id, $status);
        }
    }
}
