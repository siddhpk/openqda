<?php

namespace App\Services\Desktop;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * UltraViewer-style sharing for the laptop edition: the host presses
 * "Start sharing" and hands out a link plus an access code. Visitors must
 * enter the code before they even see the login page; then they log in
 * with their own OpenQDA account and see only projects they belong to.
 *
 * Modes:
 *   internet - link through Tailscale Funnel (HTTPS, works from anywhere)
 *   lan      - this computer's network address (same network, no internet)
 *
 * Every start creates a new session id and code, so stopping and starting
 * again locks out everyone who was let in before.
 */
class SharingManager
{
    public const OFF = 'off';

    public const INTERNET = 'internet';

    public const LAN = 'lan';

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private DesktopState $state, private Tailscale $tailscale) {}

    /**
     * Current sharing state; stops an expired session on the way.
     */
    public function current(): array
    {
        $sharing = $this->state->get('sharing', []);
        $mode = $sharing['mode'] ?? self::OFF;
        if ($mode !== self::OFF && ! empty($sharing['expires_at']) && Carbon::parse($sharing['expires_at'])->isPast()) {
            $this->stop('expired');
            $sharing = $this->state->get('sharing', []);
        }

        return $sharing + ['mode' => self::OFF];
    }

    public function mode(): string
    {
        return $this->current()['mode'];
    }

    /**
     * What the host sees on the Sharing page (includes the code).
     */
    public function forHost(): array
    {
        $s = $this->current();

        return [
            'mode' => $s['mode'],
            'url' => $s['mode'] === self::OFF ? null : ($s['url'] ?? null),
            'code' => $s['mode'] === self::OFF ? null : $this->decryptCode($s['code'] ?? null),
            'startedAt' => $s['started_at'] ?? null,
            'expiresAt' => $s['expires_at'] ?? null,
            'keepOn' => (bool) ($s['keep_on'] ?? false),
            'failures' => (int) ($s['failures'] ?? 0),
            'stoppedReason' => $s['stopped_reason'] ?? null,
            'autostart' => (bool) $this->state->get('autostart', false),
        ];
    }

    /**
     * @return array{ok: bool, message: ?string, actionUrl: ?string}
     */
    public function start(string $mode, bool $keepOn = false): array
    {
        if ($mode === self::INTERNET) {
            $port = (int) config('desktop.shared_port');
            if (! $port) {
                return ['ok' => false, 'message' => 'The sharing listener is not configured (OPENQDA_SHARED_PORT).', 'actionUrl' => null];
            }
            $funnel = $this->tailscale->startFunnel($port);
            if (! $funnel['ok']) {
                return ['ok' => false, 'message' => $funnel['message'], 'actionUrl' => $funnel['actionUrl']];
            }
            $url = $funnel['url'];
        } elseif ($mode === self::LAN) {
            $port = (int) config('desktop.lan_port');
            $ip = $this->lanAddress();
            if (! $port || ! $ip) {
                return ['ok' => false, 'message' => 'No network connection found on this computer.', 'actionUrl' => null];
            }
            $url = 'http://'.$ip.':'.$port;
        } else {
            return ['ok' => false, 'message' => 'Unknown sharing mode.', 'actionUrl' => null];
        }

        if ($this->mode() === self::INTERNET && $mode !== self::INTERNET) {
            $this->tailscale->stopFunnel();
        }
        $hours = max(1, (int) config('desktop.sharing.max_hours', 8));
        $this->state->update(function (array $data) use ($mode, $url, $keepOn, $hours) {
            $data['sharing'] = [
                'mode' => $mode,
                'session' => bin2hex(random_bytes(16)),
                'code' => Crypt::encryptString($this->newCode()),
                'url' => $url,
                'keep_on' => $keepOn,
                'started_at' => now()->toIso8601String(),
                'expires_at' => $keepOn ? null : now()->addHours($hours)->toIso8601String(),
                'failures' => 0,
            ];

            return $data;
        });
        Log::info('desktop.sharing started', ['mode' => $mode, 'keep_on' => $keepOn]);

        return ['ok' => true, 'message' => null, 'actionUrl' => null];
    }

    public function stop(string $reason = 'stopped'): void
    {
        $previous = $this->state->get('sharing.mode', self::OFF);
        if ($previous === self::INTERNET) {
            $this->tailscale->stopFunnel();
        }
        $this->state->update(function (array $data) use ($reason) {
            $data['sharing'] = ['mode' => self::OFF, 'stopped_reason' => $reason, 'stopped_at' => now()->toIso8601String()];

            return $data;
        });
        Log::info('desktop.sharing stopped', ['reason' => $reason]);
    }

    /** New access code; everyone already let in stays in until sharing stops. */
    public function newCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 9; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($chars, 3));
    }

    public function rotateCode(): void
    {
        $this->state->update(function (array $data) {
            if (($data['sharing']['mode'] ?? self::OFF) !== self::OFF) {
                $data['sharing']['code'] = Crypt::encryptString($this->newCode());
                $data['sharing']['failures'] = 0;
            }

            return $data;
        });
    }

    public function setAutostart(bool $on): void
    {
        $this->state->update(fn (array $data) => ['autostart' => $on] + $data);
    }

    public function requestShutdown(): void
    {
        if ($this->mode() !== self::OFF) {
            $this->stop('shutdown');
        }
        $this->state->update(fn (array $data) => ['shutdown' => now()->toIso8601String()] + $data);
    }

    /**
     * Does this visitor's channel match the active mode?
     */
    public function channelOpen(Request $request): bool
    {
        $mode = $this->mode();

        return match (Channel::of($request)) {
            Channel::SHARED => $mode === self::INTERNET,
            Channel::LAN => $mode === self::LAN,
            default => false,
        };
    }

    public function admitted(Request $request): bool
    {
        $session = $this->current()['session'] ?? null;

        return $session && hash_equals($session, (string) $request->session()->get('desktop_share'));
    }

    /**
     * Check an entered access code.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function attempt(Request $request, string $code): array
    {
        $sharing = $this->current();
        if (($sharing['mode'] ?? self::OFF) === self::OFF) {
            return ['ok' => false, 'message' => 'Sharing has been stopped.'];
        }
        $key = 'desktop-share:'.$sharing['session'];
        $perMinute = (int) config('desktop.sharing.attempts_per_minute', 5);
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return ['ok' => false, 'message' => 'Too many attempts. Wait a minute and try again.'];
        }

        $expected = $this->normalize((string) $this->decryptCode($sharing['code'] ?? null));
        if ($expected !== '' && hash_equals($expected, $this->normalize($code))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            $request->session()->put('desktop_share', $sharing['session']);

            return ['ok' => true, 'message' => null];
        }

        RateLimiter::hit($key, 60);
        $failures = $this->state->update(function (array $data) {
            $data['sharing']['failures'] = (int) ($data['sharing']['failures'] ?? 0) + 1;

            return $data;
        })['sharing']['failures'];
        if ($failures >= (int) config('desktop.sharing.max_failures', 20)) {
            // someone is guessing: stop and let the host start again with a new code
            $this->stop('too_many_wrong_codes');

            return ['ok' => false, 'message' => 'Sharing has been stopped.'];
        }

        return ['ok' => false, 'message' => 'That code is not right. Check it with the person who shared the link.'];
    }

    /**
     * Address of this computer on the local network (no packets are sent).
     */
    public function lanAddress(): ?string
    {
        $socket = @stream_socket_client('udp://192.0.2.1:9', $errno, $errstr, 1);
        if ($socket) {
            $name = stream_socket_get_name($socket, false);
            fclose($socket);
            $ip = $name ? preg_replace('/:\d+$/', '', $name) : null;
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! str_starts_with($ip, '127.')) {
                return $ip;
            }
        }
        foreach ((array) @gethostbynamel(gethostname()) as $ip) {
            if (! str_starts_with($ip, '127.')) {
                return $ip;
            }
        }

        return null;
    }

    private function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    private function decryptCode(?string $encrypted): ?string
    {
        if (! $encrypted) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
