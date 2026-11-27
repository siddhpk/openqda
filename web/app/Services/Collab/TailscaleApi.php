<?php

namespace App\Services\Collab;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * One-click invitations: creates single-use, pre-approved Tailscale keys
 * tagged tag:openqda with an OAuth client the Primary created in the
 * Tailscale admin console (scope "Auth Keys", tag tag:openqda). The client
 * secret is stored encrypted and used for nothing else.
 */
final class TailscaleApi
{
    public static function configured(): bool
    {
        return Collab::secret('oauth_client_id') && Collab::secret('oauth_client_secret');
    }

    public static function createAuthKey(): string
    {
        if (! self::configured()) {
            throw ValidationException::withMessages(['key' => 'Set up one-click invitations first, or paste a key.']);
        }
        $base = rtrim((string) config('collab.tailscale_api'), '/');
        try {
            $token = Http::asForm()->timeout(20)->post("{$base}/api/v2/oauth/token", [
                'client_id' => Collab::secret('oauth_client_id'),
                'client_secret' => Collab::secret('oauth_client_secret'),
                'grant_type' => 'client_credentials',
            ])->throw()->json('access_token');
            $key = Http::withToken($token)->timeout(20)->post("{$base}/api/v2/tailnet/-/keys", [
                'capabilities' => ['devices' => ['create' => [
                    'reusable' => false,
                    'ephemeral' => false,
                    'preauthorized' => true,
                    'tags' => [config('collab.tag')],
                ]]],
                'expirySeconds' => 86400 * (int) config('collab.invite_days', 7),
                'description' => 'OpenQDA invitation',
            ])->throw()->json('key');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['key' => 'Tailscale did not create a key. Check the OAuth client (scope Auth Keys, tag '
                .config('collab.tag').') and that the tag is in your access controls.']);
        }
        if (! is_string($key) || ! str_starts_with($key, 'tskey-')) {
            throw ValidationException::withMessages(['key' => 'Tailscale did not return a key.']);
        }

        return $key;
    }
}
