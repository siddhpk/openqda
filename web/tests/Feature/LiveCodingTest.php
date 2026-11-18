<?php

namespace Tests\Feature;

use App\Events\Coding\CodeChanged;
use App\Events\Coding\SelectionChanged;
use App\Models\Code;
use App\Models\Codebook;
use App\Models\Project;
use App\Models\Selection;
use App\Models\Source;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LiveCodingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Source $source;

    private Codebook $codebook;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create(['creating_user_id' => $this->user->id]);
        $this->source = Source::factory()->create(['project_id' => $this->project->id, 'creating_user_id' => $this->user->id]);
        $this->codebook = Codebook::factory()->create(['project_id' => $this->project->id, 'creating_user_id' => $this->user->id]);
        $this->code = Code::factory()->create(['codebook_id' => $this->codebook->id]);
    }

    private function storeSelection(string $id): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('selection.store', [
            'project' => $this->project->id,
            'source' => $this->source->id,
            'code' => $this->code->id,
        ]), ['textId' => $id, 'start_position' => 3, 'end_position' => 9, 'text' => 'abcdef']);
    }

    public function test_a_new_segment_is_broadcast_to_the_source_with_its_coder(): void
    {
        Event::fake([SelectionChanged::class]);

        $this->storeSelection('sel-live-1')->assertOk();

        Event::assertDispatched(SelectionChanged::class, function (SelectionChanged $e) {
            $payload = $e->broadcastWith();

            return $e->action === SelectionChanged::CREATED
                && $e->broadcastOn()->name === 'presence-coding.'.$this->source->id
                && $e->broadcastAs() === 'selection.changed'
                && $payload['selection']['id'] === 'sel-live-1'
                && $payload['selection']['code_id'] === $this->code->id
                && $payload['selection']['start'] === 3
                && $payload['selection']['end'] === 9
                && $payload['selection']['creating_user_id'] === $this->user->id
                && $payload['userId'] === $this->user->id
                // receivers read the text from the open source
                && ! array_key_exists('text', $payload['selection']);
        });
    }

    public function test_recoding_and_deleting_a_segment_are_broadcast(): void
    {
        $selection = Selection::create([
            'id' => 'sel-live-2',
            'code_id' => $this->code->id,
            'source_id' => $this->source->id,
            'project_id' => $this->project->id,
            'creating_user_id' => $this->user->id,
            'text' => 'test',
            'start_position' => 0,
            'end_position' => 4,
        ]);
        $other = Code::factory()->create(['codebook_id' => $this->codebook->id]);
        Event::fake([SelectionChanged::class]);

        $this->actingAs($this->user)->postJson(route('selection.change-code', [
            'project' => $this->project->id,
            'source' => $this->source->id,
            'code' => $this->code->id,
            'selection' => $selection->id,
        ]), ['newCodeId' => $other->id])->assertOk();

        Event::assertDispatched(SelectionChanged::class, fn (SelectionChanged $e) => $e->action === SelectionChanged::UPDATED
            && $e->selection['id'] === $selection->id
            && $e->selection['code_id'] === $other->id);

        $this->actingAs($this->user)->deleteJson(route('selection.destroy', [
            'project' => $this->project->id,
            'source' => $this->source->id,
            'code' => $other->id,
            'selection' => $selection->id,
        ]))->assertOk();

        Event::assertDispatched(SelectionChanged::class, fn (SelectionChanged $e) => $e->action === SelectionChanged::DELETED
            && $e->selection['id'] === $selection->id);
    }

    public function test_code_changes_are_broadcast_to_the_project(): void
    {
        Event::fake([CodeChanged::class]);

        $id = $this->actingAs($this->user)->postJson(route('coding.store', $this->project), [
            'title' => 'Trust',
            'color' => '#ff0000',
            'codebook' => $this->codebook->id,
            'parent_id' => $this->code->id,
        ])->assertCreated()->json('id');

        Event::assertDispatched(CodeChanged::class, fn (CodeChanged $e) => $e->action === CodeChanged::CREATED
            && $e->broadcastOn()->name === 'private-coding-project.'.$this->project->id
            && $e->broadcastAs() === 'code.changed'
            && $e->code['id'] === $id
            && $e->code['name'] === 'Trust'
            && $e->code['codebook'] === $this->codebook->id
            && $e->code['parent_id'] === $this->code->id);

        $this->actingAs($this->user)->patchJson(route('coding.update-attribute', [$this->project, $id]), ['color' => '#00ff00'])
            ->assertOk();
        Event::assertDispatched(CodeChanged::class, fn (CodeChanged $e) => $e->action === CodeChanged::UPDATED
            && $e->code['id'] === $id
            && $e->code['color'] === '#00ff00');

        $this->actingAs($this->user)->deleteJson(route('coding.destroy', [$this->project, $id]))->assertOk();
        Event::assertDispatched(CodeChanged::class, fn (CodeChanged $e) => $e->action === CodeChanged::DELETED
            && $e->code === ['id' => $id]);
    }

    public function test_coding_still_works_when_the_websocket_server_is_down(): void
    {
        Broadcast::extend('failing', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new BroadcastException('connection refused');
            }
        });
        config([
            'broadcasting.default' => 'failing',
            'broadcasting.connections.failing' => ['driver' => 'failing'],
        ]);

        $this->storeSelection('sel-live-3')->assertOk();
        $this->assertDatabaseHas('selections', ['id' => 'sel-live-3']);
    }

    public function test_only_project_members_may_join_the_coding_channels(): void
    {
        $outsider = User::factory()->create();
        $channels = Broadcast::driver()->getChannels();

        $source = $channels->get('coding.{source}');
        $member = $source($this->user, $this->source);
        $this->assertSame($this->user->id, $member['id']);
        $this->assertSame($this->user->name, $member['name']);
        $this->assertFalse($source($outsider, $this->source));

        $project = $channels->get('coding-project.{project}');
        $this->assertTrue($project($this->user, $this->project));
        $this->assertFalse($project($outsider, $this->project));
    }
}
