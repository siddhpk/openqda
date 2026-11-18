<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One AI request: who, which provider, how much (never what).
 */
class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'user_id', 'action', 'provider', 'model', 'cloud', 'items', 'characters', 'redactions'];

    protected $casts = ['cloud' => 'boolean'];
}
