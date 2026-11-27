<?php

namespace App\Services\Collab\Merge;

/**
 * Roles: a Primary's changes merge silently and win over concurrent
 * changes of Assistants. An Assistant's additions and changes to their own
 * items merge at once; changing or deleting an item someone else created
 * waits for a Primary's review.
 */
final class ReviewPolicy
{
    public const PRIMARY = 'primary';

    public const ASSISTANT = 'assistant';

    public static function rank(?string $role): int
    {
        return $role === self::PRIMARY ? 1 : 0;
    }

    public static function needsReview(?string $role, string $origin, ?string $creator): bool
    {
        return $role !== self::PRIMARY && $creator !== null && $creator !== $origin;
    }

    /** Who may live-edit a text: Primaries and the node that created it. */
    public static function mayCoEdit(?string $role, string $node, ?string $creator): bool
    {
        return $role === self::PRIMARY || ($role !== null && ($creator === null || $creator === $node));
    }
}
