<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A coded segment used as evidence for a concept or proposition. The
 * excerpt and position are copied from the segment, so the evidence stays
 * readable if the segment is later removed.
 */
class AnalyticEvidence extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'analytic_evidence';

    public const TARGETS = [Concept::TYPE => Concept::class, Proposition::TYPE => Proposition::class];

    public const STANCES = ['supports', 'challenges', 'illustrates', 'qualifies'];

    public const RESOLUTIONS = ['open', 'accounted_for', 'revised', 'unresolved'];

    protected $fillable = [
        'project_id', 'target_type', 'target_id', 'selection_id', 'source_id', 'start_position', 'end_position',
        'text', 'stance', 'note', 'resolution', 'resolution_note', 'creating_user_id',
    ];

    protected $casts = ['start_position' => 'integer', 'end_position' => 'integer'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }

    public function toClientArray(?array $sourceNames = null, ?array $codeOfSelection = null): array
    {
        return [
            'id' => $this->id,
            'targetType' => $this->target_type,
            'targetId' => $this->target_id,
            'selectionId' => $this->selection_id,
            'sourceId' => $this->source_id,
            'sourceName' => $sourceNames[$this->source_id] ?? null,
            'codeId' => $codeOfSelection[$this->selection_id] ?? null,
            'start' => $this->start_position,
            'end' => $this->end_position,
            'text' => $this->text,
            'stance' => $this->stance,
            'note' => $this->note,
            'resolution' => $this->resolution,
            'resolutionNote' => $this->resolution_note,
            'segmentRemoved' => $this->selection_id === null,
            'createdBy' => $this->creating_user_id,
            'createdAt' => $this->created_at,
        ];
    }
}
