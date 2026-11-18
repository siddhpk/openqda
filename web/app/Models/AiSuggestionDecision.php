<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A researcher's decision on one autocoding suggestion (no text).
 */
class AiSuggestionDecision extends Model
{
    public const DECISIONS = ['accepted', 'modified', 'rejected', 'deferred'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'autocode_run_id', 'source_id', 'segment_start', 'segment_end',
        'segment_hash', 'decision', 'suggested', 'chosen', 'scores', 'user_id',
    ];

    protected $casts = [
        'suggested' => 'array',
        'chosen' => 'array',
        'scores' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutocodeRun::class, 'autocode_run_id');
    }
}
