<?php

namespace App\Services\Collab;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * People in collaborative projects. Every user who changes something gets
 * a global id; their display name is published in the project (virtual
 * table "_users"). Collaborators from other laptops become shadow accounts
 * here: they cannot sign in (random password, no real address) and exist so
 * that codings keep their author ("Coded by") and team lists show names.
 */
final class Users
{
    public const SHADOW_DOMAIN = '@openqda.invalid';

    public static function isShadow(int $userId): bool
    {
        return str_ends_with((string) DB::table('users')->where('id', $userId)->value('email'), self::SHADOW_DOMAIN);
    }

    /** Publish a local user's name in a project once (and when it changes). */
    public static function publish(string $projectGid, string $userGid, int $localUserId): ?array
    {
        if (self::isShadow($localUserId)) {
            return null;
        }
        $name = (string) DB::table('users')->where('id', $localUserId)->value('name');
        $last = DB::table('collab_ops')->where('project_gid', $projectGid)->where('tbl', '_users')
            ->where('row_gid', $userGid)->where('origin', Collab::node())->orderByDesc('seq')->value('payload');
        if ($last && (json_decode($last, true)['f']['name'] ?? null) === $name) {
            return null;
        }

        return OpLog::append($projectGid, '_users', $userGid, 'put', ['name' => $name]);
    }

    /** The latest published name of a person (any project). */
    public static function nameOf(string $userGid): ?string
    {
        $payload = DB::table('collab_ops')->where('tbl', '_users')->where('row_gid', $userGid)
            ->where('status', '!=', 'waiting')->orderByDesc('hlc')->value('payload');

        return $payload ? (json_decode($payload, true)['f']['name'] ?? null) : null;
    }

    /** The local account for a person, creating a shadow account if needed. */
    public static function localFor(string $userGid, string $projectGid): int
    {
        if ($local = IdMap::local('users', $userGid)) {
            return (int) $local;
        }
        $id = DB::table('users')->insertGetId([
            'name' => self::nameOf($userGid) ?? 'Collaborator',
            'email' => 'peer-'.substr(str_replace('-', '', $userGid), 0, 20).self::SHADOW_DOMAIN,
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        IdMap::put('users', $id, $userGid);
        self::addToTeam($id, $projectGid);

        return $id;
    }

    /** A collaborator's published name changed. */
    public static function applyName(string $userGid, string $projectGid, string $name): void
    {
        $local = IdMap::local('users', $userGid);
        if ($local === null) {
            self::localFor($userGid, $projectGid);

            return;
        }
        if (self::isShadow((int) $local)) {
            DB::table('users')->where('id', $local)->update(['name' => $name, 'updated_at' => now()]);
            self::addToTeam((int) $local, $projectGid);
        }
    }

    /** Put a shadow account into the project's team (creating the team if needed). */
    public static function addToTeam(int $userId, string $projectGid): void
    {
        $projectId = Collab::projectId($projectGid);
        $project = $projectId ? Project::find($projectId) : null;
        if (! $project) {
            return;
        }
        $teamId = $project->team_id ?: self::ensureTeam($project);
        $exists = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $userId)->exists();
        if (! $exists && (int) DB::table('teams')->where('id', $teamId)->value('user_id') !== $userId) {
            DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $userId, 'role' => 'admin',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function ensureTeam(Project $project): int
    {
        if ($project->team_id) {
            return (int) $project->team_id;
        }
        $team = Team::forceCreate(['user_id' => $project->creating_user_id, 'name' => $project->name, 'personal_team' => false]);
        DB::table('projects')->where('id', $project->id)->update(['team_id' => $team->id]);
        $project->team_id = $team->id;

        return (int) $team->id;
    }
}
