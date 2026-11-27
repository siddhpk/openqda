<?php

namespace Tests\Feature\Collab;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\Note;
use App\Models\Project;
use App\Models\Selection;
use App\Models\User;
use App\Services\Collab\Collab;
use App\Services\Collab\IdMap;
use App\Services\Collab\Reviews;
use App\Services\Collab\Roster;
use App\Services\Collab\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplicationTest extends CollabTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addNode('alice', '100.64.0.1');
        $this->addNode('bob', '100.64.0.2');
    }

    private function codeName(string $id): ?string
    {
        return DB::table('codes')->where('id', $id)->value('name');
    }

    public function test_changes_are_captured_only_in_collaborative_projects(): void
    {
        $this->on('alice');
        $private = $this->makeProject('Private');
        Codebook::factory()->create(['project_id' => $private->id, 'creating_user_id' => $private->creating_user_id]);
        $this->assertSame(0, DB::table('collab_dirty')->count(), 'nothing is noted while no project is collaborative');

        $shared = $this->makeProject('Shared');
        $gid = Seeder::enable($shared);
        Codebook::factory()->create(['project_id' => $private->id, 'creating_user_id' => $private->creating_user_id]);
        $this->assertSame([], $this->flush(), 'changes outside collaborative projects make no operations');

        $book = Codebook::factory()->create(['project_id' => $shared->id, 'creating_user_id' => $shared->creating_user_id]);
        $code = Code::factory()->create(['codebook_id' => $book->id]);
        $source = $this->textSource($shared, 'We trusted the nurses.');
        Selection::factory()->count(3)->create(['project_id' => $shared->id, 'code_id' => $code->id, 'source_id' => $source,
            'creating_user_id' => $shared->creating_user_id]);
        $batches = $this->flush();
        $this->assertCount(1, $batches);
        $this->assertSame($gid, $batches[0]['project']);
        $tables = array_count_values(array_column($batches[0]['ops'], 't'));
        $this->assertSame(3, $tables['selections']);
        $this->assertSame(1, $tables['codes']);
        $this->assertSame(1, $tables['sources']);

        // a bulk delete with the query builder (no model events) is captured too
        DB::table('selections')->where('code_id', $code->id)->delete();
        $deletes = array_filter($this->flush()[0]['ops'], fn ($op) => ! empty($op['f']['_deleted']));
        $this->assertCount(3, $deletes);
    }

    public function test_a_joined_laptop_gets_the_whole_project_with_files_and_authors(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id, 'name' => 'Main']);
        $code = Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Trust']);
        $child = Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Trust in staff', 'parent_id' => $code->id]);
        $source = $this->textSource($project, 'We trusted the nurses.');
        $selection = Selection::factory()->create(['project_id' => $project->id, 'code_id' => $child->id, 'source_id' => $source,
            'creating_user_id' => $project->creating_user_id, 'text' => 'trusted', 'start_position' => '3', 'end_position' => '10']);
        $shared = Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 1, 'type' => 'code', 'target' => $code->id, 'content' => 'Shared memo']);
        Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 0, 'type' => 'project', 'target' => (string) $project->id, 'content' => 'My private thoughts']);
        $projectMemo = Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 1, 'type' => 'project', 'target' => (string) $project->id, 'content' => 'About the project']);
        Seeder::enable($project);

        ['project' => $bobProject, 'user' => $bob] = $this->join('alice', $project, 'bob');

        $this->assertSame('Interviews', Project::find($bobProject)->name);
        $this->assertSame($bob->id, Project::find($bobProject)->creating_user_id, 'the joining person owns the local copy');
        $this->assertSame('Trust in staff', $this->codeName($child->id));
        $this->assertSame($code->id, DB::table('codes')->where('id', $child->id)->value('parent_id'));
        $bobBook = DB::table('codes')->where('id', $code->id)->value('codebook_id');
        $this->assertSame($bobProject, (int) DB::table('codebooks')->where('id', $bobBook)->value('project_id'));

        // the coding keeps its author: a shadow account with Alice's name
        $author = DB::table('selections')->where('id', $selection->id)->value('creating_user_id');
        $this->assertSame('Alice Researcher', DB::table('users')->where('id', $author)->value('name'));
        $this->assertStringEndsWith('@openqda.invalid', DB::table('users')->where('id', $author)->value('email'));
        $team = Project::find($bobProject)->team_id;
        $this->assertTrue(DB::table('team_user')->where('team_id', $team)->where('user_id', $author)->exists(), 'collaborators appear in the team');

        // files: the original and the converted HTML, at local paths
        $src = DB::table('sources')->where('id', $source)->first();
        $this->assertSame("projects/{$bobProject}/sources/{$source}.txt", Str::after(str_replace('\\', '/', $src->upload_path), 'app/'));
        $this->assertSame('We trusted the nurses.', file_get_contents($src->upload_path));
        $status = DB::table('source_statuses')->where('source_id', $source)->first();
        $this->assertSame('converted:html', $status->status);
        $this->assertStringContainsString('We trusted the nurses.', file_get_contents($status->path));

        // memos: shared ones arrive (targets translated), private ones never leave
        $this->assertSame('Shared memo', DB::table('notes')->where('content', 'Shared memo')->value('content'));
        $this->assertFalse(DB::table('notes')->where('content', 'My private thoughts')->exists());
        $this->assertSame((string) $bobProject, DB::table('notes')->where('content', 'About the project')->value('target'));
        unset($shared, $projectMemo);

        // and back: Bob codes, Alice gets it with Bob as author
        $bobSelection = (string) Str::uuid();
        DB::table('selections')->insert(['id' => $bobSelection, 'text' => 'nurses', 'project_id' => $bobProject, 'code_id' => $code->id,
            'source_id' => $source, 'creating_user_id' => $bob->id, 'start_position' => '16', 'end_position' => '22',
            'created_at' => now(), 'updated_at' => now()]);
        $this->flush();
        $events = $this->sync('bob', 'alice');
        $row = DB::table('selections')->where('id', $bobSelection)->first();
        $this->assertSame($project->id, (int) $row->project_id);
        $this->assertSame('Bob Assistant', DB::table('users')->where('id', $row->creating_user_id)->value('name'));
        $this->assertContains($bobSelection, array_column(array_filter($events, fn ($e) => $e['tbl'] === 'selections'), 'id'));
    }

    public function test_primary_edits_merge_silently_and_assistant_edits_to_others_items_wait_for_review(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        $code = Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Trust']);
        Seeder::enable($project);
        $this->join('alice', $project, 'bob');

        // the Primary renames: it simply arrives
        $this->on('alice');
        Code::find($code->id)->update(['name' => 'Trust in care']);
        $this->flush();
        $this->sync('alice', 'bob');
        $this->assertSame('Trust in care', $this->codeName($code->id));

        // the Assistant renames the Primary's code: visible to Bob, waiting at Alice
        Code::find($code->id)->update(['name' => 'Distrust']);
        $this->flush();
        $this->assertSame('Distrust', $this->codeName($code->id));
        $gid = Collab::projectGid(DB::table('projects')->value('id'));
        $this->assertSame(1, Reviews::mine($gid));
        $this->sync('bob', 'alice');
        $this->assertSame('Trust in care', $this->codeName($code->id));
        $list = Reviews::list($gid);
        $this->assertCount(1, $list['changes']);
        $this->assertSame('Bob Assistant', $list['changes'][0]['by']);
        $this->assertSame(['old' => 'Trust in care', 'new' => 'Distrust'], $list['changes'][0]['fields']['name']);

        // accepted: everyone has it
        Reviews::decide($gid, [$list['changes'][0]['op']], 'accept');
        $this->assertSame('Distrust', $this->codeName($code->id));
        $this->sync('alice', 'bob');
        $this->assertSame('Distrust', $this->codeName($code->id));
        $this->assertSame(0, Reviews::mine($gid));

        // rejected: Bob's laptop goes back to the shared value
        Code::find($code->id)->update(['name' => 'Mistrust']);
        $this->flush();
        $this->sync('bob', 'alice');
        $pending = Reviews::list($gid)['changes'][0]['op'];
        Reviews::decide($gid, [$pending], 'reject');
        $this->sync('alice', 'bob');
        $this->assertSame('Distrust', $this->codeName($code->id));
        $this->assertSame(0, Reviews::mine($gid));
    }

    public function test_concurrent_offline_edits_converge_with_the_primary_winning(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        Seeder::enable($project);
        ['project' => $bobProject] = $this->join('alice', $project, 'bob');

        // Bob creates his own code (an addition: merges at once)
        $bobBook = DB::table('codes')->exists() ? null : DB::table('codebooks')->where('project_id', $bobProject)->value('id');
        $codeId = (string) Str::uuid();
        DB::table('codes')->insert(['id' => $codeId, 'name' => 'Coping', 'color' => '#00f', 'codebook_id' => $bobBook]);
        $this->flush();
        $this->sync('bob', 'alice');
        $this->assertSame('Coping', $this->codeName($codeId));

        // both rename it while offline
        Code::find($codeId)->update(['name' => 'Coping strategies']);
        $this->flush();
        $this->on('bob');
        Code::find($codeId)->update(['name' => 'Coping (Bob)', 'color' => '#0f0']);
        $this->flush();
        $this->syncBoth('alice', 'bob');
        $this->on('alice');
        $alice = DB::table('codes')->where('id', $codeId)->first(['name', 'color']);
        $this->on('bob');
        $bob = DB::table('codes')->where('id', $codeId)->first(['name', 'color']);
        $this->assertEquals($alice, $bob, 'both laptops converge');
        $this->assertSame('Coping strategies', $bob->name, 'the Primary wins the concurrent rename');
        $this->assertSame('#0f0', $bob->color, 'a field only Bob changed keeps his value');

        // Bob edits again after seeing Alice's value: his own code, so it merges
        Code::find($codeId)->update(['name' => 'Coping, revised']);
        $this->flush();
        $this->sync('bob', 'alice');
        $this->assertSame('Coping, revised', $this->codeName($codeId));
        unset($book);
    }

    public function test_codings_on_a_code_deleted_elsewhere_are_held_and_can_be_restored(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        $code = Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Obsolete']);
        $source = $this->textSource($project, 'Some interview text');
        Seeder::enable($project);
        ['project' => $bobProject, 'user' => $bob] = $this->join('alice', $project, 'bob');

        // offline: Bob codes with the code, Alice deletes it
        $selection = (string) Str::uuid();
        DB::table('selections')->insert(['id' => $selection, 'text' => 'interview', 'project_id' => $bobProject, 'code_id' => $code->id,
            'source_id' => $source, 'creating_user_id' => $bob->id, 'start_position' => '5', 'end_position' => '14',
            'created_at' => now(), 'updated_at' => now()]);
        $this->flush();
        $this->on('alice');
        DB::table('codes')->where('id', $code->id)->delete();
        $this->flush();

        $this->syncBoth('alice', 'bob');
        $this->on('alice');
        $gid = Collab::projectGid($project->id);
        $this->assertFalse(DB::table('selections')->where('id', $selection)->exists());
        $orphans = Reviews::list($gid)['orphans'];
        $this->assertCount(1, $orphans);
        $this->assertSame('Code: Obsolete', $orphans[0]['parent']);
        $this->on('bob');
        $this->assertFalse(DB::table('codes')->where('id', $code->id)->exists());
        $this->assertFalse(DB::table('selections')->where('id', $selection)->exists(), 'held back on Bob\'s laptop too');

        // the Primary brings the code back: the coding returns everywhere
        $this->on('alice');
        Reviews::orphan($gid, 'selections', $selection, 'restore');
        $this->assertSame('Obsolete', $this->codeName($code->id));
        $this->assertTrue(DB::table('selections')->where('id', $selection)->exists());
        $this->sync('alice', 'bob');
        $this->assertSame('Obsolete', $this->codeName($code->id));
        $this->assertTrue(DB::table('selections')->where('id', $selection)->exists());
    }

    public function test_a_memo_made_private_leaves_the_other_laptops(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $memo = Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 1, 'type' => 'project', 'target' => (string) $project->id, 'content' => 'Shared at first']);
        Seeder::enable($project);
        $this->join('alice', $project, 'bob');
        $this->assertTrue(DB::table('notes')->where('content', 'Shared at first')->exists());

        $this->on('alice');
        Note::find($memo->id)->update(['visibility' => 0]);
        $this->flush();
        $this->assertTrue(DB::table('notes')->where('id', $memo->id)->exists(), 'it stays on Alice\'s laptop');
        $this->sync('alice', 'bob');
        $this->assertFalse(DB::table('notes')->where('content', 'Shared at first')->exists());
    }

    public function test_roles_change_through_the_roster_and_removed_members_lose_effect(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        $code = Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Trust']);
        $gid = Seeder::enable($project);
        $this->join('alice', $project, 'bob');
        $bobNode = $this->nodeId('bob');

        $this->on('alice');
        $this->assertSame('assistant', Roster::role($gid, $bobNode));
        $this->host(User::find($project->creating_user_id))
            ->postJson("/desktop/collab/projects/{$project->id}/members", ['node' => $bobNode, 'action' => 'primary'])
            ->assertOk();
        $this->assertSame('primary', Roster::role($gid, $bobNode));
        $this->sync('alice', 'bob');
        $this->assertSame('primary', Roster::myRole($gid));

        // now a Primary, Bob's rename of Alice's code merges silently
        Code::find($code->id)->update(['name' => 'Trust (Bob)']);
        $this->flush();
        $this->sync('bob', 'alice');
        $this->assertSame('Trust (Bob)', $this->codeName($code->id));

        // the only other Primary cannot be demoted while Alice is also Primary? (two Primaries: allowed)
        $this->postJson("/desktop/collab/projects/{$project->id}/members", ['node' => $bobNode, 'action' => 'remove'])->assertOk();
        $this->sync('alice', 'bob');
        $this->on('bob');
        Code::find($code->id)->update(['name' => 'After removal']);
        $this->flush();
        $this->sync('bob', 'alice');
        $this->assertSame('Trust (Bob)', $this->codeName($code->id), 'a removed member\'s later changes have no effect');
        unset($book);
    }

    public function test_the_last_primary_cannot_step_down(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        $this->join('alice', $project, 'bob');
        $this->on('alice');
        $this->host(User::find($project->creating_user_id))
            ->postJson("/desktop/collab/projects/{$project->id}/members", ['node' => $this->nodeId('alice'), 'action' => 'assistant'])
            ->assertStatus(422);
        $this->postJson("/desktop/collab/projects/{$project->id}/leave")->assertStatus(422);
    }

    public function test_local_ids_differ_but_global_ids_match(): void
    {
        $this->on('alice');
        DB::table('projects')->insert(['name' => 'filler', 'creating_user_id' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        Seeder::enable($project);
        $aliceGid = IdMap::gid('codebooks', $book->id);
        ['project' => $bobProject] = $this->join('alice', $project, 'bob');
        $bobBook = DB::table('codebooks')->where('project_id', $bobProject)->value('id');
        $this->assertSame($aliceGid, IdMap::gid('codebooks', $bobBook));
        $this->assertNotSame($project->id, $bobProject, 'numeric ids are local to each laptop');
    }
}
