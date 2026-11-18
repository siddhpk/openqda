<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCodeRelationRequest;
use App\Http\Requests\UpdateCodeRelationRequest;
use App\Models\CodeRelation;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD for non-hierarchical relationships between codes.
 */
class CodeRelationController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('viewAny', [CodeRelation::class, $project]);

        $relations = CodeRelation::where('project_id', $project->id)->get()->map->toClientArray()->values();

        return response()->json(['relations' => $relations]);
    }

    public function store(StoreCodeRelationRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $type = $data['type'];

        try {
            $relation = CodeRelation::create([
                'project_id' => $project->id,
                'from_code_id' => $data['from'],
                'to_code_id' => $data['to'],
                'type' => $type,
                'label' => $data['label'] ?? null,
                'directed' => StoreCodeRelationRequest::directedFor($project, $type, $data['directed'] ?? null),
                'valence' => $data['valence'] ?? null,
                'description' => $data['description'] ?? null,
                'creating_user_id' => Auth::id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        }

        return response()->json([
            'message' => 'Relationship created',
            'relation' => $relation->toClientArray(),
        ], 201);
    }

    public function update(UpdateCodeRelationRequest $request, Project $project, CodeRelation $relation): JsonResponse
    {
        if ($relation->project_id !== $project->id) {
            abort(404);
        }

        $data = $request->validated();
        $relation->fill($data);
        if ($relation->type === 'custom' && trim((string) $relation->label) === '') {
            return response()->json(['message' => 'A custom relationship needs a label.', 'errors' => ['label' => ['A custom relationship needs a label.']]], 422);
        }
        // directedness follows the (possibly new) type
        $relation->directed = StoreCodeRelationRequest::directedFor($project, $relation->type, $relation->type === 'custom' ? ($data['directed'] ?? $relation->directed) : null);
        $key = CodeRelation::dedupeKey($relation->from_code_id, $relation->to_code_id, $relation->type, $relation->label, $relation->directed);
        if (CodeRelation::where('project_id', $project->id)->where('dedupe_key', $key)->where('id', '!=', $relation->id)->exists()) {
            return $this->duplicate();
        }
        try {
            $relation->save();
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate();
        }

        return response()->json([
            'message' => 'Relationship updated',
            'relation' => $relation->toClientArray(),
        ]);
    }

    public function destroy(Project $project, CodeRelation $relation): JsonResponse
    {
        if ($relation->project_id !== $project->id) {
            abort(404);
        }
        $this->authorize('delete', $relation);

        $relation->delete();

        return response()->json(['message' => 'Relationship deleted']);
    }

    private function duplicate(): JsonResponse
    {
        return response()->json(['message' => 'This relationship already exists.', 'errors' => ['type' => ['This relationship already exists.']]], 422);
    }
}
