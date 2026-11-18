<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Per-project opt-in for the optional AI features.
 */
class ProjectAiSetting extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['project_id', 'enabled', 'consent_fingerprint', 'cloud_acknowledged_at', 'redaction_terms', 'changed_by'];

    protected $casts = [
        'enabled' => 'boolean',
        'cloud_acknowledged_at' => 'datetime',
        'redaction_terms' => 'encrypted:array',
    ];

    // never copy the (sensitive) redaction terms into the audit trail
    protected $auditExclude = ['redaction_terms'];

    protected $hidden = ['redaction_terms'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function for(Project $project): ?self
    {
        return static::where('project_id', $project->id)->first();
    }

    /**
     * @return string[]
     */
    public function terms(): array
    {
        return array_values(array_filter((array) ($this->redaction_terms ?? []), 'is_string'));
    }
}
