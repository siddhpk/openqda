<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Earlier version of a concept or proposition, kept when it changes.
 */
class AnalyticRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'target_type', 'target_id', 'version', 'snapshot', 'reason', 'changed_by'];

    protected $casts = ['snapshot' => 'array', 'version' => 'integer'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
