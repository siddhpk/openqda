<?php

namespace App\Services\Collab;

use App\Models\Project;
use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts or ends the collaboration of a local project.
 */
final class Seeder
{
    /** Make a project collaborative: this node becomes its founder and first Primary. */
    public static function enable(Project $project): string
    {
        if ($gid = Collab::projectGid($project->id)) {
            return $gid;
        }
        $status = ControlClient::status()['tailscale'] ?? [];
        $identity = Collab::identity();
        $gid = (string) Str::uuid();
        DB::transaction(function () use ($project, $gid, $identity, $status) {
            Hlc::ensureSettingsRow();
            DB::table('collab_projects')->where('project_id', $project->id)->delete();
            DB::table('collab_projects')->insert(['project_id' => $project->id, 'gid' => $gid, 'founder' => $identity->node,
                'founder_pub' => $identity->pub, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            Collab::reset();
            // a project collaborated on before starts afresh under its new id
            IdMap::forgetLocal('projects', $project->id);
            IdMap::put('projects', $project->id, $gid);
            OpLog::append($gid, '_members', $identity->node, 'put', ['role' => ReviewPolicy::PRIMARY, 'name' => Collab::displayName(),
                'pub' => $identity->pub, 'addr' => $status['ip'] ?? '', 'dns' => $status['dns'] ?? '']);
            Roster::forget($gid);
            foreach (array_keys(SyncSchema::tables()) as $tbl) {
                foreach (SyncSchema::rowsOfProject($tbl, $project->id) as $pk) {
                    DB::table('collab_dirty')->insertOrIgnore(['tbl' => $tbl, 'pk' => $pk]);
                }
            }
        });
        ControlClient::publish(ChangeCapture::flush());
        ControlClient::rosterChanged();

        return $gid;
    }

    /**
     * The founder registers itself as Primary when its roster entry is
     * missing (never when a Primary removed or demoted it).
     */
    public static function repairFounder(string $gid): ?string
    {
        $collab = Collab::project($gid);
        $node = Collab::node();
        if (! $collab || $collab->founder !== $node || ! empty(Roster::state($gid)['roles'][$node])) {
            return null;
        }
        $identity = Collab::identity();
        $status = ControlClient::status()['tailscale'] ?? [];
        $op = DB::transaction(fn () => OpLog::append($gid, '_members', $node, 'put', ['role' => ReviewPolicy::PRIMARY,
            'name' => Collab::displayName(), 'pub' => $identity->pub, 'addr' => $status['ip'] ?? '', 'dns' => $status['dns'] ?? '']));
        Roster::forget($gid);
        ControlClient::publish([['project' => $gid, 'ops' => [$op], 'events' => []]]);

        return Roster::myRole($gid);
    }

    /** Leave a collaboration: the project stays here as an ordinary project. */
    public static function leave(Project $project): void
    {
        $gid = Collab::projectGid($project->id);
        if (! $gid) {
            return;
        }
        DB::transaction(function () use ($gid) {
            $node = Collab::node();
            if (Roster::role($gid, $node) !== null) {
                OpLog::append($gid, '_members', $node, 'put', ['removed' => true]);
            }
            DB::table('collab_projects')->where('gid', $gid)->update(['status' => 'left', 'updated_at' => now()]);
            DB::table('collab_rows')->where('project_gid', $gid)->delete();
            DB::table('collab_invites')->where('project_gid', $gid)->whereNull('used_at')->update(['revoked_at' => now()]);
        });
        $last = DB::table('collab_ops')->where('project_gid', $gid)->where('tbl', '_members')->where('origin', Collab::node())
            ->orderByDesc('seq')->value('payload');
        if ($last) {
            ControlClient::publish([['project' => $gid, 'ops' => [json_decode($last, true)], 'events' => []]]);
        }
        Collab::reset();
        ControlClient::rosterChanged();
    }
}
