<?php

namespace App\Http\Controllers;

use App\Models\AnalyticEvidence;
use App\Models\Code;
use App\Models\Concept;
use App\Models\Project;
use App\Models\Proposition;
use App\Models\Selection;
use App\Models\Source;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Evidence: coded segments linked to a concept or proposition as
 * supporting, challenging, illustrating or qualifying it. Challenging
 * evidence carries a resolution (open, accounted for, led to a revision,
 * unresolved), so disconfirming material stays visible.
 */
class EvidenceController extends Controller
{
    /**
     * Coded segments that could serve as evidence: by default the segments
     * of the codes linked to a concept (and their subcodes) – for a
     * proposition, of the codes linked to its concepts. Filters: any code,
     * source or text.
     */
    public function candidates(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $data = $request->validate([
            'target_type' => ['required', 'in:'.implode(',', array_keys(AnalyticEvidence::TARGETS))],
            'target_id' => ['required', 'uuid'],
            'code_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            // up to 1000 at once, e.g. to rank them by similarity in the browser
            'all' => ['sometimes', 'boolean'],
        ]);
        $target = $this->target($project, $data['target_type'], $data['target_id']);

        if (! empty($data['code_id'])) {
            $codeIds = ConceptController::withDescendants($project, [$data['code_id']]);
        } else {
            $linked = $target instanceof Concept
                ? $target->codes()->pluck('codes.id')->all()
                : Code::whereHas('concepts', fn ($q) => $q->whereIn('concepts.id', $target->concepts()->pluck('concepts.id')))->pluck('id')->all();
            $codeIds = ConceptController::withDescendants($project, $linked);
        }
        $projectCodes = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->whereIn('id', $codeIds)->pluck('name', 'id');

        $used = AnalyticEvidence::where('target_type', $data['target_type'])->where('target_id', $target->id)->whereNotNull('selection_id')->pluck('selection_id');
        $query = Selection::where('project_id', $project->id)
            ->whereIn('code_id', $projectCodes->keys())
            ->whereNotIn('id', $used)
            ->when($data['source_id'] ?? null, fn ($q, $s) => $q->where('source_id', $s))
            ->when($data['q'] ?? null, fn ($q, $text) => $q->where('text', 'like', '%'.addcslashes($text, '%_\\').'%'))
            ->orderBy('source_id')->orderBy('start_position');
        $page = $query->paginate(($data['all'] ?? false) ? 1000 : 50, ['id', 'source_id', 'code_id', 'text', 'start_position', 'end_position'], 'page', $data['page'] ?? 1);
        $sourceNames = Source::whereIn('id', collect($page->items())->pluck('source_id')->unique())->pluck('name', 'id');

        return response()->json([
            'candidates' => collect($page->items())->map(fn ($s) => [
                'selectionId' => $s->id,
                'sourceId' => $s->source_id,
                'sourceName' => $sourceNames[$s->source_id] ?? null,
                'codeId' => $s->code_id,
                'codeName' => $projectCodes[$s->code_id] ?? null,
                'text' => $s->text,
                'start' => (int) $s->start_position,
                'end' => (int) $s->end_position,
            ])->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'linkedCodes' => count($codeIds),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'target_type' => ['required', 'in:'.implode(',', array_keys(AnalyticEvidence::TARGETS))],
            'target_id' => ['required', 'uuid'],
            'selection_ids' => ['required', 'array', 'min:1', 'max:500'],
            'selection_ids.*' => ['uuid', 'distinct'],
            'stance' => ['required', 'in:'.implode(',', AnalyticEvidence::STANCES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $target = $this->target($project, $data['target_type'], $data['target_id']);
        $selections = Selection::where('project_id', $project->id)->whereIn('id', $data['selection_ids'])->get();
        if ($selections->count() !== count($data['selection_ids'])) {
            throw ValidationException::withMessages(['selection_ids' => 'All segments must belong to this project.']);
        }

        $created = 0;
        foreach ($selections as $selection) {
            try {
                AnalyticEvidence::create([
                    'project_id' => $project->id,
                    'target_type' => $data['target_type'],
                    'target_id' => $target->id,
                    'selection_id' => $selection->id,
                    'source_id' => $selection->source_id,
                    'start_position' => (int) $selection->start_position,
                    'end_position' => (int) $selection->end_position,
                    'text' => (string) $selection->text,
                    'stance' => $data['stance'],
                    'note' => $data['note'] ?? null,
                    'resolution' => $data['stance'] === 'challenges' ? 'open' : null,
                    'creating_user_id' => Auth::id(),
                ]);
                $created++;
            } catch (UniqueConstraintViolationException) {
                // already evidence for this target – keep the existing entry
            }
        }

        return response()->json(['created' => $created, 'evidence' => self::listFor($project, $data['target_type'], $target->id)], 201);
    }

    public function update(Request $request, Project $project, AnalyticEvidence $evidence): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($evidence->project_id === $project->id, 404);
        $data = $request->validate([
            'stance' => ['sometimes', 'in:'.implode(',', AnalyticEvidence::STANCES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'in:'.implode(',', AnalyticEvidence::RESOLUTIONS)],
            'resolution_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $evidence->fill($data);
        if ($evidence->stance === 'challenges') {
            $evidence->resolution ??= 'open';
        } else {
            $evidence->resolution = null;
            $evidence->resolution_note = null;
        }
        $evidence->save();

        return response()->json(['evidence' => self::listFor($project, $evidence->target_type, $evidence->target_id)]);
    }

    public function destroy(Project $project, AnalyticEvidence $evidence): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($evidence->project_id === $project->id, 404);
        $evidence->delete();

        return response()->json(['evidence' => self::listFor($project, $evidence->target_type, $evidence->target_id)]);
    }

    public static function listFor(Project $project, string $type, string $id): array
    {
        $rows = AnalyticEvidence::where('project_id', $project->id)->where('target_type', $type)->where('target_id', $id)
            ->orderBy('created_at')->orderBy('source_id')->orderBy('start_position')->get();
        $sourceNames = Source::whereIn('id', $rows->pluck('source_id')->unique())->pluck('name', 'id')->all();
        $codes = Selection::whereIn('id', $rows->pluck('selection_id')->filter()->unique())->pluck('code_id', 'id')->all();

        return $rows->map(fn ($e) => $e->toClientArray($sourceNames, $codes))->values()->all();
    }

    private function target(Project $project, string $type, string $id): Concept|Proposition
    {
        $model = AnalyticEvidence::TARGETS[$type];
        $target = $model::where('project_id', $project->id)->find($id);
        if (! $target) {
            throw ValidationException::withMessages(['target_id' => 'Unknown '.$type.' in this project.']);
        }

        return $target;
    }
}
