<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\EmbeddingProvider;

/**
 * Local embedding models through Ollama (https://ollama.com). Data stays on your server.
 */
class OllamaDriver extends HttpDriver implements EmbeddingProvider
{
    public function __construct(#[\SensitiveParameter] array $config, int $timeout, protected ?string $embeddingModel = null)
    {
        parent::__construct($config, $timeout);
    }

    public function embed(array $texts, ?int $dimensions = null): array
    {
        $body = [
            'model' => $this->embeddingModel,
            'input' => array_values($texts),
            'truncate' => true,
        ];
        if ($dimensions) {
            $body['dimensions'] = $dimensions;
        }

        $json = $this->post($this->client(), $this->baseUrl().'/api/embed', $body, 'Ollama');

        return $this->assertVectors($json['embeddings'] ?? [], count($texts), 'Ollama');
    }
}
