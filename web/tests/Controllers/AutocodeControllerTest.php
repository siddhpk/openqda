<?php

namespace Tests\Controllers;

use App\Events\Coding\SelectionChanged;
use App\Models\AiSuggestionDecision;
use App\Models\AutocodeRun;
use App\Models\Code;
use App\Models\Codebook;
use App\Models\Project;
use App\Models\ProjectAiSetting;
use App\Models\Selection;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Models\User;
use App\Services\AI\AiManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AutocodeControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/projects/autocode-test-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function source(Project $project, User $user, string $html): Source
    {
        $source = Source::factory()->create(['project_id' => $project->id, 'creating_user_id' => $user->id]);
        $path = $this->dir.'/'.$source->id.'.html';
        file_put_contents($path, $html);
        SourceStatus::create(['source_id' => $source->id, 'status' => 'converted:html', 'path' => $path]);

        return $source;
    }

    private function optIn(Project $project): void
    {
        config(['ai.enabled' => true, 'ai.embedding.provider' => 'ollama', 'ai.providers.ollama.cloud' => false]);
        ProjectAiSetting::create([
            'project_id' => $project->id,
            'enabled' => true,
            'consent_fingerprint' => (new AiManager)->configFingerprint(),
        ]);
    }

    public function test_corpus_contains_coded_and_target_sources_with_exact_selection_positions(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $this->optIn($project);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $code = Code::factory()->create(['codebook_id' => $codebook->id]);

        $coded = $this->source($project, $user, '<p>I lost my job.</p>');
        $target = $this->source($project, $user, '<p>Uncoded interview.</p>');
        $unrelated = $this->source($project, $user, '<p>Not coded and not selected.</p>');
        Selection::factory()->create(['project_id' => $project->id, 'source_id' => $coded->id, 'code_id' => $code->id, 'text' => 'I lost my job.', 'start_position' => 0, 'end_position' => 14, 'creating_user_id' => $user->id]);

        $json = $this->actingAs($user)->postJson(route('autocode.corpus', $project), [
            'target_source_ids' => [$target->id],
        ])->assertOk()->json();

        $ids = array_column($json['sources'], 'id');
        $this->assertEqualsCanonicalizing([$coded->id, $target->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $byId = collect($json['sources'])->keyBy('id');
        $this->assertTrue($byId[$target->id]['target']);
        $this->assertTrue($byId[$coded->id]['coded']);
        $this->assertStringContainsString('I lost my job.', $byId[$coded->id]['content']);
        $this->assertSame([['source_id' => $coded->id, 'code_id' => $code->id, 'start' => 0, 'end' => 14]], $json['selections']);

        $foreign = Source::factory()->create();
        $this->actingAs($user)->postJson(route('autocode.corpus', $project), ['target_source_ids' => [$foreign->id]])
            ->assertUnprocessable();
    }

    public function test_bulk_accept_creates_multi_code_selections_with_provenance(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $this->optIn($project);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $a = Code::factory()->create(['codebook_id' => $codebook->id]);
        $b = Code::factory()->create(['codebook_id' => $codebook->id]);
        $s1 = $this->source($project, $user, '<p>first unit and more</p>');
        $s2 = $this->source($project, $user, '<p>Some <b>other</b> source text.</p>');
        $run = AutocodeRun::create(['project_id' => $project->id, 'params' => [], 'metrics' => []]);

        $items = [
            ['source_id' => $s1->id, 'code_id' => $a->id, 'start' => 0, 'end' => 10, 'text' => 'first unit'],
            ['source_id' => $s1->id, 'code_id' => $b->id, 'start' => 0, 'end' => 10, 'text' => 'first unit'],   // same text, second code
            ['source_id' => $s2->id, 'code_id' => $a->id, 'start' => 5, 'end' => 17, 'text' => 'other source'],
        ];
        Event::fake([SelectionChanged::class]);
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => $items])
            ->assertCreated()
            ->assertJsonCount(3, 'created')
            ->assertJsonPath('accepted', 3);
        $this->assertSame(3, Selection::where('origin', 'autocode:'.$run->id)->count());
        // with the Live Team Coding module, others with the sources open are
        // told once per source to reload
        if (class_exists(SelectionChanged::class)) {
            Event::assertDispatchedTimes(SelectionChanged::class, 2);
            Event::assertDispatched(SelectionChanged::class, fn (SelectionChanged $e) => $e->action === SelectionChanged::STALE && $e->sourceId === $s1->id && $e->selection['count'] === 2);
        }

        // duplicates are skipped
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [$items[0]]])
            ->assertCreated()->assertJsonCount(0, 'created');

        // foreign codes or sources are rejected
        $foreignCode = Code::factory()->create();
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [[...$items[0], 'code_id' => $foreignCode->id]]])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [[...$items[0], 'end' => 0]]])
            ->assertUnprocessable();
    }

    public function test_accepted_text_must_come_from_the_source(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $this->optIn($project);
        $code = Code::factory()->create(['codebook_id' => Codebook::factory()->create(['project_id' => $project->id])->id]);
        $source = $this->source($project, $user, '<p>I lost my job.</p><p>We moved to Leipzig – it was hard.</p>');
        $run = AutocodeRun::create(['project_id' => $project->id, 'params' => [], 'metrics' => []]);
        $ok = ['source_id' => $source->id, 'code_id' => $code->id, 'start' => 15, 'end' => 46, 'text' => 'We moved to Leipzig – it was hard.'];
        $ok['end'] = $ok['start'] + mb_strlen($ok['text']);

        foreach ([
            'invented text' => [...$ok, 'text' => 'We never moved anywhere at all.', 'end' => 15 + 31],
            'length mismatch' => [...$ok, 'end' => $ok['end'] + 3],
            'outside the source' => [...$ok, 'start' => 5000, 'end' => 5000 + mb_strlen($ok['text'])],
        ] as $case => $item) {
            $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [$item]])
                ->assertUnprocessable()->assertJsonValidationErrors('items');
        }
        $this->assertSame(0, Selection::count());

        // a source without converted text cannot be verified
        $raw = Source::factory()->create(['project_id' => $project->id]);
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [[...$ok, 'source_id' => $raw->id]]])
            ->assertUnprocessable();

        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [$ok]])->assertCreated();
        // emoji count as two units in the browser
        $emoji = $this->source($project, $user, '<p>Home 🏠 at last</p>');
        $this->actingAs($user)->postJson(route('autocode.runs.accept', [$project, $run]), ['items' => [
            ['source_id' => $emoji->id, 'code_id' => $code->id, 'start' => 0, 'end' => 15, 'text' => 'Home 🏠 at last'],
        ]])->assertCreated();
    }

    public function test_decisions_are_recorded_without_text(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $this->optIn($project);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $a = Code::factory()->create(['codebook_id' => $codebook->id]);
        $b = Code::factory()->create(['codebook_id' => $codebook->id]);
        $source = $this->source($project, $user, '<p>I lost my job. My family helped.</p>');
        $run = AutocodeRun::create(['project_id' => $project->id, 'params' => [], 'metrics' => []]);
        $unit = ['source_id' => $source->id, 'start' => 0, 'end' => 14, 'text' => 'I lost my job.'];
        $other = ['source_id' => $source->id, 'start' => 15, 'end' => 33, 'text' => 'My family helped.'];
        $other['end'] = 15 + mb_strlen($other['text']);

        $response = $this->actingAs($user)->postJson(route('autocode.runs.decisions', [$project, $run]), ['items' => [
            [...$unit, 'decision' => 'accepted', 'suggested' => [$a->id], 'chosen' => [$a->id], 'scores' => [$a->id => 0.81, $b->id => 0.2]],
            [...$other, 'decision' => 'modified', 'suggested' => [$a->id], 'chosen' => [$b->id]],
        ]])->assertCreated()->assertJsonPath('recorded', 2);
        $response->assertJsonPath('summary.accepted', 1)->assertJsonPath('summary.modified', 1);

        // a later decision on the same segment replaces the earlier one in the summary
        $this->actingAs($user)->postJson(route('autocode.runs.decisions', [$project, $run]), ['items' => [
            [...$unit, 'decision' => 'rejected', 'suggested' => [$a->id], 'chosen' => []],
        ]])->assertCreated()->assertJsonPath('summary.accepted', 0)->assertJsonPath('summary.rejected', 1);

        $row = AiSuggestionDecision::where('decision', 'accepted')->first();
        $this->assertSame([$a->id], $row->chosen);
        $this->assertSame(64, strlen($row->segment_hash));
        $this->assertStringNotContainsString('lost my job', json_encode(AiSuggestionDecision::all()->toArray()));
        $this->assertSame(
            $row->segment_hash,
            AiSuggestionDecision::where('decision', 'rejected')->first()->segment_hash
        );

        // inconsistent decisions, invented text and foreign codes are refused
        $post = fn ($item) => $this->actingAs($user)->postJson(route('autocode.runs.decisions', [$project, $run]), ['items' => [$item]]);
        $post([...$unit, 'decision' => 'accepted', 'suggested' => [$a->id], 'chosen' => [$b->id]])->assertUnprocessable();
        $post([...$unit, 'text' => 'I won the lottery', 'end' => 17, 'decision' => 'rejected', 'suggested' => [], 'chosen' => []])->assertUnprocessable();
        $post([...$unit, 'decision' => 'deferred', 'suggested' => [Code::factory()->create()->id], 'chosen' => []])->assertUnprocessable();
        $this->assertSame(3, AiSuggestionDecision::count());

        $runs = $this->actingAs($user)->getJson(route('autocode.runs', $project))->assertOk()->json('runs');
        $this->assertSame(['accepted' => 0, 'modified' => 1, 'rejected' => 1, 'deferred' => 0], $runs[0]['decisions']);
    }

    public function test_runs_are_validated_marked_client_reported_and_single_selections_keep_their_origin(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $this->optIn($project);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $code = Code::factory()->create(['codebook_id' => $codebook->id]);
        $source = Source::factory()->create(['project_id' => $project->id]);
        $payload = [
            'source_id' => $source->id,
            'params' => ['granularity' => 'sentence', 'method' => 'auto', 'validation' => 'sources', 'include' => [$code->id], 'embedding' => ['provider' => 'made-up', 'model' => 'x', 'dimensions' => 768], 'secret' => 'dropped'],
            'metrics' => [
                'type' => 'logistic', 'microF1' => 0.71, 'macroF1' => 0.6, 'injected' => 'dropped',
                'validation' => ['scheme' => 'sources', 'folds' => 4, 'groups' => 5, 'nested' => true, 'evaluated' => 110],
                'perClass' => [['classId' => $code->id, 'precision' => 0.8, 'recall' => 0.7, 'f1' => 0.75, 'support' => 20, 'examples' => 25]],
                'classes' => [['id' => $code->id, 'count' => 25, 'rolledUp' => [], 'threshold' => 0.4]],
            ],
        ];

        $origin = $this->actingAs($user)->postJson(route('autocode.runs.store', $project), $payload)->assertCreated()->json('run.origin');
        $this->assertMatchesRegularExpression('/^autocode:\d+$/', $origin);
        $run = AutocodeRun::latest('id')->first();
        $this->assertSame('client', $run->metrics['reported_by']);
        $this->assertArrayNotHasKey('injected', $run->metrics);
        $this->assertArrayNotHasKey('secret', $run->params);
        $this->assertArrayNotHasKey('embedding', $run->params);
        $this->assertSame('ollama', $run->params['server']['embedding']['provider']);
        $this->assertSame(768, $run->params['server']['embedding']['reported_dimensions']);
        $this->assertSame((new AiManager)->configFingerprint(), $run->params['server']['consent_fingerprint']);

        // impossible figures and codes from elsewhere are refused
        $bad = $payload;
        $bad['metrics']['microF1'] = 1.7;
        $this->actingAs($user)->postJson(route('autocode.runs.store', $project), $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['metrics']['classes'][0]['id'] = Code::factory()->create()->id;
        $this->actingAs($user)->postJson(route('autocode.runs.store', $project), $bad)->assertUnprocessable();

        $this->actingAs($user)->postJson(route('selection.store', [$project, $source, $code]), [
            'textId' => '9a7e1c9e-5f0a-4c1e-8c55-0d0f3c7a1b11',
            'text' => 'some text', 'start_position' => 0, 'end_position' => 9, 'origin' => $origin,
        ])->assertOk();
        $this->assertDatabaseHas('selections', ['code_id' => $code->id, 'origin' => $origin]);

        $this->actingAs($user)->postJson(route('selection.store', [$project, $source, $code]), [
            'textId' => '9a7e1c9e-5f0a-4c1e-8c55-0d0f3c7a1b12',
            'text' => 'x', 'start_position' => 0, 'end_position' => 1, 'origin' => 'made-up',
        ])->assertUnprocessable();
        // a run of another project is not valid provenance
        $foreignRun = AutocodeRun::create(['project_id' => Project::factory()->create()->id, 'params' => [], 'metrics' => []]);
        $this->actingAs($user)->postJson(route('selection.store', [$project, $source, $code]), [
            'textId' => '9a7e1c9e-5f0a-4c1e-8c55-0d0f3c7a1b13',
            'text' => 'x', 'start_position' => 0, 'end_position' => 1, 'origin' => $foreignRun->origin(),
        ])->assertUnprocessable();

        // the accepted count cannot exceed what was written
        $this->actingAs($user)->patchJson(route('autocode.runs.update', [$project, $run]), ['accepted' => 999])
            ->assertOk()->assertJsonPath('run.accepted', 1);
    }

    public function test_autocode_endpoints_respect_opt_in(): void
    {
        config(['ai.enabled' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('autocode.corpus', $project), ['target_source_ids' => []])
            ->assertForbidden();
    }
}
