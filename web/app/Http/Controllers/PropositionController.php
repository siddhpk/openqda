<?php

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Project;
use App\Models\Proposition;
use App\Services\Qda\Revisions;
use App\Services\Qda\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Propositions: statements about how concepts relate, with conditions,
 * mechanism, scope, evidence and revisions.
 */
class PropositionController extends Controller
{
    public function show(Project $project, Proposition $proposition): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($proposition->project_id === $project->id, 404);

        return response()->json([
            'proposition' => self::detail($proposition),
            'evidence' => EvidenceController::listFor($project, Proposition::TYPE, $proposition->id),
            'revisions' => Revisions::list(Proposition::TYPE, $proposition->id),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $this->validated($request, $project);

        $proposition = DB::transaction(function () use ($project, $data) {
            $proposition = Proposition::create([
                'project_id' => $project->id,
                'title' => $data['title'],
                'statement' => $data['statement'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'mechanism' => $data['mechanism'] ?? null,
                'scope' => $data['scope'] ?? null,
                'status' => $data['status'] ?? null,
                'creating_user_id' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $proposition->concepts()->sync($this->pivot($data['concepts'] ?? []));

            return $proposition;
        });

        return response()->json(['proposition' => self::detail($proposition), 'propositions' => self::propositionList($project)], 201);
    }

    public function update(Request $request, Project $project, Proposition $proposition): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($proposition->project_id === $project->id, 404);
        $data = $this->validated($request, $project, true);

        DB::transaction(function () use ($proposition, $data) {
            $before = $proposition->snapshot();
            $proposition->fill(array_intersect_key($data, array_flip(['title', 'statement', 'conditions', 'mechanism', 'scope', 'status'])));
            $proposition->save();
            if (array_key_exists('concepts', $data)) {
                $proposition->concepts()->sync($this->pivot($data['concepts']));
            }
            Revisions::keep(Proposition::TYPE, $proposition, $before, $proposition->snapshot(), $data['reason'] ?? null);
        });

        return response()->json([
            'proposition' => self::detail($proposition->fresh()),
            'revisions' => Revisions::list(Proposition::TYPE, $proposition->id),
            'propositions' => self::propositionList($project),
        ]);
    }

    public function destroy(Project $project, Proposition $proposition): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($proposition->project_id === $project->id, 404);
        $proposition->delete();

        return response()->json(['propositions' => self::propositionList($project)]);
    }

    private function validated(Request $request, Project $project, bool $update = false): array
    {
        $data = $request->validate([
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'statement' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'conditions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'mechanism' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Vocabulary::for($project)->statusKeys())],
            'concepts' => ['sometimes', 'array', 'max:100'],
            'concepts.*.id' => ['required', 'uuid', 'distinct'],
            'concepts.*.role' => ['nullable', 'string', 'max:80'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['concepts'])) {
            $ids = array_column($data['concepts'], 'id');
            if (Concept::where('project_id', $project->id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['concepts' => 'All concepts must belong to this project.']);
            }
        }

        return $data;
    }

    private function pivot(array $concepts): array
    {
        $out = [];
        foreach (array_values($concepts) as $i => $c) {
            $role = isset($c['role']) ? trim($c['role']) : null;
            $out[$c['id']] = ['role' => $role === '' ? null : $role, 'position' => $i];
        }

        return $out;
    }

    public static function detail(Proposition $proposition): array
    {
        return [
            'id' => $proposition->id,
            'title' => $proposition->title,
            'statement' => $proposition->statement,
            'conditions' => $proposition->conditions,
            'mechanism' => $proposition->mechanism,
            'scope' => $proposition->scope,
            'status' => $proposition->status,
            'concepts' => $proposition->concepts()->get()->map(fn ($c) => ['id' => $c->id, 'role' => $c->pivot->role])->values(),
            'version' => $proposition->version,
            'updatedAt' => $proposition->updated_at?->toIso8601String(),
        ];
    }

    public static function propositionList(Project $project): array
    {
        $counts = ConceptController::evidenceCounts($project, Proposition::TYPE);

        return Proposition::where('project_id', $project->id)->with('concepts:id')->orderBy('title')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'concepts' => $p->concepts->pluck('id')->values(),
                'evidence' => $counts[$p->id] ?? ConceptController::emptyCounts(),
            ])->values()->all();
    }
}
