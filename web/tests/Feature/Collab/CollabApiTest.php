<?php

namespace Tests\Feature\Collab;

use App\Models\Code;
use App\Models\Codebook;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use App\Services\Collab\Collab;
use App\Services\Collab\Docs;
use App\Services\Collab\IdMap;
use App\Services\Collab\Invites;
use App\Services\Collab\LiveTokens;
use App\Services\Collab\NodeIdentity;
use App\Services\Collab\OpLog;
use App\Services\Collab\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CollabApiTest extends CollabTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->addNode('alice', '100.64.0.1');
        $this->addNode('bob', '100.64.0.2');
    }

    private function agent(): static
    {
        return $this->withServerVariables(['OPENQDA_CHANNEL' => 'collab'])->withHeaders(['X-OpenQDA-Gateway' => 'gateway-token']);
    }

    public function test_the_storage_api_needs_the_collab_channel_and_the_token(): void
    {
        $this->on('alice');
        $this->agent()->getJson('/_collab/local/roster')->assertOk()->assertJsonPath('node', Collab::node());
        $this->withServerVariables(['OPENQDA_CHANNEL' => 'collab'])->withHeaders(['X-OpenQDA-Gateway' => 'wrong'])
            ->getJson('/_collab/local/roster')->assertForbidden();
        $this->withServerVariables(['OPENQDA_CHANNEL' => 'local'])->withHeaders(['X-OpenQDA-Gateway' => 'gateway-token'])
            ->getJson('/_collab/local/roster')->assertForbidden();
        $this->withServerVariables(['OPENQDA_CHANNEL' => 'lan'])->withHeaders(['X-OpenQDA-Gateway' => 'gateway-token'])
            ->getJson('/_collab/local/roster')->assertForbidden();
        // and the collab listener serves no pages at all
        $this->withServerVariables(['OPENQDA_CHANNEL' => 'collab'])->get('/login')->assertNotFound();
        // outside the laptop edition nothing answers
        config(['desktop.enabled' => false]);
        $this->agent()->getJson('/_collab/local/roster')->assertForbidden();
    }

    public function test_the_agent_api_replicates_and_reports_version_vectors(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $book = Codebook::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id]);
        Code::factory()->create(['codebook_id' => $book->id, 'name' => 'Trust']);
        $gid = Seeder::enable($project);
        $node = Collab::node();

        $vv = $this->agent()->getJson("/_collab/local/vv?project={$gid}")->assertOk()->json('vv');
        $this->assertGreaterThan(2, $vv[$node]);
        $page = $this->agent()->postJson('/_collab/local/ops', ['project' => $gid, 'vv' => (object) [], 'limit' => 2])->assertOk();
        $this->assertCount(2, $page->json('ops'));
        $this->assertTrue($page->json('more'));
        $none = $this->agent()->postJson('/_collab/local/ops', ['project' => $gid, 'vv' => $vv])->json('ops');
        $this->assertSame([], $none);
        // an empty version vector is a JSON object (the agent decodes a map)
        $this->assertStringContainsString('"vv":{}', $this->agent()->getJson('/_collab/local/vv?project=00000000-0000-0000-0000-000000000000')->getContent());

        // a forged op (wrong signature) is ignored, a valid one applied once
        $ops = OpLog::since($gid, [], 1000)['ops'];
        $this->on('bob');
        $bob = User::factory()->create();
        DB::table('projects')->insert(['id' => 50, 'name' => 'copy', 'creating_user_id' => $bob->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('collab_projects')->insert(['project_id' => 50, 'gid' => $gid, 'founder' => $node,
            'founder_pub' => NodeIdentity::fromSeed($this->nodes['alice']['seed'])->pub, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        IdMap::put('projects', 50, $gid);
        $forged = $ops;
        $forged[1]['f'] = ['name' => 'Forged'];
        $res = $this->agent()->postJson('/_collab/local/apply', ['from' => $node, 'project' => $gid, 'ops' => [$forged[1]]])->assertOk();
        $this->assertSame([], $res->json('new'));
        $res = $this->agent()->postJson('/_collab/local/apply', ['from' => $node, 'project' => $gid, 'ops' => $ops])->assertOk();
        $this->assertCount(count($ops), $res->json('new'));
        $this->assertSame('Trust', DB::table('codes')->value('name'));
        $again = $this->agent()->postJson('/_collab/local/apply', ['from' => $node, 'project' => $gid, 'ops' => $ops])->json('new');
        $this->assertSame([], $again, 'ops arrive any number of times');
        $this->assertSame('Interviews', DB::table('projects')->where('id', 50)->value('name'));
    }

    public function test_invitations_are_single_use_and_only_from_primaries(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        $text = Invites::create($project, $project->creating_user_id, 'assistant', 'none')['text'];
        $payload = json_decode(Invites::unb64url(substr($text, strlen(Invites::PREFIX))), true);
        $this->assertSame('assistant', $payload['r']);
        $this->assertNull($payload['k']);

        $bob = NodeIdentity::fromSeed($this->nodes['bob']['seed']);
        $request = ['node' => $bob->node, 'pub' => $bob->pub, 'name' => 'Bob', 'addr' => '100.64.0.2', 'dns' => '',
            'invite' => ['p' => $payload['p'], 't' => $payload['t'], 'name' => 'Bob']];
        // a guessed secret
        $guess = $request;
        $guess['invite']['t'] = Invites::b64url(random_bytes(32));
        $this->agent()->postJson('/_collab/local/join', $guess)->assertForbidden();
        // a key that does not belong to the node id
        $mismatch = $request;
        $mismatch['pub'] = NodeIdentity::fromSeed($this->nodes['alice']['seed'])->pub;
        $this->agent()->postJson('/_collab/local/join', $mismatch)->assertForbidden();
        // the real one, then nobody else can use it
        $welcome = $this->agent()->postJson('/_collab/local/join', $request)->assertOk()->json('welcome');
        $this->assertSame('assistant', $welcome['role']);
        $this->assertNotEmpty($welcome['roster']);
        $carol = NodeIdentity::fromSeed(base64_encode(random_bytes(32)));
        $reuse = array_merge($request, ['node' => $carol->node, 'pub' => $carol->pub]);
        $this->agent()->postJson('/_collab/local/join', $reuse)->assertForbidden();

        // an Assistant cannot invite (on Bob's laptop after joining)
        $this->on('bob');
        $this->expectException(ValidationException::class);
        $local = User::factory()->create();
        DB::table('projects')->insert(['id' => 7, 'name' => 'x', 'creating_user_id' => $local->id, 'created_at' => now(), 'updated_at' => now()]);
        Invites::create(Project::find(7), $local->id, 'assistant', 'none');
    }

    public function test_an_invitation_text_is_checked_before_anything_happens(): void
    {
        $this->on('bob');
        foreach (['hello', Invites::PREFIX.'not-base64!!', Invites::PREFIX.Invites::b64url('{"v":2}')] as $bad) {
            try {
                Invites::parse($bad);
                $this->fail('accepted '.$bad);
            } catch (ValidationException $e) {
                $this->assertStringContainsString('not an OpenQDA invitation', $e->getMessage());
            }
        }
    }

    public function test_one_click_invitations_create_a_tagged_single_use_key(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        Collab::putSecret('oauth_client_id', 'k123');
        Collab::putSecret('oauth_client_secret', 'tskey-client-secret');
        Http::fake([
            'api.tailscale.com/api/v2/oauth/token' => Http::response(['access_token' => 'at']),
            'api.tailscale.com/api/v2/tailnet/-/keys' => Http::response(['key' => 'tskey-auth-abc123-XYZ']),
        ]);
        $text = Invites::create($project, $project->creating_user_id, 'primary', 'auto')['text'];
        $this->on('bob');
        $this->assertSame('tskey-auth-abc123-XYZ', Invites::parse($text)['k']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/keys')
            && $r['capabilities']['devices']['create'] === ['reusable' => false, 'ephemeral' => false, 'preauthorized' => true, 'tags' => ['tag:openqda']]);
        $this->on('alice');
        $this->assertSame('auto', DB::table('collab_invites')->latest('id')->value('key_mode'));
    }

    public function test_sharing_invitations_keep_everyone_in_their_own_network(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        try {
            Invites::create($project, $project->creating_user_id, 'assistant', 'share', null, 'not a link');
            $this->fail('a share link is required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('share link', $e->getMessage());
        }
        $link = 'https://login.tailscale.com/admin/invite/abc123XYZ';
        $text = Invites::create($project, $project->creating_user_id, 'assistant', 'share', null, $link)['text'];
        $this->assertSame('share', DB::table('collab_invites')->latest('id')->value('key_mode'));

        // Bob is signed in to his own network: no key, no moving, no admin of Alice's
        $this->tailnetName = 'bob-home.example';
        $this->on('bob');
        $user = User::factory()->create();
        $r = Invites::accept($user->id, $text);
        $this->assertSame('needs_share', $r['status']);
        $this->assertSame($link, $r['share_link']);
        $this->assertCount(1, Invites::pendingJoins(), 'OpenQDA connects as soon as the share is accepted');
        $this->assertSame($link, Invites::joinsOf($user->id)[0]['share_link']);
        Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/v1/tailscale/authkey'));
    }

    public function test_accepting_a_shared_computer_needs_your_own_signed_in_account(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        $text = Invites::create($project, $project->creating_user_id, 'assistant', 'share', null, 'https://login.tailscale.com/admin/invite/x1y2')['text'];
        $this->on('bob');
        $user = User::factory()->create();
        $this->tailnetState = 'NeedsLogin';
        $this->assertSame('needs_signin', Invites::accept($user->id, $text)['status']);
        $this->assertSame([], Invites::pendingJoins());
    }

    public function test_joining_another_network_with_a_key_asks_first(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        Seeder::enable($project);
        $text = Invites::create($project, $project->creating_user_id, 'assistant', 'paste', 'tskey-auth-kPASTED-abc')['text'];
        // Bob's computer is signed in to a different network
        $this->tailnetName = 'home.example';
        $this->on('bob');
        $user = User::factory()->create();
        $r = Invites::accept($user->id, $text);
        $this->assertSame('needs_confirm', $r['status']);
        $this->assertSame('home.example', $r['current']);
        $this->assertSame([], Invites::pendingJoins());
        $r = Invites::accept($user->id, $text, true);
        $this->assertSame('waiting', $r['status']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/tailscale/authkey') && $req['key'] === 'tskey-auth-kPASTED-abc');
    }

    public function test_co_editing_documents_follow_roles_and_carry_updates(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $memo = Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 1, 'type' => 'project', 'target' => (string) $project->id, 'content' => 'Shared memo']);
        Seeder::enable($project);
        ['user' => $bob] = $this->join('alice', $project, 'bob');

        $this->on('alice');
        $owner = User::find($project->creating_user_id);
        $doc = $this->host($owner)->getJson('/collab/docs?key=notes:'.$memo->id.':content')->assertOk()->json();
        $this->assertTrue($doc['collaborative']);
        $this->assertTrue($doc['can_edit']);
        $this->assertSame(hash('sha256', 'Shared memo'), $doc['gen'], 'a new generation starts from the text');
        $this->assertSame([], $doc['updates']);
        $this->postJson('/collab/docs/updates', ['doc' => $doc['doc'], 'gen' => $doc['gen'], 'updates' => [base64_encode("\x01\x02")]])->assertOk();
        $this->assertSame(['nodes' => [Collab::node()]], ['nodes' => Docs::info($doc['doc'])['editors']]);

        // the save leader saves the merged text, naming the generation
        $this->withHeaders(['X-OpenQDA-YDoc' => $doc['doc'].'@'.$doc['gen']])
            ->patchJson("/projects/{$project->id}/notes/{$memo->id}", ['content' => 'Shared memo, edited together'])->assertSuccessful();
        $this->sync('alice', 'bob');

        // Bob (Assistant) sees the text and the updates, but may not edit it live
        $bobMemo = DB::table('notes')->where('content', 'Shared memo, edited together')->value('id');
        $this->assertNotNull($bobMemo);
        $bobDoc = $this->host($bob)->getJson('/collab/docs?key=notes:'.$bobMemo.':content')->assertOk()->json();
        $this->assertSame($doc['gen'], $bobDoc['gen'], 'the same document continues on the other laptop');
        $this->assertSame([base64_encode("\x01\x02")], $bobDoc['updates']);
        $this->assertFalse($bobDoc['can_edit']);
        $this->postJson('/collab/docs/updates', ['doc' => $bobDoc['doc'], 'gen' => $bobDoc['gen'], 'updates' => [base64_encode('x')]])->assertForbidden();
    }

    public function test_local_requests_hand_their_changes_to_the_agent(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $memo = Note::factory()->create(['project_id' => $project->id, 'creating_user_id' => $project->creating_user_id,
            'visibility' => 1, 'type' => 'project', 'target' => (string) $project->id, 'content' => 'Before']);
        Seeder::enable($project);
        $this->published = [];
        $this->host(User::find($project->creating_user_id))
            ->patchJson("/projects/{$project->id}/notes/{$memo->id}", ['content' => 'After'])->assertSuccessful();
        $ops = collect($this->published)->flatten(1)->pluck('ops')->flatten(1);
        $this->assertTrue($ops->contains(fn ($op) => $op['t'] === 'notes' && ($op['f']['content'] ?? null) === 'After'));
    }

    public function test_live_tokens_are_signed_with_the_shared_key(): void
    {
        $this->on('alice');
        $project = $this->makeProject();
        $gid = Seeder::enable($project);
        $owner = User::find($project->creating_user_id);
        $live = $this->host($owner)->getJson("/projects/{$project->id}/collab/live")->assertOk()->json();
        $this->assertTrue($live['enabled']);
        $this->assertSame('/_live', $live['url']);
        $this->assertSame('primary', $live['role']);
        [$body, $sig] = explode('.', $live['token']);
        $key = hash_hmac('sha256', 'live-token-v1', 'gateway-token', true);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '='), $sig);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertEquals(['k' => 'live', 'p' => $gid, 'n' => 'Alice Researcher'], array_intersect_key($payload, ['k' => 1, 'p' => 1, 'n' => 1]));
        // a project that is not collaborative has no live channel
        $other = Project::factory()->create(['creating_user_id' => $owner->id]);
        $this->getJson("/projects/{$other->id}/collab/live")->assertOk()->assertJson(['enabled' => false]);
    }

    /**
     * Values computed by the PHP side that the Go agent checks with the same
     * inputs (desktop/launcher/cluster/vectors_test.go).
     */
    public function test_cross_language_vectors(): void
    {
        $seed = base64_encode(str_repeat("\x07", 32));
        $identity = NodeIdentity::fromSeed($seed);
        $this->assertSame('nfe812c12f3ab4ce6ac5d', $identity->node);
        $this->assertSame('6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=', $identity->pub);
        $this->assertSame('vpDcqRzM5+Z1uFg5ITxqKOH9Og7er83qM39fKtWAX6KLMpZrmcoN09LWVkHLHPrFNcttHoFhTGZfIYiPRGodBg==', $identity->sign('openqda-vector'));
        config(['collab.control_token' => 'vector-token']);
        $this->assertSame(
            'eyJrIjoibGl2ZSIsInUiOiJ1MSIsInAiOiJwMSIsImUiOjQxMDI0NDQ4MDB9.CAlLEGyrc8P8mYxaVLnHJlFTLmQUXcLo2Zhe2cHVLQY',
            LiveTokens::sign(['k' => 'live', 'u' => 'u1', 'p' => 'p1', 'e' => 4102444800])
        );
    }
}
