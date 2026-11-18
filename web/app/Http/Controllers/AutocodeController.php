<?php

namespace App\Http\Controllers;

use App\Models\AiSuggestionDecision;
use App\Models\AutocodeRun;
use App\Models\Code;
use App\Models\Project;
use App\Models\Selection;
use App\Models\Source;
use App\Services\AI\AiManager;
use App\Services\Collab\LiveCoding;
use App\Services\Qda\SourceText;
use App\Traits\ValidatesStoragePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Server side of (batch) autocoding.
 *
 * The browser segments sources with the coding editor's own text model,
 * labels units with the stored selections (exact positions) and fits the
 * statistical models in a Web Worker. The server supplies the corpus,
 * embeddings (AiController@embed), stores audit records of runs and
 * writes accepted suggestions as selections in bulk.
 */
class AutocodeController extends Controller
{
    use ValidatesStoragePath;

    public function __construct(protected AiManager $ai) {}

    /**
     * Batch autocoding page.
     */
    public function show(Project $project): Response
    {
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabled(), 404);

        $sources = Source::where('project_id', $project->id)
            ->withCount('selections')
            ->orderBy('name')
            ->get()
            ->filter(fn ($s) => (bool) $s->converted)
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'selectionsCount' => $s->selections_count])
            ->values();

        // all columns: "kind" (code type) exists only with the Analysis Toolkit module
        $codes = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'parent_id' => $c->parent_id, 'kind' => $c->kind ?? 'code', 'codebook' => $c->codebook_id])
            ->values();

        return Inertia::render('AutocodePage', [
            'projectId' => $project->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'sources' => $sources,
            'codes' => $codes,
        ]);
    }

    /**
     * Texts and selections needed for training and prediction: every source
     * that has coded segments plus the requested target sources.
     */
    public function corpus(Request $request, Project $project): JsonResponse
    {
        $this->guard($project);
        $maxSources = (int) config('ai.autocode.max_sources', 500);
        $data = $request->validate([
            'target_source_ids' => ['present', 'array', "max:{$maxSources}"],
            'target_source_ids.*' => ['uuid'],
        ]);

        $targets = Source::where('project_id', $project->id)->whereIn('id', $data['target_source_ids'])->pluck('id')->all();
        if (count($targets) !== count(array_unique($data['target_source_ids']))) {
            throw ValidationException::withMessages(['target_source_ids' => 'All sources must belong to this project.']);
        }

        $codeIds = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->pluck('id');
        $selections = Selection::where('project_id', $project->id)
            ->whereIn('code_id', $codeIds)
            ->get(['source_id', 'code_id', 'start_position', 'end_position']);
        $codedIds = $selections->pluck('source_id')->unique()->all();
        $ids = array_values(array_unique(array_merge($codedIds, $targets)));

        if (count($ids) > $maxSources) {
            throw ValidationException::withMessages(['target_source_ids' => "Too many sources (max {$maxSources})."]);
        }

        $budget = (int) config('ai.autocode.max_corpus_bytes', 50 * 1024 * 1024);
        $sources = [];
        foreach (Source::where('project_id', $project->id)->whereIn('id', $ids)->get() as $source) {
            $converted = $source->converted;
            $path = $converted && $converted->path ? $this->validateStoragePath($converted->path) : null;
            if ($path === null) {
                continue;
            }
            $budget -= filesize($path) ?: 0;
            if ($budget < 0) {
                throw ValidationException::withMessages(['target_source_ids' => 'The selected sources are too large to autocode at once. Select fewer sources.']);
            }
            $sources[] = [
                'id' => $source->id,
                'name' => $source->name,
                'content' => (string) file_get_contents($path),
                'target' => in_array($source->id, $targets, true),
                'coded' => in_array($source->id, $codedIds, true),
            ];
        }

        return response()->json([
            'sources' => $sources,
            'selections' => $selections
                ->filter(fn ($s) => in_array($s->source_id, $ids, true))
                ->map(fn ($s) => [
                    'source_id' => $s->source_id,
                    'code_id' => $s->code_id,
                    'start' => (int) $s->start_position,
                    'end' => (int) $s->end_position,
                ])->values(),
        ]);
    }

    /**
     * Records a run. Figures computed in the browser are validated, reduced
     * to known fields and marked as client-reported; what the server knows
     * itself (embedding configuration, consent fingerprint) is added by the
     * server, not taken from the client.
     */
    public function storeRun(Request $request, Project $project): JsonResponse
    {
        $this->guard($project);
        $unit = ['nullable', 'numeric', 'min:0', 'max:1'];
        $count = ['nullable', 'integer', 'min:0', 'max:10000000'];
        $data = $request->validate([
            'source_id' => ['nullable', 'uuid'],
            'params' => ['required', 'array'],
            'params.granularity' => ['required', 'in:sentence,paragraph'],
            'params.method' => ['required', 'in:auto,similarity,logistic'],
            'params.validation' => ['sometimes', 'in:sources,units'],
            'params.multiple' => ['sometimes', 'boolean'],
            'params.minExamples' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'params.rollUp' => ['sometimes', 'boolean'],
            'params.includeCoded' => ['sometimes', 'boolean'],
            'params.seed' => ['sometimes', 'integer'],
            'params.targets' => $count,
            'params.include' => ['sometimes', 'array', 'max:5000'],
            'params.include.*' => ['uuid'],
            'params.embedding.dimensions' => $count,
            'metrics' => ['required', 'array'],
            'metrics.type' => ['required', 'in:similarity,logistic'],
            'metrics.name' => ['sometimes', 'string', 'max:200'],
            'metrics.scale' => ['sometimes', 'string', 'max:200'],
            'metrics.lambda' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'metrics.parameters' => $count,
            'metrics.dimensions' => $count,
            'metrics.nTrain' => $count,
            'metrics.negatives' => $count,
            'metrics.multiCoded' => $count,
            'metrics.nTargets' => $count,
            'metrics.microF1' => $unit,
            'metrics.macroF1' => $unit,
            'metrics.exactMatch' => $unit,
            'metrics.validation' => ['sometimes', 'array'],
            'metrics.validation.scheme' => ['required_with:metrics.validation', 'in:sources,units,none'],
            'metrics.validation.folds' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'metrics.validation.groups' => $count,
            'metrics.validation.nested' => ['sometimes', 'boolean'],
            'metrics.validation.evaluated' => $count,
            'metrics.perClass' => ['sometimes', 'array', 'max:5000'],
            'metrics.perClass.*.classId' => ['required', 'uuid'],
            'metrics.perClass.*.precision' => $unit,
            'metrics.perClass.*.recall' => $unit,
            'metrics.perClass.*.f1' => $unit,
            'metrics.perClass.*.support' => $count,
            'metrics.perClass.*.suggested' => $count,
            'metrics.perClass.*.examples' => $count,
            'metrics.comparison' => ['sometimes', 'array', 'max:5'],
            'metrics.comparison.*.model' => ['required', 'in:similarity,logistic'],
            'metrics.comparison.*.microF1' => $unit,
            'metrics.comparison.*.macroF1' => $unit,
            'metrics.comparison.*.chosen' => ['sometimes', 'boolean'],
            'metrics.classes' => ['sometimes', 'array', 'max:5000'],
            'metrics.classes.*.id' => ['required', 'uuid'],
            'metrics.classes.*.count' => $count,
            'metrics.classes.*.rolledUp' => ['sometimes', 'array'],
            'metrics.classes.*.rolledUp.*' => ['uuid'],
            'metrics.classes.*.threshold' => $unit,
            'metrics.excluded' => ['sometimes', 'array', 'max:5000'],
            'metrics.excluded.*.id' => ['required', 'uuid'],
            'metrics.excluded.*.count' => $count,
            'metrics.warnings' => ['sometimes', 'array', 'max:100'],
            'metrics.warnings.*' => ['string', 'max:500'],
        ]);
        if (! empty($data['source_id'])) {
            abort_unless(Source::where('id', $data['source_id'])->where('project_id', $project->id)->exists(), 404);
        }

        // every code a run refers to must belong to this project
        $referenced = collect($data['params']['include'] ?? [])
            ->merge(collect($data['metrics']['classes'] ?? [])->pluck('id'))
            ->merge(collect($data['metrics']['classes'] ?? [])->pluck('rolledUp')->flatten())
            ->merge(collect($data['metrics']['perClass'] ?? [])->pluck('classId'))
            ->merge(collect($data['metrics']['excluded'] ?? [])->pluck('id'))
            ->filter()->unique();
        if ($referenced->isNotEmpty()) {
            $known = Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->whereIn('id', $referenced)->count();
            if ($known !== $referenced->count()) {
                throw ValidationException::withMessages(['metrics' => 'The run refers to codes outside this project.']);
            }
        }

        $params = $this->only($data['params'], ['granularity', 'method', 'validation', 'multiple', 'minExamples', 'rollUp', 'includeCoded', 'seed', 'targets', 'include']);
        $params['server'] = [
            'embedding' => [
                'provider' => $this->ai->embeddingProviderName(),
                'model' => $this->ai->embeddingModel(),
                'configured_dimensions' => $this->ai->embeddingDimensions(),
                'reported_dimensions' => $data['params']['embedding']['dimensions'] ?? null,
                'cloud' => $this->ai->usesCloud(),
            ],
            'consent_fingerprint' => $this->ai->configFingerprint(),
        ];
        $metrics = $this->only($data['metrics'], ['type', 'name', 'scale', 'lambda', 'parameters', 'dimensions', 'nTrain', 'negatives', 'multiCoded', 'nTargets', 'microF1', 'macroF1', 'exactMatch', 'validation', 'perClass', 'comparison', 'classes', 'excluded', 'warnings']);
        $metrics['reported_by'] = 'client';

        $run = AutocodeRun::create([
            'project_id' => $project->id,
            'source_id' => $data['source_id'] ?? null,
            'creating_user_id' => Auth::id(),
            'params' => $params,
            'metrics' => $metrics,
        ]);

        return response()->json(['run' => ['id' => $run->id, 'origin' => $run->origin()]], 201);
    }

    public function updateRun(Request $request, Project $project, AutocodeRun $run): JsonResponse
    {
        $this->guard($project);
        abort_unless($run->project_id === $project->id, 404);
        $data = $request->validate(['accepted' => ['required', 'integer', 'min:0']]);
        // a count, never more than the selections that carry the run's origin
        $written = Selection::where('project_id', $project->id)->where('origin', $run->origin())->count();
        $run->update(['accepted' => min($data['accepted'], $written)]);

        return response()->json(['run' => ['id' => $run->id, 'accepted' => $run->accepted]]);
    }

    /**
     * Writes accepted suggestions (possibly several codes per unit and many
     * sources) as selections in one transaction. Every item is validated
     * against the project and its text against the source; provenance is
     * stored as "autocode:<run id>".
     */
    public function accept(Request $request, Project $project, AutocodeRun $run, SourceText $sourceText): JsonResponse
    {
        $this->guard($project);
        abort_unless($run->project_id === $project->id, 404);
        $max = (int) config('ai.autocode.max_accept', 2000);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', "max:{$max}"],
            'items.*.source_id' => ['required', 'uuid'],
            'items.*.code_id' => ['required', 'uuid'],
            'items.*.start' => ['required', 'integer', 'min:0'],
            'items.*.end' => ['required', 'integer', 'gt:items.*.start'],
            'items.*.text' => ['required', 'string', 'max:20000'],
        ]);

        $sourceIds = collect($data['items'])->pluck('source_id')->unique();
        $codeIds = collect($data['items'])->pluck('code_id')->unique();
        $this->assertBelongs($project, $sourceIds, $codeIds);
        $this->assertTextMatches($sourceText, $data['items']);

        $origin = $run->origin();
        $created = DB::transaction(function () use ($data, $project, $origin) {
            $out = [];
            foreach ($data['items'] as $item) {
                // skip exact duplicates (same code, same span) – e.g. a double click
                $exists = Selection::where('source_id', $item['source_id'])
                    ->where('code_id', $item['code_id'])
                    ->where('start_position', $item['start'])
                    ->where('end_position', $item['end'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                $selection = Selection::create([
                    'id' => (string) Str::uuid(),
                    'text' => $item['text'],
                    'start_position' => $item['start'],
                    'end_position' => $item['end'],
                    'code_id' => $item['code_id'],
                    'source_id' => $item['source_id'],
                    'project_id' => $project->id,
                    'creating_user_id' => Auth::id(),
                    'origin' => $origin,
                ]);
                $out[] = ['id' => $selection->id, 'source_id' => $item['source_id'], 'code_id' => $item['code_id'], 'start' => $item['start'], 'end' => $item['end']];
            }

            return $out;
        });

        $run->increment('accepted', count($created));

        // with the Live Team Coding module, others with these sources open are
        // offered a reload instead of receiving up to thousands of single updates
        if (class_exists(LiveCoding::class)) {
            collect($created)->countBy('source_id')
                ->each(fn (int $count, string $sourceId) => LiveCoding::sourceStale($sourceId, $count));
        }

        return response()->json(['created' => $created, 'accepted' => $run->fresh()->accepted], 201);
    }

    /**
     * Records what the researcher did with suggestions: accepted as
     * suggested, modified (other codes chosen), rejected or deferred. Only
     * positions, code ids, scores and a keyed hash of the segment are kept.
     */
    public function decisions(Request $request, Project $project, AutocodeRun $run, SourceText $sourceText): JsonResponse
    {
        $this->guard($project);
        abort_unless($run->project_id === $project->id, 404);
        $max = (int) config('ai.autocode.max_accept', 2000);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', "max:{$max}"],
            'items.*.source_id' => ['required', 'uuid'],
            'items.*.start' => ['required', 'integer', 'min:0'],
            'items.*.end' => ['required', 'integer', 'gt:items.*.start'],
            'items.*.text' => ['required', 'string', 'max:20000'],
            'items.*.decision' => ['required', 'in:'.implode(',', AiSuggestionDecision::DECISIONS)],
            'items.*.suggested' => ['present', 'array', 'max:100'],
            'items.*.suggested.*' => ['uuid'],
            'items.*.chosen' => ['present', 'array', 'max:100'],
            'items.*.chosen.*' => ['uuid'],
            'items.*.scores' => ['sometimes', 'array', 'max:100'],
            'items.*.scores.*' => ['numeric', 'min:0', 'max:1'],
        ]);

        $items = collect($data['items']);
        $codeIds = $items->flatMap(fn ($i) => array_merge($i['suggested'], $i['chosen'], array_keys($i['scores'] ?? [])))->unique()->values();
        $this->assertBelongs($project, $items->pluck('source_id')->unique(), $codeIds);
        $this->assertTextMatches($sourceText, $data['items']);
        foreach ($data['items'] as $i => $item) {
            $same = collect($item['chosen'])->sort()->values()->all() === collect($item['suggested'])->sort()->values()->all();
            $consistent = match ($item['decision']) {
                'accepted' => $same && $item['chosen'] !== [],
                'modified' => ! $same && $item['chosen'] !== [],
                default => true,
            };
            if (! $consistent) {
                throw ValidationException::withMessages(["items.{$i}.decision" => 'The decision does not match the suggested and chosen codes.']);
            }
        }

        $now = now();
        $rows = array_map(fn ($item) => [
            'project_id' => $project->id,
            'autocode_run_id' => $run->id,
            'source_id' => $item['source_id'],
            'segment_start' => $item['start'],
            'segment_end' => $item['end'],
            'segment_hash' => SourceText::segmentHash((string) $project->id, $item['text']),
            'decision' => $item['decision'],
            'suggested' => json_encode(array_values($item['suggested'])),
            'chosen' => json_encode(array_values($item['chosen'])),
            'scores' => isset($item['scores']) ? json_encode(array_map(fn ($v) => round((float) $v, 4), $item['scores'])) : null,
            'user_id' => Auth::id(),
            'created_at' => $now,
        ], $data['items']);
        DB::transaction(fn () => collect($rows)->chunk(500)->each(fn ($chunk) => AiSuggestionDecision::insert($chunk->all())));

        return response()->json(['recorded' => count($rows), 'summary' => $this->decisionSummary($run)], 201);
    }

    public function runs(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $runs = AutocodeRun::where('project_id', $project->id)->latest()->limit(50)->get()
            ->map(fn ($run) => $run->toArray() + ['decisions' => $this->decisionSummary($run)]);

        return response()->json(['runs' => $runs]);
    }

    /** latest decision per segment, counted by kind */
    private function decisionSummary(AutocodeRun $run): array
    {
        $latest = AiSuggestionDecision::where('autocode_run_id', $run->id)
            ->orderBy('id')
            ->get(['source_id', 'segment_start', 'segment_end', 'decision'])
            ->keyBy(fn ($d) => $d->source_id.':'.$d->segment_start.':'.$d->segment_end);

        return collect(AiSuggestionDecision::DECISIONS)
            ->mapWithKeys(fn ($kind) => [$kind => $latest->where('decision', $kind)->count()])
            ->all();
    }

    private function assertBelongs(Project $project, $sourceIds, $codeIds): void
    {
        $validSources = Source::where('project_id', $project->id)->whereIn('id', $sourceIds)->count();
        $validCodes = $codeIds->isEmpty() ? 0 : Code::whereIn('codebook_id', $project->codebooks()->pluck('id'))->whereIn('id', $codeIds)->count();
        if ($validSources !== $sourceIds->count() || $validCodes !== $codeIds->count()) {
            throw ValidationException::withMessages(['items' => 'All sources and codes must belong to this project.']);
        }
    }

    private function assertTextMatches(SourceText $sourceText, array $items): void
    {
        $failures = $sourceText->verify($items);
        if ($failures) {
            $first = array_key_first($failures);
            throw ValidationException::withMessages([
                'items' => count($failures).' segment(s) do not match their source (first: item '.($first + 1).', '.$failures[$first].'). Reload the sources and run autocoding again.',
            ]);
        }
    }

    private function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    protected function guard(Project $project): void
    {
        abort_unless($this->ai->isEnabled(), 404);
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabledForProject($project), 403, 'AI features are not enabled for this project and provider configuration.');
    }
}
