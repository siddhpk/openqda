<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's research design in the researchers' own words.
 */
class ResearchDesign extends Model
{
    public const TEXT_FIELDS = ['questions', 'aims', 'approach', 'positioning', 'rationale'];

    protected $fillable = ['project_id', 'questions', 'aims', 'approach', 'positioning', 'rationale', 'plan', 'version', 'updated_by'];

    protected $casts = ['plan' => 'array', 'version' => 'integer'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function snapshot(): array
    {
        return $this->only(self::TEXT_FIELDS);
    }
}
