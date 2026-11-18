<?php

namespace Tests\Controllers;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\CodeRelation;
use App\Models\Project;
use App\Models\User;
use App\Services\Qda\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VocabularyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setupProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $a = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Job loss']);
        $b = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Moving away']);

        return [$user, $project, $a, $b];
    }

    public function test_new_projects_start_with_the_neutral_defaults(): void
    {
        [$user, $project] = $this->setupProject();

        $response = $this->actingAs($user)->getJson(route('vocabulary.show', $project))->assertOk()
            ->assertJsonPath('customised', false)
            ->assertJsonPath('vocabulary.code_types.0.key', 'code');
        $this->assertSame(
            array_column(Vocabulary::defaults()['relation_types'], 'key'),
            array_column($response->json('vocabulary.relation_types'), 'key')
        );
    }

    public function test_keys_in_use_stay_readable(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $a->id, 'to_code_id' => $b->id, 'type' => 'causal_condition']);
        $a->update(['meta' => ['core' => true]]);

        $vocabulary = $this->actingAs($user)->getJson(route('vocabulary.show', $project))->json('vocabulary');
        $relation = collect($vocabulary['relation_types'])->firstWhere('key', 'causal_condition');
        $this->assertSame('Causal condition', $relation['label']);
        $this->assertSame('text', collect($vocabulary['code_fields'])->firstWhere('key', 'core')['type']);
    }

    public function test_researchers_define_their_own_vocabulary(): void
    {
        [$user, $project, $a] = $this->setupProject();
        $data = Vocabulary::defaults();
        $data['code_types'][] = ['key' => 'pattern_code', 'label' => 'Pattern code'];
        $data['relation_types'][] = ['key' => 'enables', 'label' => 'enables', 'short' => 'enables', 'directed' => true, 'valence' => true];
        $data['memo_types'][] = ['key' => 'field_note', 'label' => 'Field note', 'targets' => ['source'], 'template' => "Setting:\n"];
        $data['code_fields'][] = ['key' => 'salience', 'label' => 'Salience', 'type' => 'choice', 'options' => [['key' => 'high', 'label' => 'High'], ['key' => 'low', 'label' => 'Low']]];

        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)->assertOk()
            ->assertJsonPath('customised', true)
            ->assertJsonPath('version', 1);

        // the new entries are accepted everywhere
        $this->actingAs($user)->patchJson(route('coding.update-attribute', [$project, $a]), [
            'kind' => 'pattern_code', 'meta' => ['salience' => 'high'],
        ])->assertOk();
        $this->assertSame('pattern_code', $a->fresh()->kind);
        $this->assertSame('high', $a->fresh()->meta['salience']);
        $this->actingAs($user)->patchJson(route('coding.update-attribute', [$project, $a]), ['meta' => ['salience' => 'medium']])
            ->assertUnprocessable();
    }

    public function test_entries_in_use_can_be_archived_but_not_removed(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $a->id, 'to_code_id' => $b->id, 'type' => 'leads_to']);
        $data = Vocabulary::defaults();
        $data['relation_types'] = array_values(array_filter($data['relation_types'], fn ($t) => $t['key'] !== 'leads_to'));

        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)
            ->assertUnprocessable()
            ->assertJsonPath('errors.in_use.relation_types.0', 'leads_to');

        $data = Vocabulary::defaults();
        $data['relation_types'][0]['archived'] = true;
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)->assertOk()
            ->assertJsonPath('vocabulary.relation_types.0.archived', true);
    }

    public function test_invalid_vocabularies_are_rejected(): void
    {
        [$user, $project] = $this->setupProject();
        $bad = Vocabulary::defaults();
        $bad['code_fields'][] = ['key' => 'mood', 'label' => 'Mood', 'type' => 'choice', 'options' => []];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $bad)->assertUnprocessable();

        $bad = Vocabulary::defaults();
        $bad['code_types'][] = ['key' => 'Bad Key!', 'label' => 'x'];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $bad)->assertUnprocessable();

        $bad = Vocabulary::defaults();
        $bad['code_fields'][] = ['key' => 'definition', 'label' => 'Clash', 'type' => 'text'];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $bad)->assertUnprocessable();
    }

    public function test_only_project_members_see_or_change_it_and_reset_returns_to_defaults(): void
    {
        [$user, $project] = $this->setupProject();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson(route('vocabulary.show', $project))->assertForbidden();
        $this->actingAs($outsider)->putJson(route('vocabulary.update', $project), Vocabulary::defaults())->assertForbidden();

        $data = Vocabulary::defaults();
        $data['statuses'][] = ['key' => 'saturated', 'label' => 'Saturated'];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)->assertOk();
        $this->actingAs($user)->deleteJson(route('vocabulary.reset', $project))->assertOk()
            ->assertJsonPath('customised', false);
        $this->assertDatabaseCount('project_vocabularies', 0);
    }

    public function test_reset_keeps_custom_entries_that_are_in_use(): void
    {
        [$user, $project] = $this->setupProject();
        $data = Vocabulary::defaults();
        $data['code_types'][] = ['key' => 'pattern', 'label' => 'Pattern code', 'description' => 'mine'];
        $data['statuses'][] = ['key' => 'saturated', 'label' => 'Saturated'];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)->assertOk();
        $codebook = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $user->id]);
        Code::factory()->create(['codebook_id' => $codebook->id, 'kind' => 'pattern']);

        $response = $this->actingAs($user)->deleteJson(route('vocabulary.reset', $project))->assertOk();
        $types = collect($response->json('vocabulary.code_types'));
        $this->assertSame('Pattern code', $types->firstWhere('key', 'pattern')['label']);
        $this->assertNull(collect($response->json('vocabulary.statuses'))->firstWhere('key', 'saturated'));
        $response->assertJsonPath('customised', true);
    }
}
