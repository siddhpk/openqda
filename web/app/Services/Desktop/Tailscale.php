<?php

namespace App\Services\Desktop;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Thin wrapper around the Tailscale command line tool, used to publish the
 * laptop edition through Tailscale Funnel: a public https://<name>.ts.net
 * address whose TLS ends on this computer, so Tailscale's relays cannot
 * read the traffic. Only the person at this computer can trigger it.
 */
class Tailscale
{
    private const CANDIDATES = [
        'C:\\Program Files\\Tailscale\\tailscale.exe',
        '/Applications/Tailscale.app/Contents/MacOS/Tailscale',
        '/opt/homebrew/bin/tailscale',
        '/usr/local/bin/tailscale',
        '/usr/bin/tailscale',
    ];

    public function cli(): ?string
    {
        $configured = config('desktop.tailscale.cli');
        if ($configured) {
            return is_file($configured) ? $configured : null;
        }
        foreach (self::CANDIDATES as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }

        return (new ExecutableFinder)->find('tailscale');
    }

    /**
     * @return array{installed: bool, running: bool, dnsName: ?string, message: ?string}
     */
    public function status(): array
    {
        $cli = $this->cli();
        if (! $cli) {
            return ['installed' => false, 'running' => false, 'dnsName' => null, 'message' => 'Tailscale is not installed on this computer.'];
        }
        $result = $this->run([$cli, 'status', '--json']);
        $json = json_decode($result['output'], true);
        if (! is_array($json)) {
            return ['installed' => true, 'running' => false, 'dnsName' => null, 'message' => 'Tailscale is not running. Open the Tailscale app and sign in.'];
        }
        $state = $json['BackendState'] ?? '';
        $dns = rtrim((string) ($json['Self']['DNSName'] ?? ''), '.');
        if ($state !== 'Running' || $dns === '') {
            return ['installed' => true, 'running' => false, 'dnsName' => null, 'message' => $state === 'NeedsLogin'
                ? 'Sign in to Tailscale first (Tailscale app → Log in).'
                : 'Tailscale is not connected. Open the Tailscale app and connect.'];
        }

        return ['installed' => true, 'running' => true, 'dnsName' => $dns, 'message' => null];
    }

    /**
     * Publish http://127.0.0.1:$port at https://<name>.ts.net (port 443).
     *
     * @return array{ok: bool, url: ?string, actionUrl: ?string, message: ?string}
     */
    public function startFunnel(int $port): array
    {
        $status = $this->status();
        if (! $status['running']) {
            return ['ok' => false, 'url' => null, 'actionUrl' => null, 'message' => $status['message']];
        }
        $result = $this->run([$this->cli(), 'funnel', '--bg', '--yes', 'http://127.0.0.1:'.$port]);
        $output = $result['output'];
        // first use: Funnel must be allowed for this tailnet in the admin console
        if (preg_match('~https://login\.tailscale\.com/\S+~', $output, $m)) {
            return ['ok' => false, 'url' => null, 'actionUrl' => rtrim($m[0], '.,'), 'message' => 'Allow Funnel for your Tailscale network once (opens the Tailscale website), then press Start again.'];
        }
        if (! $result['ok'] || ! $this->funnelActive($port)) {
            return ['ok' => false, 'url' => null, 'actionUrl' => null, 'message' => 'Tailscale could not start the sharing link: '.mb_substr(trim($output), 0, 300)];
        }

        return ['ok' => true, 'url' => 'https://'.$status['dnsName'], 'actionUrl' => null, 'message' => null];
    }

    public function stopFunnel(): void
    {
        if ($cli = $this->cli()) {
            $this->run([$cli, 'funnel', '--https=443', 'off']);
        }
    }

    public function funnelActive(int $port): bool
    {
        $cli = $this->cli();
        if (! $cli) {
            return false;
        }
        $json = json_decode($this->run([$cli, 'funnel', 'status', '--json'])['output'], true);
        if (! is_array($json) || ! in_array(true, (array) ($json['AllowFunnel'] ?? []), true)) {
            return false;
        }

        return str_contains(json_encode($json['Web'] ?? []), '127.0.0.1:'.$port);
    }

    /**
     * @return array{ok: bool, output: string}
     */
    private function run(array $command): array
    {
        try {
            $result = Process::timeout((int) config('desktop.tailscale.timeout', 20))->run($command);

            return ['ok' => $result->successful(), 'output' => $result->output().$result->errorOutput()];
        } catch (ProcessTimedOutException $e) {
            // e.g. waiting for Funnel to be allowed in the browser
            return ['ok' => false, 'output' => $e->result->output().$e->result->errorOutput()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }
}
