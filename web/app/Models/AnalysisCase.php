<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A case – a person, site, organisation, episode or whatever the study
 * compares – made up of one or more sources.
 */
class AnalysisCase extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'cases';

    protected $fillable = ['project_id', 'name', 'description', 'creating_user_id'];

    protected static function booted(): void
    {
        static::deleting(function (AnalysisCase $case) {
            Note::where('type', 'case')->where('target', $case->id)->delete();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'case_source', 'case_id', 'source_id');
    }
}
