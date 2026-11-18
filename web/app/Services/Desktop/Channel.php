<?php

namespace App\Services\Desktop;

use App\Services\AI\AiManager;
use Illuminate\Http\Request;

/**
 * Which listener of the laptop edition a request came through.
 *
 * The value is injected by the web server configuration that the launcher
 * writes (FrankenPHP `env OPENQDA_CHANNEL ...` per site). It is read from
 * the server variables only, so a client cannot choose its channel with a
 * header. Outside the laptop edition every request is on the "server"
 * channel and nothing here changes behaviour.
 */
class Channel
{
    public const LOCAL = 'local';

    public const SHARED = 'shared';

    public const LAN = 'lan';

    public const SERVER = 'server';

    /**
     * Whether the Embeddings & Autocoding module is part of this build; the
     * Sharing page offers to switch autocoding on only then.
     */
    public static function autocodingInstalled(): bool
    {
        return class_exists(AiManager::class);
    }

    public static function desktop(): bool
    {
        return (bool) config('desktop.enabled');
    }

    public static function of(Request $request): string
    {
        if (! self::desktop()) {
            return self::SERVER;
        }
        $value = $request->server('OPENQDA_CHANNEL');

        // an unknown or missing value gets the most restricted treatment
        return in_array($value, [self::LOCAL, self::SHARED, self::LAN], true) ? $value : self::SHARED;
    }

    /** The person at this computer (may manage sharing and shut down). */
    public static function isHost(Request $request): bool
    {
        return self::desktop() && self::of($request) === self::LOCAL;
    }

    /** A visitor who came through the sharing link or the network address. */
    public static function isGuest(Request $request): bool
    {
        return in_array(self::of($request), [self::SHARED, self::LAN], true);
    }
}
