<?php

namespace App\Services\Qda;

use App\Models\AnalysisCase;
use App\Models\CodeRelation;
use App\Models\Concept;
use App\Models\Project;
use App\Models\Proposition;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks that a memo's target exists in the memo's project, for the target
 * kinds added by the analysis tools. (Older kinds keep their behaviour.)
 */
class NoteTargets
{
    /** @return array<string, class-string<Model>> */
    public static function models(): array
    {
        return [
            'relation' => CodeRelation::class,
            'concept' => Concept::class,
            'proposition' => Proposition::class,
            'case' => AnalysisCase::class,
        ];
    }

    public static function exists(Project $project, string $type, string $target): bool
    {
        $model = self::models()[$type] ?? null;
        if ($model === null) {
            return true;
        }

        return $model::where('project_id', $project->id)->whereKey($target)->exists();
    }

    /** whether a memo type may be used for a target kind */
    public static function memoTypeAllows(Project $project, ?string $memoType, string $type): bool
    {
        if ($memoType === null || $memoType === '') {
            return true;
        }
        $targets = Vocabulary::for($project)->entry('memo_types', $memoType)['targets'] ?? [];

        return $targets === [] || in_array($type, $targets, true);
    }
}
