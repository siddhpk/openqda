<?php

namespace App\Http\Controllers;

use App\Models\AnalyticEvidence;
use App\Models\Code;
use App\Models\Concept;
use App\Models\Note;
use App\Models\Project;
use App\Models\Proposition;
use App\Models\Source;
use App\Services\Qda\Revisions;
use App\Services\Qda\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Concepts workspace (concepts and propositions share the page).
 */
class ConceptController extends Controller
{
    public function page(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('ConceptsPage', [
            'projectId' => $project->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'concepts' => self::conceptList($project),
            'propositions' => PropositionController::propositionList($project),
            'codes' => Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))
                ->get(['id', 'name', 'color', 'parent_id', 'codebook_id'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'parent_id' => $c->parent_id])
                ->values(),
            'sources' => Source::where('project_id', $project->id)->orderBy('name')->get(['id', 'name']),
            'memos' => self::memos($project, [Concept::TYPE, Proposition::TYPE]),
            // roles named so far, offered as suggestions (never a fixed list)
            'roles' => DB::table('proposition_concept')
                ->join('propositions', 'propositions.id', '=', 'proposition_concept.proposition_id')
                ->where('propositions.project_id', $project->id)->whereNotNull('role')
                ->distinct()->orderBy('role')->pluck('role'),
            'tab' => in_array($request->query('tab'), ['concepts', 'propositions'], true) ? $request->query('tab') : 'concepts',
            'focus' => $request->query('focus'),
        ]);
    }

    public function show(Project $project, Concept $concept): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($concept->project_id === $project->id, 404);

        return response()->json([
            'concept' => self::detail($concept),
            'evidence' => EvidenceController::listFor($project, Concept::TYPE, $concept->id),
            'revisions' => Revisions::list(Concept::TYPE, $concept->id),
            'propositions' => Proposition::where('project_id', $project->id)
                ->whereHas('concepts', fn ($q) => $q->where('concepts.id', $concept->id))
                ->get(['id', 'title'])->values(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $this->validated($request, $project);

        $concept = DB::transaction(function () use ($project, $data) {
            $concept = Concept::create([
                'project_id' => $project->id,
                'name' => $data['name'],
                'definition' => $data['definition'] ?? null,
                'status' => $data['status'] ?? null,
                'properties' => $data['properties'] ?? [],
                'color' => $data['color'] ?? null,
                'creating_user_id' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $concept->codes()->sync($data['codes'] ?? []);

            return $concept;
        });

        return response()->json(['concept' => self::detail($concept), 'concepts' => self::conceptList($project)], 201);
    }

    public function update(Request $request, Project $project, Concept $concept): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($concept->project_id === $project->id, 404);
        $data = $this->validated($request, $project, $concept);

        DB::transaction(function () use ($concept, $data) {
            $before = $concept->snapshot();
            $concept->fill(array_intersect_key($data, array_flip(['name', 'definition', 'status', 'properties', 'color'])));
            $concept->save();
            if (array_key_exists('codes', $data)) {
                $concept->codes()->sync($data['codes']);
            }
            Revisions::keep(Concept::TYPE, $concept, $before, $concept->snapshot(), $data['reason'] ?? null);
        });

        return response()->json(['concept' => self::detail($concept->fresh()), 'revisions' => Revisions::list(Concept::TYPE, $concept->id), 'concepts' => self::conceptList($project)]);
    }

    public function destroy(Project $project, Concept $concept): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($concept->project_id === $project->id, 404);
        $concept->delete();

        return response()->json(['concepts' => self::conceptList($project), 'propositions' => PropositionController::propositionList($project)]);
    }

    private function validated(Request $request, Project $project, ?Concept $concept = null): array
    {
        $sometimes = $concept ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:255', Rule::unique('concepts')->where('project_id', $project->id)->ignore($concept?->id)],
            'definition' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Vocabulary::for($project)->statusKeys())],
            'properties' => ['sometimes', 'array', 'max:50'],
            'properties.*.name' => ['required', 'string', 'max:120'],
            'properties.*.low' => ['nullable', 'string', 'max:120'],
            'properties.*.high' => ['nullable', 'string', 'max:120'],
            'color' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9., ]+\))$/'],
            'codes' => ['sometimes', 'array', 'max:1000'],
            'codes.*' => ['uuid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['codes'])) {
            $known = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->whereIn('id', $data['codes'])->count();
            if ($known !== count(array_unique($data['codes']))) {
                throw ValidationException::withMessages(['codes' => 'All codes must belong to this project.']);
            }
            $data['codes'] = array_values(array_unique($data['codes']));
        }
        if (isset($data['properties'])) {
            $data['properties'] = array_values(array_map(fn ($p) => [
                'name' => trim($p['name']),
                'low' => isset($p['low']) ? trim($p['low']) : null,
                'high' => isset($p['high']) ? trim($p['high']) : null,
            ], $data['properties']));
        }

        return $data;
    }

    public static function detail(Concept $concept): array
    {
        return [
            'id' => $concept->id,
            'name' => $concept->name,
            'definition' => $concept->definition,
            'status' => $concept->status,
            'properties' => $concept->properties ?? [],
            'color' => $concept->color,
            'codes' => $concept->codes()->pluck('codes.id')->values(),
            'version' => $concept->version,
            'updatedAt' => $concept->updated_at?->toIso8601String(),
        ];
    }

    /** concepts with evidence counts per stance and open challenges */
    public static function conceptList(Project $project): array
    {
        $counts = self::evidenceCounts($project, Concept::TYPE);

        return Concept::where('project_id', $project->id)->withCount('codes')->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'color' => $c->color,
                'codesCount' => $c->codes_count,
                'evidence' => $counts[$c->id] ?? self::emptyCounts(),
            ])->values()->all();
    }

    public static function evidenceCounts(Project $project, string $type): array
    {
        $out = [];
        AnalyticEvidence::where('project_id', $project->id)->where('target_type', $type)
            ->selectRaw('target_id, stance, resolution, COUNT(*) as n')
            ->groupBy('target_id', 'stance', 'resolution')->get()
            ->each(function ($row) use (&$out) {
                $out[$row->target_id] ??= self::emptyCounts();
                $out[$row->target_id][$row->stance] += (int) $row->n;
                if ($row->stance === 'challenges' && in_array($row->resolution, [null, 'open', 'unresolved'], true)) {
                    $out[$row->target_id]['openChallenges'] += (int) $row->n;
                }
            });

        return $out;
    }

    public static function emptyCounts(): array
    {
        return array_fill_keys(AnalyticEvidence::STANCES, 0) + ['openChallenges' => 0];
    }

    /** ids of codes and all their descendants */
    public static function withDescendants(Project $project, array $codeIds): array
    {
        if (! $codeIds) {
            return [];
        }
        $children = [];
        Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->get(['id', 'parent_id'])
            ->each(function ($c) use (&$children) {
                if ($c->parent_id) {
                    $children[$c->parent_id][] = $c->id;
                }
            });
        $out = [];
        $stack = $codeIds;
        while ($stack) {
            $id = array_pop($stack);
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = true;
            array_push($stack, ...($children[$id] ?? []));
        }

        return array_keys($out);
    }

    /** memos of the given target kinds that the current user may see */
    public static function memos(Project $project, array $types): array
    {
        $userId = Auth::id();

        return Note::where('project_id', $project->id)->whereIn('type', $types)
            ->where(fn ($q) => $q->where('visibility', 1)->orWhere('creating_user_id', $userId))
            ->orderBy('created_at')
            ->get(['id', 'type', 'target', 'content', 'memo_type', 'visibility', 'creating_user_id', 'created_at'])
            ->values()->all();
    }
}
