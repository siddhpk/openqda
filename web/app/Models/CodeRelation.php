<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A typed, optionally directed relationship between two codes
 * The available types come from the project's vocabulary (App\\Services\\Qda\\Vocabulary).
 */
class CodeRelation extends Model implements Auditable
{
    use HasFactory, HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'project_id',
        'from_code_id',
        'to_code_id',
        'type',
        'label',
        'directed',
        'valence',
        'description',
        'creating_user_id',
    ];

    protected $casts = [
        'directed' => 'boolean',
        'valence' => 'integer',
    ];

    /**
     * Identity of a relationship for duplicate detection: the pair (in
     * either order when undirected) plus the type, or the label for custom
     * types.
     */
    public static function dedupeKey(string $from, string $to, string $type, ?string $label, bool $directed): string
    {
        if (! $directed && strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }
        $kind = $type === 'custom' ? 'custom:'.mb_strtolower(trim((string) $label)) : $type;

        return sha1($from.'|'.$to.'|'.$kind);
    }

    protected static function booted(): void
    {
        static::saving(function (CodeRelation $relation) {
            $relation->dedupe_key = self::dedupeKey(
                (string) $relation->from_code_id,
                (string) $relation->to_code_id,
                (string) $relation->type,
                $relation->label,
                (bool) $relation->directed
            );
        });

        // memos attached to a relation go with it
        static::deleting(function (CodeRelation $relation) {
            Note::where('type', Note::SCOPE_RELATION)
                ->where('target', $relation->id)
                ->delete();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from_code_id,
            'to' => $this->to_code_id,
            'type' => $this->type,
            'label' => $this->label,
            'directed' => (bool) $this->directed,
            'valence' => $this->valence,
            'description' => $this->description,
            'createdBy' => $this->creating_user_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
