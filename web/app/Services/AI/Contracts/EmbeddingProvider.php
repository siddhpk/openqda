<?php

namespace App\Services\AI\Contracts;

interface EmbeddingProvider
{
    /**
     * Embed a batch of texts.
     *
     * @param  string[]  $texts
     * @return array<int, float[]> one vector per input text, same order
     */
    public function embed(array $texts, ?int $dimensions = null): array;
}
