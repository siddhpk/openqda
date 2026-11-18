<?php

namespace App\Services\AI;

use App\Models\AiEmbedding;
use App\Models\Project;
use Illuminate\Support\Facades\Crypt;

/**
 * Embeds texts through the configured provider and caches the vectors.
 *
 * Data safety:
 *  - the cache is scoped per project (the project id is part of the key), so
 *    one project can never learn whether a text exists in another project;
 *  - text is pseudonymised (PiiRedactor) before hashing and before it leaves
 *    the server, depending on the redaction mode;
 *  - vectors are encrypted at rest with the application key (configurable)
 *    and the cache holds no plain text; it is pruned after a TTL and purged
 *    when a project disables AI or is deleted.
 *
 * Vectors are returned as packed little-endian float32 binary strings.
 */
class EmbeddingService
{
    public function __construct(protected AiManager $ai) {}

    public function cacheKey(Project $project, string $text): string
    {
        return hash('sha256', implode('|', [
            'p'.$project->id,
            $this->ai->embeddingProviderName(),
            $this->ai->embeddingModel(),
            (string) ($this->ai->embeddingDimensions() ?? 'native'),
            $text,
        ]));
    }

    /**
     * Embeds all texts; vectors are packed float32 binary strings in input order.
     *
     * @param  string[]  $texts
     * @return array{vectors: string[], dimensions: int, cached: int, characters: int, redactions: int}
     */
    public function embedMany(Project $project, array $texts): array
    {
        $redactor = $this->ai->redactorFor($project);
        $texts = array_values(array_map('strval', $texts));
        $outgoing = $redactor ? $redactor->redactAll($texts) : $texts;
        $keys = array_map(fn ($t) => $this->cacheKey($project, $t), $outgoing);

        $found = AiEmbedding::where('project_id', $project->id)
            ->whereIn('hash', array_unique($keys))
            ->get(['hash', 'vector', 'dimensions'])
            ->keyBy('hash');

        $missing = [];
        foreach ($keys as $i => $key) {
            if (! $found->has($key) && ! isset($missing[$key])) {
                $missing[$key] = $outgoing[$i];
            }
        }

        $computed = [];
        $characters = 0;
        if ($missing) {
            $embedder = $this->ai->embedder();
            foreach (array_chunk($missing, $this->ai->embeddingBatchSize(), true) as $chunk) {
                $characters += array_sum(array_map('mb_strlen', $chunk));
                $wanted = $this->ai->embeddingDimensions();
                $vectors = $embedder->embed(array_values($chunk), $wanted);
                $rows = [];
                foreach (array_keys($chunk) as $j => $key) {
                    // Matryoshka truncation also when a server ignores the dimensions parameter
                    $vector = self::normalize($wanted ? array_slice($vectors[$j], 0, $wanted) : $vectors[$j]);
                    $packed = self::pack($vector);
                    $computed[$key] = ['vector' => $packed, 'dimensions' => count($vector)];
                    $rows[] = [
                        'project_id' => $project->id,
                        'hash' => $key,
                        'provider' => $this->ai->embeddingProviderName(),
                        'model' => $this->ai->embeddingModel(),
                        'dimensions' => count($vector),
                        'vector' => $this->seal($packed),
                        'created_at' => now(),
                    ];
                }
                AiEmbedding::insertOrIgnore($rows);
            }
        }

        $out = [];
        $dims = 0;
        foreach ($keys as $key) {
            if (isset($computed[$key])) {
                $entry = $computed[$key];
            } else {
                $entry = ['vector' => $this->open($found[$key]->vector), 'dimensions' => (int) $found[$key]->dimensions];
            }
            $out[] = $entry['vector'];
            $dims = $dims ?: (int) $entry['dimensions'];
        }

        return [
            'vectors' => $out,
            'dimensions' => $dims,
            'cached' => count($texts) - count($missing),
            'characters' => $characters,
            'redactions' => $redactor?->count() ?? 0,
        ];
    }

    /**
     * Deletes all cached embeddings of a project.
     */
    public function purge(Project $project): int
    {
        return AiEmbedding::where('project_id', $project->id)->delete();
    }

    protected function seal(string $packed): string
    {
        $encoded = base64_encode($packed);

        return config('ai.cache.encrypt', true) ? 'enc:'.Crypt::encryptString($encoded) : 'raw:'.$encoded;
    }

    protected function open(string $stored): string
    {
        if (str_starts_with($stored, 'enc:')) {
            return base64_decode(Crypt::decryptString(substr($stored, 4)));
        }

        return base64_decode(str_starts_with($stored, 'raw:') ? substr($stored, 4) : $stored);
    }

    /**
     * L2-normalise so that cosine similarity == dot product downstream.
     *
     * @param  float[]  $v
     * @return float[]
     */
    public static function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $v)));
        if ($norm <= 0.0) {
            return $v;
        }

        return array_map(static fn ($x) => $x / $norm, $v);
    }

    /**
     * @param  float[]  $v
     */
    public static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    /**
     * @return float[]
     */
    public static function unpack(string $binary): array
    {
        return array_values(unpack('g*', $binary));
    }
}
