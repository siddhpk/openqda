<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Project;
use App\Models\Source;
use App\Services\Collab\Collab;
use App\Services\Collab\Docs;
use App\Services\Collab\IdMap;
use App\Services\Collab\LiveTokens;
use App\Services\Collab\Merge\ReviewPolicy;
use App\Services\Collab\Roster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Live collaboration for everyone working in a collaborative project on
 * this laptop (not only the host): the token for the live channel, and the
 * shared documents for co-editing.
 */
class CollabDocController extends Controller
{
    public function live(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $gid = Collab::isCollaborative($project->id) ? Collab::projectGid($project->id) : null;
        if (! $gid) {
            return response()->json(['enabled' => false]);
        }
        $role = Roster::myRole($gid);
        $live = LiveTokens::live($request->user()->id, $gid);

        return response()->json([
            'enabled' => true,
            'url' => '/_live',
            'project' => $gid,
            'node' => Collab::node(),
            'role' => $role,
            'review' => $role === ReviewPolicy::PRIMARY,
        ] + $live);
    }

    public function open(Request $request): JsonResponse
    {
        $key = (string) $request->query('key');
        $r = Docs::resolve($key);
        abort_unless($r, 404);
        $project = Project::find($r['project_id']);
        abort_unless($project, 404);
        Gate::authorize('view', $project);
        if ($r['tbl'] === 'notes') {
            $note = Note::findOrFail($r['local']);
            Gate::authorize('view', $note);
            $mayEdit = Gate::allows('update', $note);
        } else {
            // an unlocked source's text (a locked one has codings that refer to it)
            $source = Source::findOrFail($r['local']);
            $mayEdit = ! $source->isLocked && Gate::allows('update', $project);
        }

        return response()->json(Docs::open($request->user()->id, $r, $mayEdit));
    }

    public function append(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doc' => 'required|string|max:300',
            'gen' => 'required|string|max:80',
            'updates' => 'required|array|min:1|max:200',
            'updates.*' => 'string|max:2000000',
        ]);
        foreach ($data['updates'] as $u) {
            abort_unless(base64_decode($u, true) !== false, 422);
        }
        // the local row behind the document must be one this person may edit
        [$tbl, $gid] = Docs::parse($data['doc']) ?? [null, null];
        abort_unless($tbl, 422);
        $local = IdMap::local($tbl, $gid);
        abort_unless($local, 404);
        $r = Docs::resolve("{$tbl}:{$local}:".explode(':', $data['doc'], 3)[2]);
        abort_unless($r && ($project = Project::find($r['project_id'])), 404);
        Gate::authorize('view', $project);
        if ($tbl === 'notes') {
            Gate::authorize('update', Note::find($local));
        }
        $op = Docs::append($data['doc'], $data['gen'], $data['updates']);
        abort_unless($op, 403, 'You cannot edit this text live.');

        return response()->json(['ok' => true, 'id' => $op['id']]);
    }
}
