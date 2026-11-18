<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A proposition: a statement about how concepts relate, with the roles the
 * concepts play (named by the researcher), the conditions under which it
 * holds, a mechanism, its scope and the evidence for and against it.
 */
class Proposition extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    public const TYPE = 'proposition';

    public const TEXT_FIELDS = ['statement', 'conditions', 'mechanism', 'scope'];

    protected $fillable = ['project_id', 'title', 'statement', 'conditions', 'mechanism', 'scope', 'status', 'version', 'creating_user_id', 'updated_by'];

    protected $casts = ['version' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (Proposition $proposition) {
            AnalyticEvidence::where('target_type', self::TYPE)->where('target_id', $proposition->id)->delete();
            AnalyticRevision::where('target_type', self::TYPE)->where('target_id', $proposition->id)->delete();
            CaseAssessment::where('item_type', self::TYPE)->where('item_id', $proposition->id)->delete();
            Note::where('type', self::TYPE)->where('target', $proposition->id)->delete();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function concepts(): BelongsToMany
    {
        return $this->belongsToMany(Concept::class, 'proposition_concept')->withPivot(['role', 'position'])->orderByPivot('position');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AnalyticEvidence::class, 'target_id')->where('target_type', self::TYPE);
    }

    public function snapshot(): array
    {
        return [
            'title' => $this->title,
            'statement' => $this->statement,
            'conditions' => $this->conditions,
            'mechanism' => $this->mechanism,
            'scope' => $this->scope,
            'status' => $this->status,
            'concepts' => $this->concepts()->get()->map(fn ($c) => ['id' => $c->id, 'role' => $c->pivot->role])->values()->all(),
        ];
    }
}
