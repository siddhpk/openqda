<?php

namespace Tests\Feature\Collab;

use App\Models\Project;
use App\Models\User;
use App\Services\Collab\Collab;
use App\Services\Collab\IdMap;
use App\Services\Collab\Roster;
use App\Services\Collab\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Collaboration page as the person at the computer uses it.
 */
class CollaborationPageTest extends CollabTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addNode('alice', '100.64.0.1');
    }

    public function test_pressing_collaborate_makes_this_computer_the_primary_with_invitations(): void
    {
        $project = $this->makeProject();
        $owner = User::find($project->creating_user_id);

        $page = $this->host($owner)->getJson('/desktop/collab/status')->assertOk();
        $this->assertSame([$project->id], array_column($page->json('available'), 'id'));

        $after = $this->postJson("/desktop/collab/projects/{$project->id}/enable")->assertOk()->json();
        $card = $after['projects'][0];
        $this->assertSame('primary', $card['role']);
        $this->assertCount(1, $card['members']);
        $this->assertTrue($card['members'][0]['me']);
        $this->assertSame('primary', $card['members'][0]['role']);

        // and it stays so on the next request (a fresh roster)
        Collab::reset();
        $again = $this->getJson('/desktop/collab/status')->json('projects.0');
        $this->assertSame('primary', $again['role']);
        $this->assertCount(1, $again['members']);
    }

    public function test_a_founder_whose_roster_entry_is_missing_becomes_primary_again(): void
    {
        $project = $this->makeProject();
        $gid = Seeder::enable($project);
        DB::table('collab_ops')->where('tbl', '_members')->delete();
        Collab::reset();
        $this->assertNull(Roster::myRole($gid));

        $card = $this->host(User::find($project->creating_user_id))->getJson('/desktop/collab/status')->json('projects.0');
        $this->assertSame('primary', $card['role']);
        $this->assertCount(1, $card['members']);
    }

    public function test_several_projects_and_collaborating_again_after_leaving_keep_working(): void
    {
        $first = $this->makeProject('First');
        $second = Project::factory()->create(['creating_user_id' => $first->creating_user_id, 'name' => 'Second']);
        $this->host(User::find($first->creating_user_id));

        $this->postJson("/desktop/collab/projects/{$first->id}/enable")->assertOk();
        $cards = collect($this->postJson("/desktop/collab/projects/{$second->id}/enable")->assertOk()->json('projects'))->keyBy('id');
        $this->assertSame('primary', $cards[$first->id]['role']);
        $this->assertSame('primary', $cards[$second->id]['role'], 'a second project gets its own roster');

        // leave, then collaborate again: a new collaboration where this computer is Primary
        $this->postJson("/desktop/collab/projects/{$first->id}/leave")->assertOk();
        $this->assertContains($first->id, array_column($this->getJson('/desktop/collab/status')->json('available'), 'id'));
        $cards = collect($this->postJson("/desktop/collab/projects/{$first->id}/enable")->assertOk()->json('projects'))->keyBy('id');
        $this->assertSame('primary', $cards[$first->id]['role']);
        Collab::reset();
        $gid = Collab::projectGid($first->id);
        $this->assertSame($gid, IdMap::gid('projects', $first->id), 'the project maps to its current collaboration');

        // and invitations work on both
        foreach ([$first, $second] as $p) {
            $text = $this->postJson("/desktop/collab/projects/{$p->id}/invites", ['role' => 'assistant', 'key_mode' => 'none'])
                ->assertOk()->json('text');
            $this->assertStringStartsWith('openqda-invite:', $text);
        }
        $this->assertSame(2, DB::table('collab_invites')->count());
    }
}
