<?php

namespace Tests\Unit;

use App\Services\AI\AiException;
use App\Services\AI\AiManager;
use App\Services\AI\EmbeddingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDriversTest extends TestCase
{
    private function manager(array $overrides = []): AiManager
    {
        $config = array_replace_recursive(require base_path('config/ai.php'), [
            'enabled' => true,
            'local_only' => false,
            'providers' => [
                'ollama' => ['base_url' => 'http://ollama:11434'],
            ],
        ], $overrides);

        return new AiManager($config);
    }

    public function test_ollama_embeddings(): void
    {
        Http::fake(['ollama:11434/api/embed' => Http::response(['embeddings' => [[0.1, 0.2], [0.3, 0.4]]])]);
        $ai = $this->manager(['embedding' => ['provider' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 2]]);

        $this->assertCount(2, $ai->embedder()->embed(['a', 'b'], 2));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://ollama:11434/api/embed' && $r['dimensions'] === 2 && $r['model'] === 'nomic-embed-text');
    }

    public function test_unknown_provider_is_reported(): void
    {
        $this->expectException(AiException::class);
        $this->manager(['embedding' => ['provider' => 'nonsense']])->embedder();
    }

    public function test_text_is_never_sent_over_plain_http_to_remote_hosts(): void
    {
        Http::fake();
        $ai = $this->manager([
            'embedding' => ['provider' => 'ollama', 'model' => 'x'],
            'providers' => ['ollama' => ['base_url' => 'http://ollama.example.com:11434']],
        ]);
        try {
            $ai->embedder()->embed(['x']);
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertStringContainsString('unencrypted HTTP', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_provider_error_messages_are_scrubbed_of_tokens(): void
    {
        Http::fake(['ollama:11434/*' => Http::response(['error' => 'Unauthorized: token sk-proj-abcdefghijklmnop123456 was rejected.'], 401)]);
        $ai = $this->manager(['embedding' => ['provider' => 'ollama', 'model' => 'x']]);
        try {
            $ai->embedder()->embed(['x']);
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertStringNotContainsString('abcdefghijklmnop', $e->getMessage());
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    public function test_local_only_mode_refuses_third_party_endpoints(): void
    {
        Http::fake();
        $ai = $this->manager([
            'local_only' => true,
            'embedding' => ['provider' => 'ollama'],
            'providers' => ['ollama' => ['base_url' => 'https://ollama.example.com']],
        ]);
        $this->assertTrue($ai->blockedByLocalOnly());
        try {
            $ai->embedder();
            $this->fail('expected exception');
        } catch (AiException $e) {
            $this->assertStringContainsString('AI_LOCAL_ONLY', $e->getMessage());
        }
        $this->assertFalse($this->manager(['local_only' => true, 'embedding' => ['provider' => 'ollama']])->blockedByLocalOnly());
        Http::assertNothingSent();
    }

    public function test_vectors_pack_roundtrip_and_normalisation(): void
    {
        $v = EmbeddingService::normalize([3.0, 4.0]);
        $this->assertEqualsWithDelta([0.6, 0.8], $v, 1e-9);
        $this->assertEqualsWithDelta($v, EmbeddingService::unpack(EmbeddingService::pack($v)), 1e-6);
    }
}
