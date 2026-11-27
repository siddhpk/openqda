<?php

namespace App\Services\Collab;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the launcher's node agent on loopback (OPENQDA_CONTROL_URL).
 * Every call tolerates the agent being away: collaboration pauses, local
 * work never fails because of it.
 */
final class ControlClient
{
    public static function available(): bool
    {
        return (string) config('collab.control_url') !== '' && (string) config('collab.control_token') !== '';
    }

    private static function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('collab.control_url'), '/'))
            ->withToken((string) config('collab.control_token'))->acceptJson()->timeout($timeout);
    }

    public static function status(): ?array
    {
        if (! self::available()) {
            return null;
        }
        try {
            $r = self::http(5)->get('/v1/status');

            return $r->successful() ? $r->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function post(string $path, array $body, int $timeout): array
    {
        if (! self::available()) {
            return ['ok' => false, 'error' => 'The collaboration service is not running.'];
        }
        try {
            $r = self::http($timeout)->post($path, $body);
            $json = $r->json() ?? [];

            return ['ok' => $r->successful(), 'conflict' => $r->status() === 409, 'error' => $json['error'] ?? null] + $json;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The collaboration service did not answer.'];
        }
    }

    public static function connect(): array
    {
        return self::post('/v1/tailscale/connect', [], 40);
    }

    public static function authKey(string $key, bool $replace): array
    {
        return self::post('/v1/tailscale/authkey', ['key' => $key, 'replace' => $replace], 70);
    }

    public static function logout(): array
    {
        return self::post('/v1/tailscale/logout', [], 25);
    }

    public static function tags(array $tags): array
    {
        return self::post('/v1/tailscale/tags', ['tags' => $tags], 35);
    }

    /** Hand new local changes to the agent (sent on at once). */
    public static function publish(array $batches): void
    {
        $batches = array_values(array_filter($batches, fn ($b) => ! empty($b['ops']) || ! empty($b['events'])));
        if ($batches) {
            self::post('/v1/ops', ['batches' => $batches], 3);
        }
    }

    public static function rosterChanged(): void
    {
        self::post('/v1/roster', [], 3);
    }

    public static function sync(): void
    {
        self::post('/v1/sync', [], 3);
    }
}
