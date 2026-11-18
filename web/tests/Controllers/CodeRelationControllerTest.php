<?php

namespace Tests\Controllers;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\CodeRelation;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use App\Services\Qda\Vocabulary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeRelationControllerTest extends TestCase
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

    public function test_create_list_update_delete(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();

        $id = $this->actingAs($user)->postJson(route('code-relations.store', $project), [
            'from' => $a->id, 'to' => $b->id, 'type' => 'leads_to', 'valence' => 1,
        ])->assertCreated()
            ->assertJsonPath('relation.directed', true)
            ->assertJsonPath('relation.valence', 1)
            ->json('relation.id');

        $this->actingAs($user)->getJson(route('code-relations.index', $project))
            ->assertOk()
            ->assertJsonPath('relations.0.from', $a->id);

        $this->actingAs($user)->patchJson(route('code-relations.update', [$project, $id]), ['valence' => -1, 'description' => 'pushes people out'])
            ->assertOk()
            ->assertJsonPath('relation.valence', -1);

        $this->actingAs($user)->deleteJson(route('code-relations.destroy', [$project, $id]))->assertOk();
        $this->assertDatabaseCount('code_relations', 0);
    }

    public function test_undirected_types_default_to_undirected(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();

        $this->actingAs($user)->postJson(route('code-relations.store', $project), [
            'from' => $a->id, 'to' => $b->id, 'type' => 'contradicts',
        ])->assertCreated()->assertJsonPath('relation.directed', false);
    }

    public function test_rejects_self_links_duplicates_foreign_codes_and_unknown_types(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        $foreign = Code::factory()->create();

        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $a->id, 'type' => 'leads_to'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $foreign->id, 'type' => 'leads_to'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'loves'])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'custom'])
            ->assertUnprocessable(); // custom needs a label

        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'condition_for'])
            ->assertCreated();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'condition_for'])
            ->assertUnprocessable();
    }

    public function test_outsiders_cannot_touch_relations(): void
    {
        [, $project, $a, $b] = $this->setupProject();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson(route('code-relations.index', $project))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'leads_to'])
            ->assertForbidden();
    }

    public function test_deleting_a_code_removes_its_relations_and_their_memos(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        $relation = CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $a->id, 'to_code_id' => $b->id, 'type' => 'leads_to']);
        Note::factory()->create([
            'project_id' => $project->id, 'creating_user_id' => $user->id,
            'type' => 'relation', 'target' => $relation->id,
        ]);

        $this->actingAs($user)->deleteJson(route('coding.destroy', [$project, $a]))->assertOk();

        $this->assertDatabaseCount('code_relations', 0);
        $this->assertDatabaseMissing('notes', ['type' => 'relation', 'target' => $relation->id]);
    }

    public function test_code_analytic_details_can_be_updated(): void
    {
        [$user, $project, $a] = $this->setupProject();

        $this->actingAs($user)->patchJson(route('coding.update-attribute', [$project, $a]), [
            'kind' => 'category',
            'meta' => [
                'definition' => 'Losing paid work',
                'origin' => 'emergent',
                'status' => 'developing',
                'core' => true,
                'properties' => [['name' => 'duration', 'low' => 'short', 'high' => 'long']],
                'unknown' => 'dropped',
            ],
        ])->assertOk();

        $a->refresh();
        $this->assertSame('category', $a->kind);
        $this->assertSame('Losing paid work', $a->meta['definition']);
        $this->assertSame('emergent', $a->meta['origin']);
        $this->assertSame('developing', $a->meta['status']);
        $this->assertSame('duration', $a->meta['properties'][0]['name']);
        // "core" is not a field of this project's vocabulary, so it is dropped
        $this->assertArrayNotHasKey('core', $a->meta);
        $this->assertArrayNotHasKey('unknown', $a->meta);

        $this->actingAs($user)->patchJson(route('coding.update-attribute', [$project, $a]), ['kind' => 'nonsense'])
            ->assertUnprocessable();
    }

    public function test_notes_accept_memo_types_and_relation_targets(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        $relation = CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $a->id, 'to_code_id' => $b->id, 'type' => 'leads_to']);

        $this->actingAs($user)->postJson(route('notes.store', $project), [
            'content' => 'Why does job loss lead to moving?',
            'target' => $relation->id,
            'type' => 'relation',
            'memo_type' => 'decision',
            'visibility' => 1,
        ])->assertCreated()->assertJsonPath('note.memo_type', 'decision');

        $this->actingAs($user)->postJson(route('notes.store', $project), [
            'content' => 'x', 'target' => $relation->id, 'type' => 'relation', 'memo_type' => 'gossip',
        ])->assertUnprocessable();
    }

    public function test_the_database_allows_one_relationship_per_pair_and_type(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        $post = fn (array $body) => $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, ...$body]);

        // undirected types count once per pair, in either order
        $post(['type' => 'associated_with'])->assertCreated();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $b->id, 'to' => $a->id, 'type' => 'associated_with'])
            ->assertUnprocessable();
        // directed types may go both ways
        $post(['type' => 'leads_to'])->assertCreated();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $b->id, 'to' => $a->id, 'type' => 'leads_to'])
            ->assertCreated();
        // custom relationships are told apart by label (case-insensitive)
        $post(['type' => 'custom', 'label' => 'enables'])->assertCreated();
        $post(['type' => 'custom', 'label' => ' Enables '])->assertUnprocessable();
        $post(['type' => 'custom', 'label' => 'blocks'])->assertCreated();

        // the constraint holds even when the validation is bypassed
        $this->expectException(UniqueConstraintViolationException::class);
        CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $b->id, 'to_code_id' => $a->id, 'type' => 'associated_with', 'directed' => false]);
    }

    public function test_direction_comes_from_the_type_and_updates_cannot_create_duplicates(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        // a client cannot make a directed type undirected
        $id = $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'leads_to', 'directed' => false])
            ->assertCreated()->assertJsonPath('relation.directed', true)->json('relation.id');
        // custom relationships choose
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'custom', 'label' => 'mirrors', 'directed' => false])
            ->assertCreated()->assertJsonPath('relation.directed', false);
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $a->id, 'to' => $b->id, 'type' => 'contradicts'])->assertCreated();

        // changing the type changes the direction; turning into an existing one is refused
        $this->actingAs($user)->patchJson(route('code-relations.update', [$project, $id]), ['type' => 'contradicts'])->assertUnprocessable();
        $this->actingAs($user)->patchJson(route('code-relations.update', [$project, $id]), ['type' => 'associated_with'])
            ->assertOk()->assertJsonPath('relation.directed', false);
        $this->actingAs($user)->patchJson(route('code-relations.update', [$project, $id]), ['type' => 'custom'])->assertUnprocessable();
        $this->actingAs($user)->patchJson(route('code-relations.update', [$project, $id]), ['type' => 'custom', 'label' => 'MIRRORS', 'directed' => false])->assertUnprocessable();
    }

    public function test_relations_and_their_memos_stay_inside_their_project(): void
    {
        [$user, $project, $a, $b] = $this->setupProject();
        $other = Project::factory()->create(['creating_user_id' => $user->id]); // same owner
        $otherCode = Code::factory()->create(['codebook_id' => Codebook::factory()->create(['project_id' => $other->id])->id]);
        $relation = CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $a->id, 'to_code_id' => $b->id, 'type' => 'leads_to']);

        // through the other project's routes the relation does not exist
        $this->actingAs($user)->patchJson(route('code-relations.update', [$other, $relation->id]), ['valence' => 1])->assertNotFound();
        $this->actingAs($user)->deleteJson(route('code-relations.destroy', [$other, $relation->id]))->assertNotFound();
        $this->actingAs($user)->getJson(route('code-relations.index', $other))->assertOk()->assertJsonCount(0, 'relations');
        // codes of another project cannot be linked, in either position
        $this->actingAs($user)->postJson(route('code-relations.store', $other), ['from' => $a->id, 'to' => $otherCode->id, 'type' => 'leads_to'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('code-relations.store', $project), ['from' => $otherCode->id, 'to' => $b->id, 'type' => 'leads_to'])->assertUnprocessable();
        // a memo cannot point at a relation of another project, or at none
        $this->actingAs($user)->postJson(route('notes.store', $other), ['content' => 'x', 'target' => $relation->id, 'type' => 'relation'])
            ->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'x', 'target' => 'not-a-relation', 'type' => 'relation'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('code_relations', ['id' => $relation->id, 'valence' => null]);
    }

    public function test_memo_types_are_limited_to_their_targets(): void
    {
        [$user, $project, $a] = $this->setupProject();
        $data = Vocabulary::defaults();
        $data['memo_types'][] = ['key' => 'code_definition', 'label' => 'Definition note', 'targets' => ['code']];
        $this->actingAs($user)->putJson(route('vocabulary.update', $project), $data)->assertOk();

        $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'x', 'target' => (string) $project->id, 'type' => 'project', 'memo_type' => 'code_definition'])
            ->assertUnprocessable()->assertJsonValidationErrors('memo_type');
        $id = $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'x', 'target' => $a->id, 'type' => 'code', 'memo_type' => 'code_definition'])
            ->assertCreated()->json('note.id');
        $projectNote = $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'y', 'target' => (string) $project->id, 'type' => 'project', 'memo_type' => 'reflexive'])
            ->assertCreated()->json('note.id');
        $this->actingAs($user)->patchJson(route('notes.update', [$project, $projectNote]), ['memo_type' => 'code_definition'])->assertUnprocessable();
        $this->actingAs($user)->patchJson(route('notes.update', [$project, $id]), ['memo_type' => 'reflexive'])->assertOk();
    }
}
