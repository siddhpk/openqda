<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\AiException;
use App\Services\AI\AiManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared HTTP plumbing for the embedding driver.
 *
 * Safety rules enforced here:
 *  - project text is only sent over HTTPS (except to loopback/private hosts)
 *  - every failure becomes an AiException with a message that never contains
 *    tokens, request content or stack data
 *  - the config is marked #[SensitiveParameter] so it is redacted from stack
 *    traces
 */
abstract class HttpDriver
{
    protected array $config;

    public function __construct(
        #[\SensitiveParameter] array $config,
        protected int $timeout = 120,
        protected bool $allowInsecureHttp = false,
    ) {
        $this->config = $config;
    }

    public function allowInsecureHttp(bool $allow): static
    {
        $this->allowInsecureHttp = $allow;

        return $this;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    protected function client(): PendingRequest
    {
        return Http::timeout($this->timeout)->acceptJson()->asJson()->withoutRedirecting();
    }

    protected function assertTransportIsSafe(string $url, string $provider): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new AiException("Invalid base URL for provider '{$provider}'.");
        }
        if ($scheme === 'http' && ! $this->allowInsecureHttp && ! AiManager::isPrivateHost($host)) {
            throw new AiException("Refusing to send data to '{$provider}' over unencrypted HTTP. Use an https:// base URL.");
        }
    }

    /**
     * Removes anything that looks like a credential, e.g. from a reverse proxy
     * in front of the embedding server.
     */
    public function scrub(string $message): string
    {
        // error messages sometimes echo (partial) keys or bearer tokens
        $message = preg_replace('/\b(sk|pa|ak|key|tok)[-_][A-Za-z0-9_\-*.]{6,}/i', '***', $message) ?? '';
        $message = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '***', $message) ?? '';

        return mb_substr($message, 0, 200);
    }

    /**
     * POST and return the decoded JSON body, converting failures into AiExceptions
     * that never leak request content or credentials.
     */
    protected function post(PendingRequest $client, string $url, array $body, string $provider): array
    {
        $this->assertTransportIsSafe($url, $provider);

        try {
            /** @var Response $response */
            $response = $client->post($url, $body);
        } catch (Throwable) {
            throw new AiException("Could not reach the {$provider} service. Check its base URL and network.");
        }

        if ($response->failed()) {
            $detail = '';
            try {
                $detail = $response->json('error.message') ?? $response->json('error') ?? '';
            } catch (Throwable) {
                $detail = '';
            }
            $detail = is_string($detail) ? $this->scrub($detail) : '';
            throw new AiException(trim("{$provider} request failed ({$response->status()}). {$detail}"));
        }

        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }
        if (! is_array($json)) {
            throw new AiException("{$provider} returned an unexpected response.");
        }

        return $json;
    }

    /**
     * @param  array<int, mixed>  $vectors
     * @return array<int, float[]>
     */
    protected function assertVectors(array $vectors, int $expected, string $provider): array
    {
        if (count($vectors) !== $expected) {
            throw new AiException("{$provider} returned ".count($vectors)." embeddings for {$expected} texts.");
        }

        return array_map(static fn ($v) => array_map('floatval', (array) $v), array_values($vectors));
    }
}
