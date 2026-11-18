<?php

namespace App\Models;

use App\Services\Qda\Vocabulary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A project's own analytic vocabulary; every change is audited.
 */
class ProjectVocabulary extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = ['project_id', 'data', 'version', 'updated_by'];

    protected $casts = ['data' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::saved(fn (self $vocabulary) => Vocabulary::forget($vocabulary->project_id));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
