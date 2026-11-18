<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectVocabulary;
use App\Services\Qda\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read and edit a project's analytic vocabulary. Entries that are in use
 * cannot be removed – archive them instead, so existing analysis keeps its
 * meaning.
 */
class VocabularyController extends Controller
{
    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $data = Vocabulary::normalize($request->validate(Vocabulary::rules()));

        foreach ($data['code_fields'] as $field) {
            if ($field['type'] === 'choice' && ! $field['options']) {
                return response()->json(['message' => "The field \"{$field['label']}\" needs at least one option.", 'errors' => ['code_fields' => ['missing options']]], 422);
            }
        }
        $missing = Vocabulary::missingInUse($data, Vocabulary::usage($project));
        if ($missing) {
            $list = collect($missing)->map(fn ($keys, $section) => str_replace('_', ' ', $section).': '.implode(', ', $keys))->implode('; ');

            return response()->json([
                'message' => "Some entries are still in use and can only be archived, not removed ({$list}).",
                'errors' => ['in_use' => $missing],
            ], 422);
        }

        $record = ProjectVocabulary::firstOrNew(['project_id' => $project->id]);
        $record->data = $data;
        $record->version = ($record->exists ? $record->version : 0) + 1;
        $record->updated_by = $request->user()->id;
        $record->save();

        return response()->json($this->payload($project));
    }

    /**
     * Back to the starter set. Custom entries that are still in use are kept
     * (with their labels), so no existing code, relationship or memo loses
     * its meaning.
     */
    public function reset(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $record = ProjectVocabulary::where('project_id', $project->id)->first();
        if ($record) {
            $usage = Vocabulary::usage($project);
            $data = Vocabulary::defaults();
            $kept = 0;
            foreach (Vocabulary::SECTIONS as $section) {
                $have = array_column($data[$section], 'key');
                foreach ($record->data[$section] ?? [] as $entry) {
                    if (! in_array($entry['key'], $have, true) && isset($usage[$section][$entry['key']])) {
                        $data[$section][] = $entry;
                        $kept++;
                    }
                }
            }
            if ($kept) {
                $record->data = Vocabulary::normalize($data);
                $record->version++;
                $record->updated_by = $request->user()->id;
                $record->save();
            } else {
                $record->delete();
            }
        }
        Vocabulary::forget($project);

        return response()->json($this->payload($project));
    }

    private function payload(Project $project): array
    {
        $record = ProjectVocabulary::where('project_id', $project->id)->first();

        return [
            'vocabulary' => Vocabulary::for($project)->toArray(),
            'usage' => Vocabulary::usage($project),
            'version' => $record?->version ?? 0,
            'customised' => (bool) $record,
            'updatedAt' => $record?->updated_at?->toIso8601String(),
        ];
    }
}
