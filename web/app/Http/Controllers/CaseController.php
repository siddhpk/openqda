<?php

namespace App\Http\Controllers;

use App\Models\AnalysisCase;
use App\Models\AnalyticEvidence;
use App\Models\CaseAssessment;
use App\Models\Code;
use App\Models\Concept;
use App\Models\Project;
use App\Models\Proposition;
use App\Models\Selection;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cases and the case matrix: codes, concepts or propositions × cases.
 * A cell shows the coded segments or evidence found in the case's sources,
 * and the researcher's explicit judgement where there is one. A cell with
 * neither is "not examined" – absence has to be recorded on purpose.
 */
class CaseController extends Controller
{
    public function page(Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('CasesPage', [
            'projectId' => $project->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'cases' => self::caseList($project),
            'sources' => Source::where('project_id', $project->id)->orderBy('name')->get(['id', 'name']),
            'codes' => Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))
                ->get(['id', 'name', 'color', 'parent_id'])->values(),
            'concepts' => Concept::where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'color']),
            'propositions' => Proposition::where('project_id', $project->id)->orderBy('title')->get(['id', 'title']),
            'memos' => ConceptController::memos($project, ['case']),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $this->validated($request, $project);
        DB::transaction(function () use ($project, $data) {
            $case = AnalysisCase::create([
                'project_id' => $project->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'creating_user_id' => Auth::id(),
            ]);
            $case->sources()->sync($data['source_ids'] ?? []);
        });

        return response()->json(['cases' => self::caseList($project)], 201);
    }

    public function update(Request $request, Project $project, AnalysisCase $case): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($case->project_id === $project->id, 404);
        $data = $this->validated($request, $project, $case);
        DB::transaction(function () use ($case, $data) {
            $case->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();
            if (array_key_exists('source_ids', $data)) {
                $case->sources()->sync($data['source_ids']);
            }
        });

        return response()->json(['cases' => self::caseList($project)]);
    }

    public function destroy(Project $project, AnalysisCase $case): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($case->project_id === $project->id, 404);
        $case->delete();

        return response()->json(['cases' => self::caseList($project)]);
    }

    /**
     * @return array{rows: array, cases: array, cells: array}
     */
    public function matrix(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $data = $request->validate([
            'rows' => ['required', 'in:'.implode(',', CaseAssessment::ITEMS)],
            'subcodes' => ['sometimes', 'boolean'],
        ]);
        $cases = AnalysisCase::where('project_id', $project->id)->with('sources:id')->orderBy('name')->get();
        $caseOfSource = [];
        foreach ($cases as $case) {
            foreach ($case->sources as $source) {
                $caseOfSource[$source->id][] = $case->id;
            }
        }

        [$rows, $counts] = match ($data['rows']) {
            'code' => $this->codeCounts($project, (bool) ($data['subcodes'] ?? false)),
            default => $this->evidenceCounts($project, $data['rows']),
        };

        $cells = [];
        foreach ($counts as $rowId => $bySource) {
            foreach ($bySource as $sourceId => $n) {
                foreach ($caseOfSource[$sourceId] ?? [] as $caseId) {
                    $cell = &$cells[$rowId][$caseId];
                    foreach ($n as $key => $value) {
                        $cell[$key] = ($cell[$key] ?? 0) + $value;
                    }
                    unset($cell);
                }
            }
        }
        CaseAssessment::where('project_id', $project->id)->where('item_type', $data['rows'])->get()
            ->each(function ($a) use (&$cells) {
                $cells[$a->item_id][$a->case_id]['assessment'] = ['state' => $a->state, 'note' => $a->note];
            });

        return response()->json([
            'rows' => $rows,
            'cases' => $cases->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'sources' => $c->sources->count()])->values(),
            'cells' => (object) $cells,
        ]);
    }

    /** segments or evidence behind one cell */
    public function cell(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $data = $request->validate([
            'item_type' => ['required', 'in:'.implode(',', CaseAssessment::ITEMS)],
            'item_id' => ['required', 'uuid'],
            'case_id' => ['required', 'uuid'],
            'subcodes' => ['sometimes', 'boolean'],
        ]);
        $case = AnalysisCase::where('project_id', $project->id)->findOrFail($data['case_id']);
        $sourceIds = $case->sources()->pluck('sources.id');
        $names = Source::whereIn('id', $sourceIds)->pluck('name', 'id');

        if ($data['item_type'] === 'code') {
            $this->assertItem($project, 'code', $data['item_id']);
            $codeIds = ($data['subcodes'] ?? false) ? ConceptController::withDescendants($project, [$data['item_id']]) : [$data['item_id']];
            $codeNames = Code::whereIn('id', $codeIds)->pluck('name', 'id');
            $items = Selection::where('project_id', $project->id)->whereIn('code_id', $codeIds)->whereIn('source_id', $sourceIds)
                ->orderBy('source_id')->orderBy('start_position')->limit(500)->get()
                ->map(fn ($s) => ['sourceId' => $s->source_id, 'sourceName' => $names[$s->source_id] ?? null, 'text' => $s->text,
                    'start' => (int) $s->start_position, 'end' => (int) $s->end_position, 'label' => $codeNames[$s->code_id] ?? null]);
        } else {
            $this->assertItem($project, $data['item_type'], $data['item_id']);
            $items = AnalyticEvidence::where('project_id', $project->id)->where('target_type', $data['item_type'])
                ->where('target_id', $data['item_id'])->whereIn('source_id', $sourceIds)
                ->orderBy('source_id')->orderBy('start_position')->limit(500)->get()
                ->map(fn ($e) => ['sourceId' => $e->source_id, 'sourceName' => $names[$e->source_id] ?? null, 'text' => $e->text,
                    'start' => $e->start_position, 'end' => $e->end_position, 'label' => $e->stance, 'note' => $e->note]);
        }
        $assessment = CaseAssessment::where('case_id', $case->id)->where('item_type', $data['item_type'])->where('item_id', $data['item_id'])->first();

        return response()->json([
            'items' => $items->values(),
            'assessment' => $assessment ? ['state' => $assessment->state, 'note' => $assessment->note] : null,
        ]);
    }

    /** records (or clears, with state null) the researcher's judgement */
    public function assess(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'item_type' => ['required', 'in:'.implode(',', CaseAssessment::ITEMS)],
            'item_id' => ['required', 'uuid'],
            'case_id' => ['required', 'uuid'],
            'state' => ['nullable', 'in:'.implode(',', CaseAssessment::STATES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        AnalysisCase::where('project_id', $project->id)->findOrFail($data['case_id']);
        $this->assertItem($project, $data['item_type'], $data['item_id']);
        $key = ['case_id' => $data['case_id'], 'item_type' => $data['item_type'], 'item_id' => $data['item_id']];
        if (($data['state'] ?? null) === null) {
            CaseAssessment::where($key)->first()?->delete();

            return response()->json(['assessment' => null]);
        }
        $assessment = CaseAssessment::firstOrNew($key);
        $assessment->fill(['project_id' => $project->id, 'state' => $data['state'], 'note' => $data['note'] ?? null, 'updated_by' => Auth::id()])->save();

        return response()->json(['assessment' => ['state' => $assessment->state, 'note' => $assessment->note]]);
    }

    private function validated(Request $request, Project $project, ?AnalysisCase $case = null): array
    {
        $data = $request->validate([
            'name' => [$case ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('cases')->where('project_id', $project->id)->ignore($case?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'source_ids' => ['sometimes', 'array', 'max:5000'],
            'source_ids.*' => ['uuid', 'distinct'],
        ]);
        if (! empty($data['source_ids']) && Source::where('project_id', $project->id)->whereIn('id', $data['source_ids'])->count() !== count($data['source_ids'])) {
            throw ValidationException::withMessages(['source_ids' => 'All sources must belong to this project.']);
        }

        return $data;
    }

    private function assertItem(Project $project, string $type, string $id): void
    {
        $exists = match ($type) {
            'code' => Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->whereKey($id)->exists(),
            Concept::TYPE => Concept::where('project_id', $project->id)->whereKey($id)->exists(),
            Proposition::TYPE => Proposition::where('project_id', $project->id)->whereKey($id)->exists(),
        };
        if (! $exists) {
            throw ValidationException::withMessages(['item_id' => 'Unknown '.$type.' in this project.']);
        }
    }

    /** @return array{0: array, 1: array<string, array<string, array<string, int>>>} */
    private function codeCounts(Project $project, bool $subcodes): array
    {
        $codes = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->get(['id', 'name', 'color', 'parent_id']);
        $direct = [];
        Selection::where('project_id', $project->id)->whereIn('code_id', $codes->pluck('id'))
            ->selectRaw('code_id, source_id, COUNT(*) as n')->groupBy('code_id', 'source_id')->get()
            ->each(function ($r) use (&$direct) {
                $direct[$r->code_id][$r->source_id] = (int) $r->n;
            });
        $counts = [];
        foreach ($codes as $code) {
            $ids = $subcodes ? ConceptController::withDescendants($project, [$code->id]) : [$code->id];
            foreach ($ids as $id) {
                foreach ($direct[$id] ?? [] as $sourceId => $n) {
                    $counts[$code->id][$sourceId]['count'] = ($counts[$code->id][$sourceId]['count'] ?? 0) + $n;
                }
            }
        }
        $rows = $codes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'parentId' => $c->parent_id])->values()->all();

        return [$rows, $counts];
    }

    private function evidenceCounts(Project $project, string $type): array
    {
        $rows = $type === Concept::TYPE
            ? Concept::where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'color'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color])
            : Proposition::where('project_id', $project->id)->orderBy('title')->get(['id', 'title'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->title]);
        $counts = [];
        AnalyticEvidence::where('project_id', $project->id)->where('target_type', $type)
            ->selectRaw('target_id, source_id, stance, COUNT(*) as n')->groupBy('target_id', 'source_id', 'stance')->get()
            ->each(function ($r) use (&$counts) {
                $cell = &$counts[$r->target_id][$r->source_id];
                $cell['count'] = ($cell['count'] ?? 0) + (int) $r->n;
                $cell[$r->stance] = ($cell[$r->stance] ?? 0) + (int) $r->n;
                unset($cell);
            });

        return [$rows->values()->all(), $counts];
    }

    public static function caseList(Project $project): array
    {
        return AnalysisCase::where('project_id', $project->id)->with('sources:id')->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'sourceIds' => $c->sources->pluck('id')->values()])
            ->values()->all();
    }
}
