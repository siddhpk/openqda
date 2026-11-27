<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\DB;

/**
 * Short-lived tokens for the browsers' live channel (the node agent's
 * /_live WebSocket). Only sessions that may open the project get one, so the
 * access-code gate of the sharing feature also protects the live channel.
 * The agent checks them with the same key (cluster.DeriveKey).
 */
final class LiveTokens
{
    public static function key(): string
    {
        return hash_hmac('sha256', 'live-token-v1', (string) config('collab.control_token'), true);
    }

    public static function sign(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::key(), true)), '+/', '-_'), '=');

        return $body.'.'.$sig;
    }

    private static function expires(): int
    {
        return time() + 60 * (int) config('collab.live_token_minutes', 15);
    }

    /** @return array{token:string, me: array{gid:string,name:string,color:string}} */
    public static function live(int $userId, string $projectGid): array
    {
        $gid = IdMap::ensureGid('users', $userId);
        $name = (string) DB::table('users')->where('id', $userId)->value('name');
        $color = Collab::color($gid);

        return [
            'token' => self::sign(['k' => 'live', 'u' => $gid, 'n' => $name, 'c' => $color, 'p' => $projectGid, 'e' => self::expires()]),
            'me' => ['gid' => $gid, 'name' => $name, 'color' => $color],
        ];
    }

    public static function doc(int $userId, string $projectGid, string $doc, bool $edit): string
    {
        return self::sign(['k' => 'doc', 'u' => IdMap::ensureGid('users', $userId), 'p' => $projectGid, 'd' => $doc, 'ed' => $edit, 'e' => self::expires()]);
    }
}
