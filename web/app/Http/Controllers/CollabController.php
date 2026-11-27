<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Collab\Collab;
use App\Services\Collab\ControlClient;
use App\Services\Collab\Invites;
use App\Services\Collab\Merge\ReviewPolicy;
use App\Services\Collab\OpLog;
use App\Services\Collab\Reviews;
use App\Services\Collab\Roster;
use App\Services\Collab\Seeder;
use App\Services\Collab\TailscaleApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Collaboration page of the laptop edition (person at this computer
 * only, like the Sharing page): connect to the team's Tailscale network,
 * make projects collaborative, invite people, manage roles, review changes.
 */
class CollabController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('CollaborationPage', ['initial' => $this->payload($request)]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $enabled = Collab::enabled();
        $control = $enabled ? ControlClient::status() : null;
        $peers = collect($control['cluster']['peers'] ?? [])->keyBy('node');
        $presence = collect($control['cluster']['presence'] ?? []);
        $projects = [];
        $collaborative = [];
        if ($enabled) {
            foreach (Collab::activeProjects() as $c) {
                $project = Project::find($c->project_id);
                if (! $project || ! Gate::allows('view', $project)) {
                    continue;
                }
                $collaborative[] = $project->id;
                // the founder is always on its project's team (repairs a lost roster entry)
                $role = Roster::myRole($c->gid) ?? Seeder::repairFounder($c->gid);
                $projects[] = [
                    'id' => $project->id,
                    'gid' => $c->gid,
                    'name' => $project->name,
                    'role' => $role,
                    'founder' => $c->founder === Collab::node(),
                    'members' => array_map(fn ($m) => [
                        'node' => $m['node'],
                        'name' => $m['name'],
                        'role' => $m['role'],
                        'removed' => $m['removed'],
                        'me' => $m['node'] === Collab::node(),
                        'online' => $m['node'] === Collab::node() || (bool) ($peers[$m['node']]['online'] ?? false),
                        'direct' => (bool) ($peers[$m['node']]['direct'] ?? false),
                        'last_seen' => $peers[$m['node']]['last_seen'] ?? null,
                        'people' => $presence->where('node', $m['node'])->where('project', $c->gid)->pluck('name')->unique()->values(),
                    ], Roster::members($c->gid)),
                    'invites' => DB::table('collab_invites')->where('project_gid', $c->gid)->whereNull('revoked_at')
                        ->where('expires_at', '>', now())->whereNull('used_at')->orderByDesc('id')
                        ->get(['id', 'role', 'key_mode', 'expires_at', 'created_at']),
                    'reviews' => $role === ReviewPolicy::PRIMARY ? Reviews::count($c->gid) : 0,
                    'my_pending' => Reviews::mine($c->gid),
                    'ops' => DB::table('collab_ops')->where('project_gid', $c->gid)->count(),
                ];
            }
        }
        $userId = $request->user()->id;
        $available = $enabled ? Project::where('creating_user_id', $userId)->whereNotIn('id', $collaborative)
            ->orderBy('name')->get(['id', 'name']) : [];

        return [
            'enabled' => $enabled,
            'node' => $enabled ? Collab::node() : null,
            'name' => $enabled ? Collab::displayName() : null,
            'agent' => $control !== null,
            'tailscale' => $control['tailscale'] ?? ['state' => 'Unavailable'],
            'oauth' => $enabled && TailscaleApi::configured(),
            'tag' => config('collab.tag'),
            'policy' => $this->policySnippet(),
            'projects' => $projects,
            'available' => $available,
            'joins' => $enabled ? Invites::joinsOf($userId) : [],
        ];
    }

    /** The access rules suggested for the team network (Tailscale policy file). */
    private function policySnippet(): string
    {
        $tag = config('collab.tag');

        return json_encode([
            'tagOwners' => [$tag => ['autogroup:admin']],
            'grants' => [['src' => [$tag], 'dst' => [$tag], 'ip' => ['tcp:80']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function collabProject(Project $project): string
    {
        Gate::authorize('view', $project);
        $gid = Collab::projectGid($project->id);
        abort_unless($gid, 404, 'This project is not collaborative.');

        return $gid;
    }

    private function requirePrimary(string $gid): void
    {
        if (Roster::myRole($gid) !== ReviewPolicy::PRIMARY) {
            throw ValidationException::withMessages(['role' => 'Only a Primary can do this.']);
        }
    }

    public function connect(Request $request): JsonResponse
    {
        $r = ControlClient::connect();
        if (! $r['ok']) {
            return response()->json(['message' => $r['error'] ?? 'Tailscale could not start.'] + $this->payload($request), 422);
        }

        return response()->json(['authUrl' => $r['auth_url'] ?? null] + $this->payload($request));
    }

    public function disconnect(Request $request): JsonResponse
    {
        ControlClient::logout();

        return response()->json($this->payload($request));
    }

    public function tag(Request $request): JsonResponse
    {
        $r = ControlClient::tags([config('collab.tag')]);
        if (! $r['ok']) {
            return response()->json(['message' => 'Tailscale refused the tag. Is the policy saved in the admin console? ('.($r['error'] ?? '').')']
                + $this->payload($request), 422);
        }

        return response()->json($this->payload($request));
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:80',
            'oauth_client_id' => 'nullable|string|max:255',
            'oauth_client_secret' => 'nullable|string|max:255',
            'clear_oauth' => 'nullable|boolean',
        ]);
        if (array_key_exists('name', $data)) {
            Collab::putSetting('name', trim((string) $data['name']) ?: null);
        }
        if (! empty($data['clear_oauth'])) {
            Collab::putSecret('oauth_client_id', null);
            Collab::putSecret('oauth_client_secret', null);
        } elseif (! empty($data['oauth_client_id']) && ! empty($data['oauth_client_secret'])) {
            Collab::putSecret('oauth_client_id', trim($data['oauth_client_id']));
            Collab::putSecret('oauth_client_secret', trim($data['oauth_client_secret']));
        }
        ControlClient::rosterChanged();

        return response()->json($this->payload($request));
    }

    public function enable(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless(Collab::enabled(), 404);
        Seeder::enable($project);

        return response()->json($this->payload($request));
    }

    public function invite(Request $request, Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);
        $this->requirePrimary($gid);
        $data = $request->validate([
            'role' => ['required', Rule::in([ReviewPolicy::PRIMARY, ReviewPolicy::ASSISTANT])],
            'key_mode' => ['required', Rule::in(['share', 'auto', 'paste', 'none'])],
            'key' => 'nullable|string|max:255',
            'share_link' => 'nullable|string|max:500',
        ]);
        $created = Invites::create($project, $request->user()->id, $data['role'], $data['key_mode'], $data['key'] ?? null, $data['share_link'] ?? null);

        return response()->json(['text' => $created['text']] + $this->payload($request));
    }

    public function revoke(Request $request, Project $project, int $invite): JsonResponse
    {
        $gid = $this->collabProject($project);
        $this->requirePrimary($gid);
        DB::table('collab_invites')->where('id', $invite)->where('project_gid', $gid)->update(['revoked_at' => now()]);

        return response()->json($this->payload($request));
    }

    public function member(Request $request, Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);
        $this->requirePrimary($gid);
        $data = $request->validate([
            'node' => 'required|string|regex:/^n[0-9a-f]{20}$/',
            'action' => ['required', Rule::in(['primary', 'assistant', 'remove'])],
        ]);
        $member = collect(Roster::members($gid))->firstWhere('node', $data['node']);
        abort_unless($member && ! $member['removed'], 404);
        $primaries = Roster::primaries($gid);
        if ($member['role'] === ReviewPolicy::PRIMARY && $data['action'] !== 'primary' && count($primaries) <= 1) {
            throw ValidationException::withMessages(['role' => 'A project needs at least one Primary. Make someone else Primary first.']);
        }
        $fields = $data['action'] === 'remove' ? ['removed' => true] : ['role' => $data['action']];
        $op = DB::transaction(fn () => OpLog::append($gid, '_members', $data['node'], 'put', $fields));
        Roster::forget($gid);
        ControlClient::publish([['project' => $gid, 'ops' => [$op], 'events' => []]]);
        ControlClient::rosterChanged();

        return response()->json($this->payload($request));
    }

    public function leave(Request $request, Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);
        if (Roster::myRole($gid) === ReviewPolicy::PRIMARY && count(Roster::primaries($gid)) <= 1
            && count(array_filter(Roster::members($gid), fn ($m) => ! $m['removed'])) > 1) {
            throw ValidationException::withMessages(['role' => 'You are the only Primary. Make someone else Primary before you leave.']);
        }
        Seeder::leave($project);

        return response()->json($this->payload($request));
    }

    public function join(Request $request): JsonResponse
    {
        abort_unless(Collab::enabled(), 404);
        $data = $request->validate(['invite' => 'required|string|max:8000', 'move' => 'nullable|boolean']);
        $result = Invites::accept($request->user()->id, $data['invite'], (bool) ($data['move'] ?? false));

        return response()->json(['join' => $result] + $this->payload($request));
    }

    public function dismissJoin(Request $request, int $join): JsonResponse
    {
        DB::table('collab_joins')->where('id', $join)->where('user_id', $request->user()->id)->where('status', '!=', 'joined')->delete();
        ControlClient::rosterChanged();

        return response()->json($this->payload($request));
    }

    public function sync(Request $request): JsonResponse
    {
        ControlClient::sync();

        return response()->json($this->payload($request));
    }

    public function reviews(Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);

        return response()->json(Reviews::list($gid) + ['role' => Roster::myRole($gid)]);
    }

    public function decide(Request $request, Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);
        $data = $request->validate([
            'ops' => 'required|array|min:1|max:500',
            'ops.*' => 'string|max:64',
            'decision' => ['required', Rule::in(['accept', 'reject'])],
        ]);
        ControlClient::publish(Reviews::decide($gid, $data['ops'], $data['decision']));

        return response()->json(Reviews::list($gid) + ['role' => Roster::myRole($gid)]);
    }

    public function orphan(Request $request, Project $project): JsonResponse
    {
        $gid = $this->collabProject($project);
        $data = $request->validate([
            'tbl' => 'required|string|max:64',
            'gid' => 'required|string|max:191',
            'action' => ['required', Rule::in(['restore', 'discard'])],
        ]);
        ControlClient::publish(Reviews::orphan($gid, $data['tbl'], $data['gid'], $data['action']));

        return response()->json(Reviews::list($gid) + ['role' => Roster::myRole($gid)]);
    }
}
