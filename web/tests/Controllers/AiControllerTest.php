<?php

namespace Tests\Controllers;

use App\Models\AiEmbedding;
use App\Models\AiUsageLog;
use App\Models\Project;
use App\Models\ProjectAiSetting;
use App\Models\User;
use App\Services\AI\AiManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    private function enableAi(array $overrides = []): void
    {
        config(array_merge([
            'ai.enabled' => true,
            'ai.embedding.provider' => 'ollama',
            'ai.embedding.model' => 'nomic-embed-text',
            'ai.providers.ollama.base_url' => 'http://ollama:11434',
            'ai.providers.ollama.cloud' => false,
            'ai.redaction' => 'cloud',
            'ai.local_only' => true,
        ], $overrides));
    }

    private function projectWithOwner(bool $optIn = false): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $owner->id]);
        if ($optIn) {
            $ai = new AiManager;
            ProjectAiSetting::create([
                'project_id' => $project->id,
                'enabled' => true,
                'consent_fingerprint' => $ai->configFingerprint(),
                'cloud_acknowledged_at' => $ai->usesCloud() ? now() : null,
                'changed_by' => $owner->id,
            ]);
        }

        return [$owner, $project];
    }

    public function test_everything_is_inert_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        Http::fake();
        [$owner, $project] = $this->projectWithOwner(true);

        $this->actingAs($owner)->getJson(route('ai.status', $project))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('projectEnabled', false);

        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['x y z']])->assertNotFound();
        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_project_must_be_opted_in(): void
    {
        $this->enableAi();
        Http::fake();
        [$owner, $project] = $this->projectWithOwner(false);

        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['hello there']])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_owner_can_change_consent(): void
    {
        $this->enableAi();
        [$owner, $project] = $this->projectWithOwner(false);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson(route('ai.consent', $project), ['enabled' => true])->assertForbidden();
        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('projectEnabled', true)
            ->assertJsonPath('consentState', 'granted');
    }

    public function test_third_party_endpoints_require_an_explicit_acknowledgement(): void
    {
        $this->enableAi(['ai.local_only' => false, 'ai.providers.ollama.base_url' => 'https://ollama.example.com']);
        [$owner, $project] = $this->projectWithOwner(false);

        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => true])->assertUnprocessable();
        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => true, 'acknowledge_cloud' => true])
            ->assertOk()
            ->assertJsonPath('projectEnabled', true);
        $this->assertNotNull(ProjectAiSetting::for($project)->cloud_acknowledged_at);
    }

    public function test_local_only_mode_blocks_third_party_endpoints(): void
    {
        $this->enableAi(['ai.providers.ollama.base_url' => 'https://ollama.example.com']);
        Http::fake();
        [$owner, $project] = $this->projectWithOwner(false);

        $this->actingAs($owner)->getJson(route('ai.status', $project))
            ->assertJsonPath('localOnly', true)
            ->assertJsonPath('consentState', 'blocked_local_only');
        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => true, 'acknowledge_cloud' => true])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_changing_the_provider_configuration_revokes_consent(): void
    {
        $this->enableAi(['ai.local_only' => false]);
        Http::fake();
        [$owner, $project] = $this->projectWithOwner(true);

        config(['ai.embedding.model' => 'all-minilm']);

        $this->actingAs($owner)->getJson(route('ai.status', $project))
            ->assertJsonPath('projectEnabled', false)
            ->assertJsonPath('consentState', 'config_changed');
        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['hello there']])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_embeddings_are_project_scoped_encrypted_and_logged(): void
    {
        $this->enableAi();
        Http::fake(['ollama:11434/api/embed' => Http::response(['embeddings' => [[3.0, 4.0]]])]);
        [$owner, $project] = $this->projectWithOwner(true);
        [$owner2, $project2] = $this->projectWithOwner(true);

        $response = $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['same text']])
            ->assertOk()
            ->assertJsonPath('dimensions', 2)
            ->assertJsonMissingPath('cached');
        $this->assertEqualsWithDelta([0.6, 0.8], array_values(unpack('g*', base64_decode($response->json('vectors.0')))), 1e-6);

        // encrypted at rest
        $stored = DB::table('ai_embeddings')->value('vector');
        $this->assertStringStartsWith('enc:', $stored);

        // the same text in another project is not served from the first project's cache
        $this->actingAs($owner2)->postJson(route('ai.embed', $project2), ['texts' => ['same text']])->assertOk();
        Http::assertSentCount(2);
        $this->assertSame(2, AiEmbedding::count());

        // a repeated call in the same project is cached
        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['same text']])->assertOk();
        Http::assertSentCount(2);

        // usage is logged as counts only
        $log = AiUsageLog::where('project_id', $project->id)->first();
        $this->assertSame('embed', $log->action);
        $this->assertSame(9, (int) $log->characters);
        $this->assertStringNotContainsString('same text', json_encode(AiUsageLog::all()->toArray()));
    }

    public function test_vectors_are_truncated_to_the_configured_dimensions(): void
    {
        $this->enableAi();
        config(['ai.embedding.dimensions' => 2]);
        // a server that ignores the dimensions parameter and returns the full vector
        Http::fake(['ollama:11434/api/embed' => Http::response(['embeddings' => [[3.0, 4.0, 12.0]]])]);
        [$owner, $project] = $this->projectWithOwner(true);

        $response = $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['short']])
            ->assertOk()
            ->assertJsonPath('dimensions', 2);
        $this->assertEqualsWithDelta([0.6, 0.8], array_values(unpack('g*', base64_decode($response->json('vectors.0')))), 1e-6);
        Http::assertSent(fn ($request) => $request['dimensions'] === 2);
    }

    public function test_text_for_third_party_endpoints_is_pseudonymised(): void
    {
        $this->enableAi(['ai.local_only' => false, 'ai.providers.ollama.base_url' => 'https://ollama.example.com']);
        Http::fake(['ollama.example.com/*' => Http::response(['embeddings' => [[1.0, 0.0]]])]);
        [$owner, $project] = $this->projectWithOwner(true);
        ProjectAiSetting::for($project)->update(['redaction_terms' => ['Anna']]);

        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['Anna wrote to anna@example.org']])->assertOk();

        Http::assertSent(fn (Request $r) => $r['input'] === ['[REDACTED] wrote to [EMAIL]']);
        $this->assertSame(2, (int) AiUsageLog::first()->redactions);
    }

    public function test_redaction_terms_are_owner_only_encrypted_and_not_audited(): void
    {
        $this->enableAi();
        [$owner, $project] = $this->projectWithOwner(true);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->putJson(route('ai.redaction-terms', $project), ['terms' => ['Secretname']])->assertForbidden();
        $this->actingAs($owner)->putJson(route('ai.redaction-terms', $project), ['terms' => ['Secretname', 'Bremen-Nord']])
            ->assertOk()
            ->assertJsonPath('redactionTermsCount', 2)
            ->assertJsonPath('redactionTerms.0', 'Secretname');

        $raw = DB::table('project_ai_settings')->where('project_id', $project->id)->value('redaction_terms');
        $this->assertStringNotContainsString('Secretname', $raw);
        $this->assertStringNotContainsString('Secretname', json_encode(DB::table('audits')->get()));
    }

    public function test_disabling_ai_purges_cached_embeddings(): void
    {
        $this->enableAi();
        Http::fake(['ollama:11434/api/embed' => Http::response(['embeddings' => [[1.0, 0.0]]])]);
        [$owner, $project] = $this->projectWithOwner(true);
        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['text']])->assertOk();
        $this->assertSame(1, AiEmbedding::count());

        $this->actingAs($owner)->postJson(route('ai.consent', $project), ['enabled' => false])->assertOk();
        $this->assertSame(0, AiEmbedding::count());
    }

    public function test_provider_errors_are_reported_without_crashing(): void
    {
        $this->enableAi();
        Http::fake(['ollama:11434/*' => Http::response('boom', 500)]);
        [$owner, $project] = $this->projectWithOwner(true);

        $this->actingAs($owner)->postJson(route('ai.embed', $project), ['texts' => ['Some passage of text']])
            ->assertStatus(502)
            ->assertJsonStructure(['message']);
        $this->assertSame(0, AiUsageLog::count());
    }

    public function test_non_members_cannot_use_ai(): void
    {
        $this->enableAi();
        Http::fake();
        [, $project] = $this->projectWithOwner(true);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson(route('ai.embed', $project), ['texts' => ['hello there']])->assertForbidden();
        $this->actingAs($outsider)->getJson(route('ai.status', $project))->assertForbidden();
        Http::assertNothingSent();
    }
}
