<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached text embedding of one project (packed float32, optionally
 * encrypted with the application key). Holds no plain text.
 */
class AiEmbedding extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'hash', 'provider', 'model', 'dimensions', 'vector'];

    protected $hidden = ['vector'];

    protected $casts = ['dimensions' => 'integer'];
}
