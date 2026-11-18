<?php

namespace Tests\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ResearchDesignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setupProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);

        return [$user, $project];
    }

    public function test_the_page_shows_an_empty_design_without_methodology_profiles(): void
    {
        [$user, $project] = $this->setupProject();
        $this->withoutVite();

        $this->actingAs($user)->get(route('design.show', [$project, 'tab' => 'vocabulary']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ResearchDesignPage')
                ->where('design.version', 0)
                ->where('design.approach', null)
                ->where('tab', 'vocabulary')
                ->has('vocabulary.code_types')
                ->missing('methodology'));
    }

    public function test_every_change_is_versioned_with_author_and_reason(): void
    {
        [$user, $project] = $this->setupProject();

        $this->actingAs($user)->putJson(route('design.update', $project), [
            'questions' => 'How do people experience moving cities after job loss?',
            'approach' => 'Exploratory; we will decide how far to abstract once we know the data.',
        ])->assertOk()->assertJsonPath('design.version', 1)->assertJsonCount(0, 'revisions');

        // saving the same content again does not create a new version
        $this->actingAs($user)->putJson(route('design.update', $project), [
            'questions' => 'How do people experience moving cities after job loss?',
            'approach' => 'Exploratory; we will decide how far to abstract once we know the data.',
        ])->assertOk()->assertJsonPath('design.version', 1);

        $response = $this->actingAs($user)->putJson(route('design.update', $project), [
            'questions' => 'How do people experience moving cities after job loss?',
            'approach' => 'Building an explanation of why some people move and others stay.',
            'reason' => 'First interviews showed a clear contrast between movers and stayers.',
        ])->assertOk()
            ->assertJsonPath('design.version', 2)
            ->assertJsonPath('design.updatedBy', $user->name)
            ->assertJsonPath('revisions.0.version', 1)
            ->assertJsonPath('revisions.0.reason', 'First interviews showed a clear contrast between movers and stayers.')
            ->assertJsonPath('revisions.0.changedBy', $user->name);
        $this->assertStringStartsWith('Exploratory', $response->json('revisions.0.snapshot.approach'));
    }

    public function test_the_analysis_plan_is_written_by_the_researchers(): void
    {
        [$user, $project] = $this->setupProject();

        $this->actingAs($user)->putJson(route('design.plan', $project), ['plan' => [
            ['title' => 'Read all interviews once', 'done' => true],
            ['title' => 'Compare movers and stayers', 'description' => 'case comparison'],
        ]])->assertOk()
            ->assertJsonPath('design.plan.0.done', true)
            ->assertJsonPath('design.plan.1.title', 'Compare movers and stayers')
            ->assertJsonPath('design.version', 0);

        $this->actingAs($user)->getJson(route('design.summary', $project))->assertOk()->assertJsonCount(2, 'design.plan');
    }

    public function test_outsiders_cannot_read_or_change_the_design(): void
    {
        [, $project] = $this->setupProject();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson(route('design.summary', $project))->assertForbidden();
        $this->actingAs($outsider)->putJson(route('design.update', $project), ['questions' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->putJson(route('design.plan', $project), ['plan' => []])->assertForbidden();
    }
}
