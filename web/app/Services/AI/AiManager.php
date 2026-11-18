<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\ProjectAiSetting;
use App\Services\AI\Contracts\EmbeddingProvider;
use App\Services\AI\Drivers\OllamaDriver;

/**
 * Entry point of the optional AI layer – text embeddings for autocoding,
 * nothing else. Resolves the configured embedding provider (Ollama) and
 * answers "may it be used for this project?".
 */
class AiManager
{
    public const EMBEDDING_PROVIDERS = ['ollama'];

    protected array $config;

    public function __construct(#[\SensitiveParameter] ?array $config = null)
    {
        $this->config = $config ?? (array) config('ai', []);
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * AI may be used for a project only if the owner opted in for exactly the
     * provider configuration that is active now (providers, models, hosts).
     */
    public function localOnly(): bool
    {
        return (bool) ($this->config['local_only'] ?? true);
    }

    /**
     * True when local-only mode forbids the configured providers.
     */
    public function blockedByLocalOnly(): bool
    {
        return $this->localOnly() && $this->usesCloud();
    }

    public function isEnabledForProject(Project $project): bool
    {
        return $this->isEnabled() && $this->consentState($project) === 'granted';
    }

    /**
     * @return string granted | none | config_changed | cloud_unacknowledged | blocked_local_only
     */
    public function consentState(Project $project, ?ProjectAiSetting $setting = null): string
    {
        if ($this->blockedByLocalOnly()) {
            return 'blocked_local_only';
        }
        $setting ??= ProjectAiSetting::for($project);
        if (! $setting || ! $setting->enabled) {
            return 'none';
        }
        if (! hash_equals((string) $setting->consent_fingerprint, $this->configFingerprint())) {
            return 'config_changed';
        }
        if ($this->usesCloud() && ! $setting->cloud_acknowledged_at) {
            return 'cloud_unacknowledged';
        }

        return 'granted';
    }

    /**
     * Identifies the configuration a consent refers to: provider, model,
     * dimensions, the exact endpoint, whether it counts as a third party,
     * the redaction mode and the version of the consent terms. Changing any
     * of them asks the project owner to confirm again.
     */
    public function configFingerprint(): string
    {
        $provider = $this->embeddingProviderName();

        return hash('sha256', json_encode([
            'v' => 2,
            'embedding' => [$provider, $this->embeddingModel(), $this->embeddingDimensions()],
            'endpoint' => $this->endpoint($provider),
            'cloud' => $this->isCloud($provider),
            'redaction' => $this->redactionMode(),
            'policy' => (int) ($this->config['consent_policy_version'] ?? 1),
        ]));
    }

    /** scheme://host:port/path of a provider, without credentials or query */
    public function endpoint(string $provider): string
    {
        $url = (string) ($this->config['providers'][$provider]['base_url'] ?? '');
        $parts = parse_url($url) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);

        return "{$scheme}://{$host}:{$port}".rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * True for this machine, private and link-local addresses (including
     * 100.64.0.0/10, used by Tailscale), single-label host names (container
     * or LAN names) and reserved internal suffixes, plus configured hosts.
     */
    public static function isPrivateHost(string $host, array $allow = []): bool
    {
        $host = strtolower(trim($host, '[] '));
        if ($host === '') {
            return false;
        }
        if (in_array($host, array_map('strtolower', $allow), true)) {
            return true;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $long = ip2long($host);
                if (($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                    return true;
                }
            }

            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (! str_contains($host, '.')) {
            return true;
        }
        foreach (['.local', '.internal', '.lan', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** whether a provider's endpoint is on this machine or a private network */
    public function endpointIsPrivate(string $provider): bool
    {
        $host = (string) (parse_url((string) ($this->config['providers'][$provider]['base_url'] ?? ''), PHP_URL_HOST) ?: '');

        return self::isPrivateHost($host, (array) ($this->config['local_hosts'] ?? []));
    }

    public function usesCloud(): bool
    {
        return $this->isCloud($this->embeddingProviderName());
    }

    public function redactionMode(): string
    {
        $mode = (string) ($this->config['redaction'] ?? 'cloud');

        return in_array($mode, ['cloud', 'always', 'off'], true) ? $mode : 'cloud';
    }

    /**
     * Redactor for text sent to the embedding provider of a project, or null
     * when no redaction applies.
     */
    public function redactorFor(Project $project): ?PiiRedactor
    {
        return $this->redactionActive()
            ? new PiiRedactor(ProjectAiSetting::for($project)?->terms() ?? [])
            : null;
    }

    public function redactionActive(): bool
    {
        $mode = $this->redactionMode();

        return $mode === 'always' || ($mode === 'cloud' && $this->isCloud($this->embeddingProviderName()));
    }

    public function embeddingProviderName(): string
    {
        return (string) ($this->config['embedding']['provider'] ?? 'ollama');
    }

    public function embeddingModel(): string
    {
        return (string) ($this->config['embedding']['model'] ?? '');
    }

    public function embeddingDimensions(): ?int
    {
        $dims = $this->config['embedding']['dimensions'] ?? null;

        return $dims ? (int) $dims : null;
    }

    public function embeddingBatchSize(): int
    {
        return max(1, (int) ($this->config['embedding']['batch_size'] ?? 64));
    }

    /**
     * A provider is a third-party service unless its endpoint is private;
     * the `cloud` flag can mark a private endpoint as third party, never the
     * other way round.
     */
    public function isCloud(string $provider): bool
    {
        if ((bool) ($this->config['providers'][$provider]['cloud'] ?? true)) {
            return true;
        }

        return ! $this->endpointIsPrivate($provider);
    }

    /**
     * Public, non-sensitive description of the configuration for the client.
     */
    public function describe(?Project $project = null): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'projectEnabled' => $project ? $this->isEnabledForProject($project) : false,
            'consent' => $project ? $this->consentState($project) : 'none',
            'cloud' => $this->usesCloud(),
            'localOnly' => $this->localOnly(),
            'redaction' => [
                'mode' => $this->redactionMode(),
                'active' => $this->redactionActive(),
            ],
            'embedding' => [
                'provider' => $this->embeddingProviderName(),
                'model' => $this->embeddingModel(),
                'dimensions' => $this->embeddingDimensions(),
                'cloud' => $this->isCloud($this->embeddingProviderName()),
                'endpointHost' => (string) (parse_url((string) ($this->config['providers'][$this->embeddingProviderName()]['base_url'] ?? ''), PHP_URL_HOST) ?: ''),
                'endpointPrivate' => $this->endpointIsPrivate($this->embeddingProviderName()),
                'available' => in_array($this->embeddingProviderName(), self::EMBEDDING_PROVIDERS, true),
            ],
        ];
    }

    public function embedder(): EmbeddingProvider
    {
        $name = $this->embeddingProviderName();
        $timeout = (int) ($this->config['timeout'] ?? 120);
        $model = $this->embeddingModel();

        $this->assertAllowed($name);

        $cfg = $this->providerConfig($name);

        $driver = match ($name) {
            'ollama' => new OllamaDriver($cfg, $timeout, $model),
            default => throw new AiException("Unknown embedding provider '{$name}'. Use one of: ".implode(', ', self::EMBEDDING_PROVIDERS).'.'),
        };

        return $driver->allowInsecureHttp((bool) ($this->config['allow_insecure_http'] ?? false));
    }

    public function limit(string $key, int $default): int
    {
        return (int) ($this->config['limits'][$key] ?? $default);
    }

    protected function assertAllowed(string $provider): void
    {
        if ($this->localOnly() && $this->isCloud($provider)) {
            throw new AiException("The '{$provider}' endpoint is a third-party service and AI_LOCAL_ONLY is on. Point OLLAMA_BASE_URL to an Ollama server on this machine or your network, or ask an administrator to set AI_LOCAL_ONLY=false.");
        }
    }

    protected function providerConfig(string $name): array
    {
        $cfg = $this->config['providers'][$name] ?? null;
        if (! is_array($cfg)) {
            throw new AiException("Provider '{$name}' is not configured.");
        }

        return $cfg;
    }
}
