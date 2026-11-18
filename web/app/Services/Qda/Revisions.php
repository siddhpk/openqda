<?php

namespace App\Services\Qda;

use App\Models\AnalyticRevision;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps the previous version of a concept or proposition when it changes.
 */
class Revisions
{
    /**
     * @param  array  $before  snapshot before the change
     * @param  array  $after  snapshot after the change
     * @return bool whether anything changed (and a revision was kept)
     */
    public static function keep(string $type, object $model, array $before, array $after, ?string $reason): bool
    {
        if ($before == $after) {
            return false;
        }
        AnalyticRevision::create([
            'project_id' => $model->project_id,
            'target_type' => $type,
            'target_id' => $model->id,
            'version' => $model->version,
            'snapshot' => $before,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'changed_by' => Auth::id(),
        ]);
        $model->version++;
        $model->updated_by = Auth::id();
        $model->save();

        return true;
    }

    public static function list(string $type, string $id, int $limit = 50): array
    {
        return AnalyticRevision::with('author:id,name')
            ->where('target_type', $type)->where('target_id', $id)
            ->orderByDesc('version')->limit($limit)->get()
            ->map(fn ($r) => [
                'version' => $r->version,
                'snapshot' => $r->snapshot,
                'reason' => $r->reason,
                'author' => $r->author?->name,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])->all();
    }
}
