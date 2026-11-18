<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An analytic concept: a name, a definition in the researcher's words,
 * properties with their dimensions, the codes that inform it and the
 * evidence for and against it. Not tied to any methodology.
 */
class Concept extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    public const TYPE = 'concept';

    protected $fillable = ['project_id', 'name', 'definition', 'status', 'properties', 'color', 'version', 'creating_user_id', 'updated_by'];

    protected $casts = ['properties' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (Concept $concept) {
            AnalyticEvidence::where('target_type', self::TYPE)->where('target_id', $concept->id)->delete();
            AnalyticRevision::where('target_type', self::TYPE)->where('target_id', $concept->id)->delete();
            CaseAssessment::where('item_type', self::TYPE)->where('item_id', $concept->id)->delete();
            Note::where('type', self::TYPE)->where('target', $concept->id)->delete();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function codes(): BelongsToMany
    {
        return $this->belongsToMany(Code::class, 'concept_code');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AnalyticEvidence::class, 'target_id')->where('target_type', self::TYPE);
    }

    /** what a revision keeps */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'definition' => $this->definition,
            'status' => $this->status,
            'properties' => $this->properties ?? [],
            'color' => $this->color,
            'codes' => $this->codes()->pluck('codes.id')->sort()->values()->all(),
        ];
    }
}
