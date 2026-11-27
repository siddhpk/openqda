<?php

namespace Tests\Feature\Collab;

use App\Models\Project;
use App\Models\User;
use App\Services\Collab\Applier;
use App\Services\Collab\Blobs;
use App\Services\Collab\ChangeCapture;
use App\Services\Collab\Collab;
use App\Services\Collab\Invites;
use App\Services\Collab\NodeIdentity;
use App\Services\Collab\OpLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Several laptops in one test: every node has its own SQLite database (with
 * the capture triggers), storage folder and node key. sync() does what the
 * node agents do between two online laptops: compare version vectors, apply
 * the missing operations and copy the missing files.
 */
abstract class CollabTestCase extends TestCase
{
    protected static ?string $template = null;

    protected array $nodes = [];

    protected ?string $current = null;

    public array $published = [];

    /** The Tailscale network the fake node agent reports. */
    public string $tailnetName = 'lab.example';

    /** The Tailscale state the fake node agent reports. */
    public string $tailnetState = 'Running';

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_seed_keypair') || ! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The laptop collaboration needs the sodium and pdo_sqlite extensions.');
        }
        config([
            'desktop.enabled' => true,
            'collab.control_url' => 'http://control.test',
            'collab.control_token' => 'gateway-token',
            'database.default' => 'sqlite',
        ]);
        $test = $this;
        Http::fake(['control.test/*' => function (Request $request) use ($test) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/ops') {
                $test->published[] = $request->data()['batches'] ?? [];
            }
            if ($path === '/v1/status') {
                return Http::response(['tailscale' => ['state' => $test->tailnetState, 'ip' => $test->nodes[$test->current]['ip'] ?? '100.64.0.1',
                    'dns' => "openqda-{$test->current}.lab.ts.net", 'tailnet' => $test->tailnetName], 'cluster' => ['peers' => [], 'presence' => []]]);
            }

            return Http::response(['ok' => true]);
        }]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach ($this->nodes as $n) {
            @unlink($n['db']);
            File::deleteDirectory($n['storage']);
        }
        Collab::reset();
        parent::tearDown();
    }

    /** One migrated database per test run, copied for every node. */
    protected function template(): string
    {
        if (self::$template && is_file(self::$template)) {
            return self::$template;
        }
        $path = sys_get_temp_dir().'/openqda-collab-template-'.getmypid().'.sqlite';
        @unlink($path);
        touch($path);
        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        DB::purge('sqlite');

        return self::$template = $path;
    }

    protected function addNode(string $name, string $ip): void
    {
        $db = sys_get_temp_dir().'/openqda-collab-'.$name.'-'.Str::random(8).'.sqlite';
        copy($this->template(), $db);
        $storage = sys_get_temp_dir().'/openqda-collab-'.$name.'-'.Str::random(8);
        foreach (['app', 'logs', 'framework/views', 'framework/cache/data'] as $dir) {
            mkdir("{$storage}/{$dir}", 0o777, true);
        }
        $this->nodes[$name] = ['db' => $db, 'seed' => base64_encode(random_bytes(32)), 'storage' => $storage, 'ip' => $ip];
        $this->on($name);
    }

    /** Continue as the given laptop. */
    protected function on(string $name): static
    {
        $n = $this->nodes[$name];
        config(['database.connections.sqlite.database' => $n['db'], 'collab.node_key' => $n['seed']]);
        DB::purge('sqlite');
        $this->app->useStoragePath($n['storage']);
        Collab::reset();
        $this->current = $name;

        return $this;
    }

    protected function nodeId(string $name): string
    {
        return NodeIdentity::fromSeed($this->nodes[$name]['seed'])->node;
    }

    protected function flush(): array
    {
        return ChangeCapture::flush();
    }

    /** What two connected node agents do: operations (both ways) and files. */
    protected function sync(string $from, string $to): array
    {
        $events = [];
        $this->on($to);
        $projects = DB::table('collab_projects')->where('status', 'active')->pluck('gid')->all();
        foreach ($projects as $gid) {
            $this->on($to);
            $vv = OpLog::vv($gid);
            $this->on($from);
            if (! Collab::projectId($gid)) {
                continue;
            }
            $page = OpLog::since($gid, $vv, 100000);
            $this->on($to);
            // through JSON, like the real agents
            $res = Applier::apply($gid, json_decode(json_encode($page['ops']), true));
            $events = array_merge($events, $res['events']);
        }
        $this->on($to);
        $toNode = $this->nodeId($to);
        foreach (Blobs::missing() as $m) {
            $this->on($from);
            $path = Blobs::locate($m['sha'], $toNode);
            if (! $path) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'blob');
            copy($path, $tmp);
            $this->on($to);
            Blobs::place($m['sha'], $tmp);
            unlink($tmp);
        }
        $this->on($to);

        return $events;
    }

    protected function syncBoth(string $a, string $b): void
    {
        $this->sync($a, $b);
        $this->sync($b, $a);
    }

    /** Requests from the person at this computer (the local listener). */
    protected function host(User $user): static
    {
        return $this->actingAs($user)->withServerVariables(['OPENQDA_CHANNEL' => 'local']);
    }

    /** A user and a project on the current laptop. */
    protected function makeProject(string $name = 'Interviews'): Project
    {
        $owner = User::factory()->create(['name' => 'Alice Researcher']);

        return Project::factory()->create(['creating_user_id' => $owner->id, 'name' => $name, 'description' => 'Study on trust']);
    }

    /**
     * $joiner accepts an invitation from $primary (as the agents would carry it).
     *
     * @return array{project: int, user: User}
     */
    protected function join(string $primary, Project $project, string $joiner, string $role = 'assistant', string $userName = 'Bob Assistant'): array
    {
        $this->on($primary);
        $text = Invites::create($project, $project->creating_user_id, $role, 'none')['text'];
        $this->on($joiner);
        $user = User::factory()->create(['name' => $userName]);
        $accepted = Invites::accept($user->id, $text);
        $this->assertSame('waiting', $accepted['status']);
        $join = Invites::pendingJoins()[0];
        $identity = NodeIdentity::fromSeed($this->nodes[$joiner]['seed']);
        $this->on($primary);
        $welcome = Invites::join(json_decode(json_encode(['node' => $identity->node, 'pub' => $identity->pub, 'name' => $userName,
            'addr' => $this->nodes[$joiner]['ip'], 'dns' => '', 'invite' => $join['invite']]), true));
        $this->on($joiner);
        Invites::joined($join['id'], json_decode(json_encode($welcome), true));
        $this->sync($primary, $joiner);

        return ['project' => Collab::projectId($join['project']), 'user' => $user];
    }

    /** Write a text source with its converted HTML, as an upload would. */
    protected function textSource(Project $project, string $text): string
    {
        $id = (string) Str::uuid();
        $dir = storage_path("app/projects/{$project->id}/sources");
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/{$id}.txt", $text);
        file_put_contents("{$dir}/{$id}.html", '<p>'.e($text).'</p>');
        DB::table('sources')->insert(['id' => $id, 'name' => 'Interview 1', 'description' => null, 'type' => 'text',
            'creating_user_id' => $project->creating_user_id, 'project_id' => $project->id, 'upload_path' => "{$dir}/{$id}.txt",
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('source_statuses')->insert(['id' => (string) Str::uuid(), 'source_id' => $id, 'status' => 'converted:html',
            'path' => "{$dir}/{$id}.html", 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
