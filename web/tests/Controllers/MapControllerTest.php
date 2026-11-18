<?php

namespace Tests\Controllers;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\CodeRelation;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_page_renders_tree_relations_and_notes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $parent = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Migration', 'kind' => 'theme']);
        $child = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Leaving', 'parent_id' => $parent->id]);
        CodeRelation::create(['project_id' => $project->id, 'from_code_id' => $child->id, 'to_code_id' => $parent->id, 'type' => 'consequence']);
        Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $user->id, 'type' => 'code', 'target' => $parent->id, 'visibility' => 0]);

        $this->withoutVite();
        $this->actingAs($user)->get(route('map.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MapPage', false)
                ->has('allCodes', 1)
                ->where('allCodes.0.name', 'Migration')
                ->where('allCodes.0.kind', 'theme')
                ->where('allCodes.0.children.0.name', 'Leaving')
                ->has('relations', 1)
                ->has('notes', 1));
    }

    public function test_map_import_creates_codebook_codes_and_notes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('map.import', $project), [
            'name' => 'Imported map',
            'nodes' => [[
                'name' => 'Root idea', 'color' => '#ff0000', 'note' => 'A note',
                'children' => [['name' => 'Child A'], ['name' => 'Child B', 'children' => [['name' => 'Grandchild']]]],
            ]],
        ])->assertCreated()->assertJsonPath('count', 4);

        $codebook = Codebook::where('name', 'Imported map')->firstOrFail();
        $this->assertSame(4, Code::where('codebook_id', $codebook->id)->count());
        $root = Code::where('name', 'Root idea')->firstOrFail();
        $this->assertSame('#ff0000', $root->color);
        $this->assertSame(2, $root->children()->count());
        $this->assertDatabaseHas('notes', ['target' => $root->id, 'type' => 'code', 'memo_type' => 'code_note']);
    }

    public function test_map_import_rejects_invalid_nodes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('map.import', $project), ['name' => 'x', 'nodes' => [['name' => '']]])
            ->assertUnprocessable();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson(route('map.import', $project), ['name' => 'x', 'nodes' => [['name' => 'a']]])
            ->assertForbidden();
    }
}
