<?php

namespace Tests\Controllers;

use App\Models\AnalysisCase;
use App\Models\AnalyticEvidence;
use App\Models\CaseAssessment;
use App\Models\Code;
use App\Models\Codebook;
use App\Models\Concept;
use App\Models\Project;
use App\Models\Selection;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalysisObjectsTest extends TestCase
{
    use RefreshDatabase;

    private function setupProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $codebook = Codebook::factory()->create(['project_id' => $project->id]);
        $work = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Work']);
        $jobLoss = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Job loss', 'parent_id' => $work->id]);
        $family = Code::factory()->create(['codebook_id' => $codebook->id, 'name' => 'Family']);
        $s1 = Source::factory()->create(['project_id' => $project->id, 'name' => 'Interview A']);
        $s2 = Source::factory()->create(['project_id' => $project->id, 'name' => 'Interview B']);
        $sel = fn ($source, $code, $text, $start) => Selection::factory()->create([
            'project_id' => $project->id, 'source_id' => $source->id, 'code_id' => $code->id, 'text' => $text,
            'start_position' => $start, 'end_position' => $start + strlen($text), 'creating_user_id' => $user->id,
        ]);
        $segments = [
            'a1' => $sel($s1, $jobLoss, 'The plant closed.', 0),
            'a2' => $sel($s1, $family, 'My mother helped.', 20),
            'b1' => $sel($s2, $work, 'I found work in the city.', 0),
            'b2' => $sel($s2, $jobLoss, 'Laid off again.', 30),
        ];

        return compact('user', 'project', 'work', 'jobLoss', 'family', 's1', 's2', 'segments');
    }

    public function test_concepts_are_created_revised_and_listed(): void
    {
        extract($this->setupProject());
        $this->actingAs($user)->get(route('concepts.page', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ConceptsPage')->has('concepts', 0)->has('codes', 3));

        $id = $this->actingAs($user)->postJson(route('concepts.store', $project), [
            'name' => 'Economic push', 'definition' => 'Leaving because work disappears.', 'status' => 'draft',
            'properties' => [['name' => 'Suddenness', 'low' => 'gradual', 'high' => 'abrupt']],
            'codes' => [$work->id],
        ])->assertCreated()->assertJsonPath('concept.codes.0', $work->id)->json('concept.id');

        // same name twice, foreign codes and unknown statuses are refused
        $this->actingAs($user)->postJson(route('concepts.store', $project), ['name' => 'Economic push'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('concepts.store', $project), ['name' => 'X', 'codes' => [Code::factory()->create()->id]])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('concepts.store', $project), ['name' => 'Y', 'status' => 'saturated'])->assertUnprocessable();

        // a change keeps the previous version; an unchanged save does not
        $this->actingAs($user)->putJson(route('concepts.update', [$project, $id]), [
            'definition' => 'Leaving because paid work disappears or becomes insecure.', 'reason' => 'Interview B',
        ])->assertOk()->assertJsonPath('concept.version', 2)->assertJsonPath('revisions.0.reason', 'Interview B')
            ->assertJsonPath('revisions.0.snapshot.definition', 'Leaving because work disappears.');
        $this->actingAs($user)->putJson(route('concepts.update', [$project, $id]), ['definition' => 'Leaving because paid work disappears or becomes insecure.'])
            ->assertJsonPath('concept.version', 2);
        $this->actingAs($user)->putJson(route('concepts.update', [$project, $id]), ['codes' => [$work->id, $family->id]])
            ->assertJsonPath('concept.version', 3);
    }

    public function test_evidence_comes_from_coded_segments_and_keeps_challenges_visible(): void
    {
        extract($this->setupProject());
        $concept = Concept::create(['project_id' => $project->id, 'name' => 'Economic push']);
        $concept->codes()->sync([$work->id]);

        // candidates: segments of the linked code and its subcodes
        $candidates = $this->actingAs($user)->getJson(route('evidence.candidates', [$project, 'target_type' => 'concept', 'target_id' => $concept->id]))
            ->assertOk()->json('candidates');
        $this->assertEqualsCanonicalizing([$segments['a1']->id, $segments['b1']->id, $segments['b2']->id], array_column($candidates, 'selectionId'));
        // any code can be searched, and everything at once (for ranking in the browser)
        $this->actingAs($user)->getJson(route('evidence.candidates', [$project, 'target_type' => 'concept', 'target_id' => $concept->id, 'code_id' => $family->id, 'all' => 1]))
            ->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('lastPage', 1);

        $this->actingAs($user)->postJson(route('evidence.store', $project), [
            'target_type' => 'concept', 'target_id' => $concept->id, 'selection_ids' => [$segments['a1']->id, $segments['b2']->id], 'stance' => 'supports',
        ])->assertCreated()->assertJsonPath('created', 2)->assertJsonFragment(['text' => 'The plant closed.', 'stance' => 'supports']);
        $evidence = $this->actingAs($user)->postJson(route('evidence.store', $project), [
            'target_type' => 'concept', 'target_id' => $concept->id, 'selection_ids' => [$segments['b1']->id], 'stance' => 'challenges', 'note' => 'Moved for a job, not from a loss',
        ])->assertCreated()->json('evidence');
        $challenge = collect($evidence)->firstWhere('stance', 'challenges');
        $this->assertSame('open', $challenge['resolution']);

        // used segments are no longer candidates; adding one twice is ignored
        $this->actingAs($user)->getJson(route('evidence.candidates', [$project, 'target_type' => 'concept', 'target_id' => $concept->id]))
            ->assertJsonCount(0, 'candidates');
        $this->actingAs($user)->postJson(route('evidence.store', $project), [
            'target_type' => 'concept', 'target_id' => $concept->id, 'selection_ids' => [$segments['a1']->id], 'stance' => 'illustrates',
        ])->assertCreated()->assertJsonPath('created', 0);

        $list = $this->actingAs($user)->get(route('concepts.page', $project))->viewData('page')['props']['concepts'];
        $this->assertSame(['supports' => 2, 'challenges' => 1, 'illustrates' => 0, 'qualifies' => 0, 'openChallenges' => 1], $list[0]['evidence']);

        // resolving a challenge; changing the stance clears the resolution
        $this->actingAs($user)->putJson(route('evidence.update', [$project, $challenge['id']]), ['resolution' => 'revised', 'resolution_note' => 'Definition widened'])
            ->assertOk();
        $this->assertSame(0, $this->actingAs($user)->get(route('concepts.page', $project))->viewData('page')['props']['concepts'][0]['evidence']['openChallenges']);
        $this->actingAs($user)->putJson(route('evidence.update', [$project, $challenge['id']]), ['stance' => 'qualifies'])->assertOk();
        $this->assertNull(AnalyticEvidence::find($challenge['id'])->resolution);

        // a removed segment leaves the excerpt in place
        $segments['a1']->delete();
        $after = $this->actingAs($user)->getJson(route('concepts.show', [$project, $concept]))->json('evidence');
        $kept = collect($after)->firstWhere('text', 'The plant closed.');
        $this->assertTrue($kept['segmentRemoved']);
    }

    public function test_propositions_name_concept_roles_and_keep_revisions(): void
    {
        extract($this->setupProject());
        $push = Concept::create(['project_id' => $project->id, 'name' => 'Economic push']);
        $ties = Concept::create(['project_id' => $project->id, 'name' => 'Family ties']);

        $id = $this->actingAs($user)->postJson(route('propositions.store', $project), [
            'title' => 'Ties soften the push', 'statement' => 'Where family ties are strong, job loss leads to moving later.',
            'conditions' => 'Regions with few jobs', 'concepts' => [['id' => $push->id, 'role' => 'trigger'], ['id' => $ties->id, 'role' => 'moderator']],
        ])->assertCreated()->assertJsonPath('proposition.concepts.1.role', 'moderator')->json('proposition.id');

        $this->actingAs($user)->putJson(route('propositions.update', [$project, $id]), ['scope' => 'Working-age adults', 'reason' => 'case C'])
            ->assertOk()->assertJsonPath('proposition.version', 2)->assertJsonPath('revisions.0.snapshot.scope', null);
        $this->actingAs($user)->postJson(route('propositions.store', $project), ['title' => 'x', 'concepts' => [['id' => Concept::create(['project_id' => Project::factory()->create()->id, 'name' => 'Foreign'])->id]]])
            ->assertUnprocessable();

        // evidence for a proposition comes from the codes of its concepts
        $push->codes()->sync([$jobLoss->id]);
        $this->actingAs($user)->getJson(route('evidence.candidates', [$project, 'target_type' => 'proposition', 'target_id' => $id]))
            ->assertOk()->assertJsonCount(2, 'candidates');

        $this->actingAs($user)->getJson(route('concepts.show', [$project, $push]))->assertJsonPath('propositions.0.title', 'Ties soften the push');
        // deleting a concept removes it from propositions
        $this->actingAs($user)->deleteJson(route('concepts.destroy', [$project, $ties]))->assertOk();
        $this->actingAs($user)->getJson(route('propositions.show', [$project, $id]))->assertJsonCount(1, 'proposition.concepts');
    }

    public function test_case_matrix_tells_absence_from_not_examined(): void
    {
        extract($this->setupProject());
        $this->actingAs($user)->postJson(route('cases.store', $project), ['name' => 'Anna', 'source_ids' => [$s1->id]])->assertCreated();
        $cases = $this->actingAs($user)->postJson(route('cases.store', $project), ['name' => 'Ben', 'source_ids' => [$s2->id]])->assertCreated()->json('cases');
        $anna = collect($cases)->firstWhere('name', 'Anna')['id'];
        $ben = collect($cases)->firstWhere('name', 'Ben')['id'];
        $this->actingAs($user)->postJson(route('cases.store', $project), ['name' => 'Anna'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('cases.store', $project), ['name' => 'C', 'source_ids' => [Source::factory()->create()->id]])->assertUnprocessable();

        $matrix = $this->actingAs($user)->getJson(route('cases.matrix', [$project, 'rows' => 'code']))->assertOk()->json();
        $this->assertSame(1, $matrix['cells'][$jobLoss->id][$anna]['count']);
        $this->assertSame(1, $matrix['cells'][$family->id][$anna]['count']);
        $this->assertArrayNotHasKey($ben, $matrix['cells'][$family->id]); // not examined: no entry at all
        // with subcodes the parent includes its children
        $withSub = $this->actingAs($user)->getJson(route('cases.matrix', [$project, 'rows' => 'code', 'subcodes' => 1]))->json();
        $this->assertSame(2, $withSub['cells'][$work->id][$ben]['count']);

        // absence is recorded on purpose and shown as such
        $this->actingAs($user)->putJson(route('cases.assess', $project), ['item_type' => 'code', 'item_id' => $family->id, 'case_id' => $ben, 'state' => 'absent', 'note' => 'Asked; no family nearby'])
            ->assertOk()->assertJsonPath('assessment.state', 'absent');
        $matrix = $this->actingAs($user)->getJson(route('cases.matrix', [$project, 'rows' => 'code']))->json();
        $this->assertSame('absent', $matrix['cells'][$family->id][$ben]['assessment']['state']);
        $this->assertArrayNotHasKey('count', $matrix['cells'][$family->id][$ben]);
        $this->actingAs($user)->putJson(route('cases.assess', $project), ['item_type' => 'code', 'item_id' => $family->id, 'case_id' => $ben, 'state' => null])->assertOk();
        $this->assertSame(0, CaseAssessment::count());

        // drill-down
        $this->actingAs($user)->getJson(route('cases.cell', [$project, 'item_type' => 'code', 'item_id' => $jobLoss->id, 'case_id' => $ben]))
            ->assertOk()->assertJsonPath('items.0.text', 'Laid off again.')->assertJsonPath('items.0.sourceName', 'Interview B');

        // concept rows count evidence by stance
        $concept = Concept::create(['project_id' => $project->id, 'name' => 'Economic push']);
        AnalyticEvidence::create(['project_id' => $project->id, 'target_type' => 'concept', 'target_id' => $concept->id, 'selection_id' => $segments['b1']->id,
            'source_id' => $s2->id, 'start_position' => 0, 'end_position' => 25, 'text' => 'I found work in the city.', 'stance' => 'challenges', 'resolution' => 'open']);
        $matrix = $this->actingAs($user)->getJson(route('cases.matrix', [$project, 'rows' => 'concept']))->json();
        $this->assertSame(['count' => 1, 'challenges' => 1], $matrix['cells'][$concept->id][$ben]);

        // a code of another project cannot be assessed
        $this->actingAs($user)->putJson(route('cases.assess', $project), ['item_type' => 'code', 'item_id' => Code::factory()->create()->id, 'case_id' => $ben, 'state' => 'present'])
            ->assertUnprocessable();
    }

    public function test_everything_stays_inside_its_project(): void
    {
        extract($this->setupProject());
        $outsider = User::factory()->create();
        $concept = Concept::create(['project_id' => $project->id, 'name' => 'Economic push']);
        $other = Project::factory()->create(['creating_user_id' => $user->id]);
        $case = AnalysisCase::create(['project_id' => $project->id, 'name' => 'Anna']);

        $this->actingAs($outsider)->get(route('concepts.page', $project))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('concepts.store', $project), ['name' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->getJson(route('cases.matrix', [$project, 'rows' => 'code']))->assertForbidden();

        $this->actingAs($user)->getJson(route('concepts.show', [$other, $concept]))->assertNotFound();
        $this->actingAs($user)->putJson(route('concepts.update', [$other, $concept]), ['name' => 'moved'])->assertNotFound();
        $this->actingAs($user)->putJson(route('cases.update', [$other, $case]), ['name' => 'moved'])->assertNotFound();
        // evidence cannot use another project's segments or targets
        $this->actingAs($user)->postJson(route('evidence.store', $other), ['target_type' => 'concept', 'target_id' => $concept->id, 'selection_ids' => [$segments['a1']->id], 'stance' => 'supports'])
            ->assertUnprocessable();
        $otherConcept = Concept::create(['project_id' => $other->id, 'name' => 'Elsewhere']);
        $this->actingAs($user)->postJson(route('evidence.store', $other), ['target_type' => 'concept', 'target_id' => $otherConcept->id, 'selection_ids' => [$segments['a1']->id], 'stance' => 'supports'])
            ->assertUnprocessable();
        // memos on concepts and cases must point into the project
        $this->actingAs($user)->postJson(route('notes.store', $other), ['content' => 'x', 'type' => 'concept', 'target' => $concept->id])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'x', 'type' => 'concept', 'target' => $concept->id])->assertCreated();
        $this->actingAs($user)->postJson(route('notes.store', $project), ['content' => 'x', 'type' => 'case', 'target' => $case->id])->assertCreated();

        // deleting a concept takes its evidence, memos and assessments with it
        AnalyticEvidence::create(['project_id' => $project->id, 'target_type' => 'concept', 'target_id' => $concept->id, 'selection_id' => $segments['a1']->id,
            'source_id' => $s1->id, 'start_position' => 0, 'end_position' => 17, 'text' => 'The plant closed.', 'stance' => 'supports']);
        CaseAssessment::create(['project_id' => $project->id, 'case_id' => $case->id, 'item_type' => 'concept', 'item_id' => $concept->id, 'state' => 'present']);
        $this->actingAs($user)->deleteJson(route('concepts.destroy', [$project, $concept]))->assertOk();
        $this->assertSame(0, AnalyticEvidence::count());
        $this->assertSame(0, CaseAssessment::count());
        $this->assertDatabaseMissing('notes', ['type' => 'concept', 'target' => $concept->id]);
    }
}
