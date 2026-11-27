<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Collab\Applier;
use App\Services\Collab\Blobs;
use App\Services\Collab\ChangeCapture;
use App\Services\Collab\Collab;
use App\Services\Collab\Docs;
use App\Services\Collab\Invites;
use App\Services\Collab\OpLog;
use App\Services\Collab\Roster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The storage API of the laptop collaboration, used only by the launcher's
 * node agent over loopback (channel "collab", gateway token; see
 * CollabLocalAuth). The agent moves data between laptops; this side checks,
 * merges and stores it.
 */
class CollabLocalController extends Controller
{
    public function roster(): JsonResponse
    {
        $projects = [];
        foreach (Collab::activeProjects() as $p) {
            $projects[] = [
                'gid' => $p->gid,
                'name' => (string) Project::whereKey($p->project_id)->value('name'),
                'members' => array_map(fn ($m) => [
                    'node' => $m['node'], 'pub' => $m['pub'], 'name' => $m['name'], 'role' => (string) $m['role'],
                    'addr' => $m['addr'], 'dns' => $m['dns'], 'removed' => $m['removed'],
                ], Roster::members($p->gid)),
            ];
        }

        return response()->json([
            'node' => Collab::node(),
            'name' => Collab::displayName(),
            'projects' => $projects,
            'joins' => Invites::pendingJoins(),
            'missing' => Blobs::missing(),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $result = Applier::apply((string) $request->input('project'), (array) $request->input('ops', []));

        return response()->json($result);
    }

    public function ops(Request $request): JsonResponse
    {
        $vv = array_map('intval', array_filter((array) $request->input('vv', []), 'is_numeric'));
        $limit = min(1000, max(1, (int) $request->input('limit', 500)));

        return response()->json(OpLog::since((string) $request->input('project'), $vv, $limit));
    }

    public function vv(Request $request): JsonResponse
    {
        return response()->json(['vv' => (object) OpLog::vv((string) $request->query('project'))]);
    }

    public function blobGet(Request $request, string $sha): Response
    {
        $path = Blobs::locate($sha, (string) $request->query('node'));
        abort_unless($path, 404);
        $size = filesize($path);
        $offset = min(max(0, (int) $request->query('offset', 0)), $size);

        return new StreamedResponse(function () use ($path, $offset) {
            $f = fopen($path, 'rb');
            fseek($f, $offset);
            fpassthru($f);
            fclose($f);
        }, 200, ['Content-Type' => 'application/octet-stream', 'X-Blob-Size' => (string) $size]);
    }

    public function blobPut(Request $request, string $sha): JsonResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'oqblob');
        try {
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($request->getContent(true), $out);
            fclose($out);
            abort_unless(hash_file('sha256', $tmp) === $sha, 422, 'The file does not match its hash.');
            $placed = Blobs::place($sha, $tmp);
        } finally {
            @unlink($tmp);
        }

        return response()->json(['placed' => $placed]);
    }

    public function join(Request $request): Response
    {
        try {
            return response()->json(['welcome' => Invites::join($request->all())]);
        } catch (\DomainException $e) {
            return response($e->getMessage(), 403);
        }
    }

    public function joined(Request $request): JsonResponse
    {
        Invites::joined((int) $request->input('join'), (array) $request->input('welcome', []));

        return response()->json(['ok' => true]);
    }

    public function joinFailed(Request $request): JsonResponse
    {
        Invites::failed((int) $request->input('join'), (string) $request->input('message', ''));

        return response()->json(['ok' => true]);
    }

    public function doc(Request $request): JsonResponse
    {
        $info = Docs::info((string) $request->query('key'));
        abort_unless($info, 404);

        return response()->json($info);
    }

    public function flush(): JsonResponse
    {
        Blobs::scan();

        return response()->json(['batches' => ChangeCapture::flush()]);
    }
}
