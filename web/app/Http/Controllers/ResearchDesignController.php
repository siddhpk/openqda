<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectVocabulary;
use App\Models\ResearchDesign;
use App\Models\ResearchDesignRevision;
use App\Models\User;
use App\Services\Qda\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Research design page: the design record (versioned), the project's
 * analytic vocabulary and an optional analysis plan the researchers write
 * themselves. No methodology profiles: every approach is described in the
 * researchers' own words and supported by the same capabilities.
 */
class ResearchDesignController extends Controller
{
    public function show(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        $record = ProjectVocabulary::where('project_id', $project->id)->first();

        return Inertia::render('ResearchDesignPage', [
            'projectId' => $project->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'design' => $this->designPayload($project),
            'revisions' => $this->revisions($project),
            'vocabularyInfo' => [
                'usage' => Vocabulary::usage($project),
                'version' => $record?->version ?? 0,
                'customised' => (bool) $record,
            ],
            'tab' => in_array($request->query('tab'), ['design', 'vocabulary', 'plan'], true) ? $request->query('tab') : 'design',
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $rules = ['reason' => ['nullable', 'string', 'max:2000']];
        foreach (ResearchDesign::TEXT_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:20000'];
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($project, $data, $request) {
            $design = ResearchDesign::lockForUpdate()->firstOrNew(['project_id' => $project->id]);
            $new = [];
            foreach (ResearchDesign::TEXT_FIELDS as $field) {
                $new[$field] = isset($data[$field]) && trim($data[$field]) !== '' ? $data[$field] : null;
            }
            if ($design->exists && $design->snapshot() === $new) {
                return;
            }
            if ($design->exists && $design->version > 0) {
                // keep the version that is being replaced
                ResearchDesignRevision::create([
                    'project_id' => $project->id,
                    'version' => $design->version,
                    'snapshot' => $design->snapshot(),
                    'reason' => $data['reason'] ?? null,
                    'changed_by' => $request->user()->id,
                ]);
            }
            $design->fill($new + [
                'version' => $design->version + 1,
                'updated_by' => $request->user()->id,
            ])->save();
        });

        return response()->json(['design' => $this->designPayload($project), 'revisions' => $this->revisions($project)]);
    }

    /**
     * The analysis plan: steps the researchers define themselves.
     */
    public function plan(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate([
            'plan' => ['present', 'array', 'max:50'],
            'plan.*.id' => ['nullable', 'string', 'max:40'],
            'plan.*.title' => ['required', 'string', 'max:200'],
            'plan.*.description' => ['nullable', 'string', 'max:2000'],
            'plan.*.done' => ['sometimes', 'boolean'],
        ]);
        $plan = array_map(fn ($step) => [
            'id' => $step['id'] ?? (string) Str::uuid(),
            'title' => trim($step['title']),
            'description' => trim((string) ($step['description'] ?? '')),
            'done' => (bool) ($step['done'] ?? false),
        ], $data['plan']);

        $design = ResearchDesign::firstOrNew(['project_id' => $project->id]);
        $design->plan = $plan;
        $design->updated_by = $request->user()->id;
        $design->save();

        return response()->json(['design' => $this->designPayload($project)]);
    }

    /** The design as JSON for the overview panel on other pages. */
    public function summary(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['design' => $this->designPayload($project)]);
    }

    private function designPayload(Project $project): array
    {
        $design = ResearchDesign::where('project_id', $project->id)->first();
        $author = $design?->updated_by ? User::find($design->updated_by) : null;

        return array_merge(
            array_fill_keys(ResearchDesign::TEXT_FIELDS, null),
            $design?->snapshot() ?? [],
            [
                'plan' => $design?->plan ?? [],
                'version' => $design?->version ?? 0,
                'updatedAt' => $design?->updated_at?->toIso8601String(),
                'updatedBy' => $author?->name,
            ]
        );
    }

    private function revisions(Project $project): array
    {
        return ResearchDesignRevision::with('author:id,name')
            ->where('project_id', $project->id)
            ->orderByDesc('version')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'version' => $r->version,
                'snapshot' => $r->snapshot,
                'reason' => $r->reason,
                'changedBy' => $r->author?->name,
                'changedAt' => $r->created_at?->toIso8601String(),
            ])->all();
    }
}
