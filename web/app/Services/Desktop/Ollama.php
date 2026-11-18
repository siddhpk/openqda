<?php

namespace App\Services\Desktop;

use Illuminate\Support\Facades\Http;

/**
 * Talks to a local Ollama (https://ollama.com) for the laptop edition's
 * "Autocoding (AI)" settings: is it running, which models are installed,
 * download an embedding model. Only ever contacts this computer.
 */
class Ollama
{
    public const DEFAULT_URL = 'http://127.0.0.1:11434';

    /** Embedding models offered on the settings page (name => description). */
    public const MODELS = [
        'nomic-embed-text' => 'English – recommended (274 MB)',
        'all-minilm' => 'English – very small, for older computers (46 MB)',
        'qwen3-embedding:0.6b' => 'Many languages (640 MB)',
        'bge-m3' => 'Many languages – larger (1.2 GB)',
    ];

    /**
     * @return array{running: bool, models: string[]}
     */
    public function status(string $url = self::DEFAULT_URL): array
    {
        try {
            $response = Http::timeout(3)->connectTimeout(2)->get(rtrim($url, '/').'/api/tags');
            if (! $response->ok()) {
                return ['running' => false, 'models' => []];
            }

            return ['running' => true, 'models' => collect($response->json('models', []))->pluck('name')->filter()->values()->all()];
        } catch (\Throwable) {
            return ['running' => false, 'models' => []];
        }
    }

    public static function installed(array $models, string $model): bool
    {
        $base = fn (string $name) => str_ends_with($name, ':latest') ? substr($name, 0, -7) : $name;

        return in_array($base($model), array_map($base, $models), true);
    }

    /**
     * Download a model; can take several minutes for larger models.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function pull(string $model, string $url = self::DEFAULT_URL): array
    {
        @set_time_limit(0);
        try {
            $response = Http::timeout(1800)->connectTimeout(3)
                ->post(rtrim($url, '/').'/api/pull', ['model' => $model, 'stream' => false]);
            if ($response->ok() && $response->json('status') === 'success') {
                return ['ok' => true, 'message' => null];
            }

            return ['ok' => false, 'message' => 'Ollama could not download the model: '.mb_substr((string) ($response->json('error') ?? $response->body()), 0, 200)];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Ollama is not reachable. Start the Ollama app and try again.'];
        }
    }
}
