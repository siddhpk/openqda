<?php

namespace App\Http\Controllers;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\CodeRelation;
use App\Models\Note;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FreeMind-style mind map of the project's codes, their hierarchy,
 * relationships and memos.
 */
class MapController extends Controller
{
    public const MAX_IMPORT_NODES = 2000;

    public const MAX_IMPORT_DEPTH = 12;

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        $codebooks = $project->codebooks()->get();
        $codes = Code::whereIn('codebook_id', $codebooks->pluck('id'))
            ->withCount('selections')
            ->get();

        $userId = Auth::id();
        $notes = Note::where('project_id', $project->id)
            ->whereIn('type', ['project', 'code', 'relation'])
            ->where(function ($query) use ($userId) {
                $query->where('visibility', 1)
                    ->orWhere(function ($query) use ($userId) {
                        $query->where('visibility', 0)->where('creating_user_id', $userId);
                    });
            })
            ->get();

        return Inertia::render('MapPage', [
            'projectId' => $project->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'codebooks' => $codebooks,
            'allCodes' => $this->tree($codes),
            'relations' => CodeRelation::where('project_id', $project->id)->get()->map->toClientArray()->values(),
            'notes' => $notes,
        ]);
    }

    /**
     * Import a FreeMind/Freeplane map (parsed in the browser) as a new codebook.
     */
    public function import(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nodes' => ['required', 'array', 'min:1'],
        ]);

        $count = 0;
        $this->validateNodes($data['nodes'], 1, $count);

        $codebook = DB::transaction(function () use ($project, $data) {
            $codebook = new Codebook;
            $codebook->name = $data['name'];
            $codebook->description = 'Imported from a FreeMind mind map';
            $codebook->project_id = $project->id;
            $codebook->creating_user_id = Auth::id();
            $codebook->properties = ['sharedWithPublic' => false, 'sharedWithTeams' => false];
            $codebook->save();

            $this->createCodes($project, $codebook, $data['nodes'], null);

            return $codebook;
        });

        return response()->json(['message' => "Imported {$count} codes", 'codebook' => $codebook->id, 'count' => $count], 201);
    }

    protected function validateNodes(array $nodes, int $depth, int &$count): void
    {
        if ($depth > self::MAX_IMPORT_DEPTH) {
            throw ValidationException::withMessages(['nodes' => 'The map is nested too deeply (max '.self::MAX_IMPORT_DEPTH.' levels).']);
        }
        foreach ($nodes as $node) {
            $count++;
            if ($count > self::MAX_IMPORT_NODES) {
                throw ValidationException::withMessages(['nodes' => 'The map has too many nodes (max '.self::MAX_IMPORT_NODES.').']);
            }
            $name = $node['name'] ?? null;
            if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['nodes' => 'Every node needs a name of at most 255 characters.']);
            }
            if (isset($node['note']) && (! is_string($node['note']) || mb_strlen($node['note']) > 10000)) {
                throw ValidationException::withMessages(['nodes' => 'Node notes must be text of at most 10000 characters.']);
            }
            if (! empty($node['children'])) {
                if (! is_array($node['children'])) {
                    throw ValidationException::withMessages(['nodes' => 'Invalid node children.']);
                }
                $this->validateNodes($node['children'], $depth + 1, $count);
            }
        }
    }

    protected function createCodes(Project $project, Codebook $codebook, array $nodes, ?string $parentId): void
    {
        foreach ($nodes as $node) {
            $color = isset($node['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $node['color'])
                ? $node['color']
                : sprintf('#%06x', random_int(0x404040, 0xDFDFDF));

            $code = new Code;
            $code->name = trim($node['name']);
            $code->color = $color;
            $code->codebook_id = $codebook->id;
            $code->parent_id = $parentId;
            $code->save();

            if (! empty($node['note'])) {
                Note::create([
                    'content' => $node['note'],
                    'project_id' => $project->id,
                    'target' => $code->id,
                    'type' => Note::SCOPE_CODE,
                    'memo_type' => 'code_note',
                    'visibility' => 0,
                    'creating_user_id' => Auth::id(),
                ]);
            }

            if (! empty($node['children'])) {
                $this->createCodes($project, $codebook, $node['children'], $code->id);
            }
        }
    }

    /**
     * Build the nested code structure from a flat list (one query instead of N).
     */
    protected function tree($codes): array
    {
        $byParent = $codes->groupBy(fn ($c) => $c->parent_id ?? '_root');
        $build = function ($parentKey) use (&$build, $byParent) {
            return $byParent->get($parentKey, collect())->map(fn (Code $code) => [
                'id' => $code->id,
                'name' => $code->name,
                'color' => $code->color,
                'codebook' => $code->codebook_id,
                'description' => $code->description ?? '',
                'kind' => $code->kind ?? 'code',
                'meta' => $code->meta ?: new \stdClass,
                'selectionsCount' => (int) $code->selections_count,
                'children' => $build($code->id),
            ])->values()->all();
        };

        return $build('_root');
    }
}
