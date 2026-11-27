<?php

namespace App\Services\Collab;

/**
 * Deterministic JSON (keys sorted at every level) for signatures: the
 * signer and every verifier encode the same decoded structure the same way,
 * however the bytes travelled.
 */
final class Canonical
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map([self::class, 'sort'], $value);
    }

    /** Values compared for change detection (database drivers mix types). */
    public static function norm(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return self::encode($value);
        }
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }
}
