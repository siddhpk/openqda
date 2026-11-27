<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;

/**
 * Hybrid logical clock. Timestamps look like "0001732451234567-0003-n1a2b"
 * (milliseconds, counter, node) and sort as strings. A node never stamps a
 * change earlier than any change it has seen, so an edit made after seeing
 * another one always sorts after it, even when the laptops' clocks differ.
 */
final class Hlc
{
    public static function format(int $ms, int $counter, string $node): string
    {
        return sprintf('%016d-%04d-%s', $ms, $counter, $node);
    }

    /** @return array{0:int,1:int} */
    public static function parse(?string $hlc): array
    {
        if (! $hlc || ! preg_match('/^(\d{16})-(\d{4})-/', $hlc, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** The next local timestamp (call inside the transaction that uses it). */
    public static function next(): string
    {
        [$ms, $counter] = self::parse(Collab::setting('hlc'));
        $now = self::nowMs();
        if ($now > $ms) {
            $ms = $now;
            $counter = 0;
        } else {
            $counter++;
            if ($counter > 9999) {
                $ms++;
                $counter = 0;
            }
        }
        $hlc = self::format($ms, $counter, Collab::node());
        Collab::putSetting('hlc', $hlc);

        return $hlc;
    }

    /** Move the clock past a timestamp received from a peer. */
    public static function observe(string $remote): void
    {
        [$rms, $rc] = self::parse($remote);
        [$ms, $c] = self::parse(Collab::setting('hlc'));
        if ($rms > $ms || ($rms === $ms && $rc > $c)) {
            Collab::putSetting('hlc', self::format($rms, $rc, Collab::node()));
        }
    }

    /** Whether a timestamp is too far ahead of this computer's clock. */
    public static function tooFarAhead(string $hlc): bool
    {
        [$ms] = self::parse($hlc);

        return $ms > self::nowMs() + 1000 * (int) config('collab.future_skew_seconds', 600);
    }

    public static function ensureSettingsRow(): void
    {
        DB::table('collab_settings')->insertOrIgnore(['key' => 'hlc', 'value' => self::format(0, 0, Collab::node())]);
    }
}
