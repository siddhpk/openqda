<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record of an autocoding run (parameters and evaluation metrics only).
 */
class AutocodeRun extends Model
{
    protected $fillable = ['project_id', 'source_id', 'creating_user_id', 'params', 'metrics', 'accepted'];

    protected $casts = [
        'params' => 'array',
        'metrics' => 'array',
        'accepted' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function origin(): string
    {
        return 'autocode:'.$this->id;
    }
}
