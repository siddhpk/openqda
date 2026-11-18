<?php

namespace Tests\Feature;

use App\Events\ConversionCompleted;
use App\Jobs\ConvertFileToHtmlJob;
use App\Models\Project;
use App\Models\Source;
use App\Models\SourceStatus;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Desktop\Channel;
use App\Services\Desktop\DesktopState;
use App\Services\Desktop\DocumentConverter;
use App\Services\Desktop\SharingManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class DesktopSharingTest extends TestCase
{
    use RefreshDatabase;

    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = sys_get_temp_dir().'/openqda-state-'.Str::random(8).'.json';
        config([
            'desktop.state_file' => $this->stateFile,
            'desktop.shared_port' => 8766,
            'desktop.lan_port' => 8767,
            // any existing file stands in for the Tailscale CLI; calls are faked
            'desktop.tailscale.cli' => PHP_BINARY,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile.'.lock');
        parent::tearDown();
    }

    private function desktop(): void
    {
        config(['desktop.enabled' => true]);
    }

    private function as(string $channel): static
    {
        return $this->withServerVariables(['OPENQDA_CHANNEL' => $channel]);
    }

    /** Start LAN sharing directly and return the access code. */
    private function shareOnLan(): string
    {
        $this->app->make(DesktopState::class)->update(function (array $data) {
            $data['sharing'] = [
                'mode' => SharingManager::LAN,
                'session' => 'session-1',
                'code' => Crypt::encryptString('ABC-DEF-GHJ'),
                'url' => 'http://192.168.1.20:8767',
                'keep_on' => false,
                'started_at' => now()->toIso8601String(),
                'expires_at' => now()->addHours(8)->toIso8601String(),
                'failures' => 0,
            ];

            return $data;
        });

        return 'ABC-DEF-GHJ';
    }

    private function fakeTailscale(bool $funnelAllowed = true): void
    {
        Process::fake(function (PendingProcess $process) use ($funnelAllowed) {
            $cmd = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($cmd, 'status --json') && ! str_contains($cmd, 'funnel') => Process::result(json_encode([
                    'BackendState' => 'Running', 'Self' => ['DNSName' => 'laptop.tail1234.ts.net.'],
                ])),
                str_contains($cmd, 'funnel status --json') => Process::result(json_encode($funnelAllowed ? [
                    'AllowFunnel' => ['laptop.tail1234.ts.net:443' => true],
                    'Web' => ['laptop.tail1234.ts.net:443' => ['Handlers' => ['/' => ['Proxy' => 'http://127.0.0.1:8766']]]],
                ] : [])),
                str_contains($cmd, 'funnel --bg') => $funnelAllowed
                    ? Process::result('Available on the internet: https://laptop.tail1234.ts.net/')
                    : Process::result('Funnel is not enabled on your tailnet.'."\n".'To enable, visit: https://login.tailscale.com/f/funnel?node=abc123', '', 1),
                default => Process::result(''),
            };
        });
    }

    public function test_nothing_changes_outside_the_laptop_edition(): void
    {
        $user = User::factory()->create();

        $this->as('lan')->get('/share/enter')->assertNotFound();
        $this->actingAs($user)->as('local')->get(route('desktop.sharing'))->assertNotFound();
        $this->as('lan')->post('/desktop/shutdown')->assertNotFound();
        // the gate is inactive: the welcome page is served as usual
        $this->as('lan')->get('/')->assertOk();
    }

    public function test_visitors_see_a_closed_page_while_sharing_is_off(): void
    {
        $this->desktop();

        $this->as('lan')->get('/login')->assertStatus(503)->assertSee('Sharing is off');
        $this->as('shared')->get('/login')->assertStatus(503);
        // a missing channel value is treated as a visitor, never as the host
        $this->get('/login')->assertStatus(503);
    }

    public function test_visitors_must_enter_the_access_code_first(): void
    {
        $this->desktop();
        $code = $this->shareOnLan();

        $this->as('lan')->get('/login')->assertRedirect('/share/enter');
        $this->as('lan')->getJson('/projects')->assertStatus(401);
        $this->as('lan')->get('/share/enter')->assertOk()->assertSee('Access code');

        $this->as('lan')->post('/share/enter', ['code' => 'WRONG-CODE'])->assertRedirect('/share/enter');
        $this->as('lan')->get('/share/enter')->assertSee('not right');
        $this->assertSame(1, $this->app->make(DesktopState::class)->get('sharing.failures'));

        // lower case and without dashes is accepted
        $this->as('lan')->post('/share/enter', ['code' => strtolower(str_replace('-', '', $code))])->assertRedirect('/login');
        $this->as('lan')->get('/login')->assertOk();
    }

    public function test_the_internet_link_only_opens_the_funnel_channel_and_uses_https_urls(): void
    {
        $this->desktop();
        $this->fakeTailscale();
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->postJson(route('desktop.sharing.start'), ['mode' => 'internet'])
            ->assertOk()
            ->assertJsonPath('sharing.mode', 'internet')
            ->assertJsonPath('sharing.url', 'https://laptop.tail1234.ts.net');
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'funnel --bg --yes http://127.0.0.1:8766'));

        auth()->logout();
        $this->as('lan')->get('/login')->assertStatus(503);
        $this->as('shared')->get('/login')->assertRedirect('https://laptop.tail1234.ts.net/share/enter');
    }

    public function test_first_use_of_funnel_returns_the_tailscale_approval_link(): void
    {
        $this->desktop();
        $this->fakeTailscale(funnelAllowed: false);
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->postJson(route('desktop.sharing.start'), ['mode' => 'internet'])
            ->assertStatus(422)
            ->assertJsonPath('actionUrl', 'https://login.tailscale.com/f/funnel?node=abc123')
            ->assertJsonPath('sharing.mode', 'off');
    }

    public function test_only_the_host_can_manage_sharing_or_shut_down(): void
    {
        $this->desktop();
        $code = $this->shareOnLan();
        $user = User::factory()->create();

        $this->as('lan')->post('/share/enter', ['code' => $code]);
        $this->actingAs($user)->as('lan')->getJson(route('desktop.sharing.status'))->assertNotFound();
        $this->actingAs($user)->as('lan')->postJson(route('desktop.sharing.stop'))->assertNotFound();
        $this->as('lan')->postJson('/desktop/shutdown')->assertNotFound();

        $this->actingAs($user)->as('local')->getJson(route('desktop.sharing.status'))
            ->assertOk()
            ->assertJsonPath('sharing.mode', 'lan')
            ->assertJsonPath('sharing.code', $code)
            ->assertJsonPath('host', true);
    }

    public function test_stopping_and_restarting_locks_out_earlier_visitors(): void
    {
        $this->desktop();
        $code = $this->shareOnLan();
        $this->as('lan')->post('/share/enter', ['code' => $code]);
        $this->as('lan')->get('/login')->assertOk();

        $sharing = $this->app->make(SharingManager::class);
        $sharing->stop();
        $this->as('lan')->get('/login')->assertStatus(503);

        config(['desktop.lan_port' => 8767]);
        $this->shareOnLan();
        $this->app->make(DesktopState::class)->update(function (array $d) {
            $d['sharing']['session'] = 'session-2';

            return $d;
        });
        $this->as('lan')->get('/login')->assertRedirect('/share/enter');
    }

    public function test_guessing_stops_sharing(): void
    {
        $this->desktop();
        config(['desktop.sharing.max_failures' => 3, 'desktop.sharing.attempts_per_minute' => 100]);
        $this->shareOnLan();

        foreach (range(1, 3) as $i) {
            $this->as('lan')->post('/share/enter', ['code' => 'NOPE-'.$i]);
        }

        $this->assertSame('off', $this->app->make(SharingManager::class)->mode());
        $this->assertSame('too_many_wrong_codes', $this->app->make(DesktopState::class)->get('sharing.stopped_reason'));
    }

    public function test_sharing_ends_after_the_time_limit_unless_kept_on(): void
    {
        $this->desktop();
        $this->shareOnLan();
        $this->travel(9)->hours();

        $this->assertSame('off', $this->app->make(SharingManager::class)->mode());
        $this->as('lan')->get('/login')->assertStatus(503);
    }

    public function test_codes_are_readable_and_stored_encrypted(): void
    {
        $sharing = $this->app->make(SharingManager::class);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{3}-[A-HJKMNP-Z2-9]{3}-[A-HJKMNP-Z2-9]{3}$/', $sharing->newCode());

        $this->desktop();
        $this->shareOnLan();
        $raw = file_get_contents($this->stateFile);
        $this->assertStringNotContainsString('ABC-DEF-GHJ', $raw);
    }

    public function test_host_settings_and_shutdown_are_written_for_the_launcher(): void
    {
        $this->desktop();
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->postJson(route('desktop.autostart'), ['enabled' => true])
            ->assertOk()->assertJsonPath('sharing.autostart', true);
        $this->as('local')->postJson('/desktop/shutdown')->assertOk();

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['autostart']);
        $this->assertNotEmpty($state['shutdown']);
    }

    public function test_registration_is_confirmed_without_email_in_the_laptop_edition(): void
    {
        $this->desktop();
        $user = User::factory()->unverified()->create();

        event(new Registered($user));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_documents_are_converted_with_the_bundled_pandoc(): void
    {
        config(['desktop.pandoc' => '/opt/openqda/bin/pandoc', 'internalPlugins.rtf.endpoint' => null]);
        $input = tempnam(sys_get_temp_dir(), 'doc').'.docx';
        file_put_contents($input, 'fake');
        $source = Source::factory()->create();
        $projectId = $source->project_id;
        Event::fake([ConversionCompleted::class]);
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            file_put_contents(end($command), '<html><style>body{}</style><p>Interview text</p></html>');

            return Process::result('');
        });

        (new ConvertFileToHtmlJob($input, $projectId, $source->id, 'Interview'))->handle();

        Process::assertRan(fn (PendingProcess $p) => ((array) $p->command)[0] === '/opt/openqda/bin/pandoc'
            && in_array('html5', (array) $p->command, true));
        $html = file_get_contents(storage_path('app/projects/'.$projectId.'/sources/'.pathinfo($input, PATHINFO_FILENAME).'.html'));
        $this->assertStringContainsString('Interview text', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertDatabaseHas('source_statuses', ['source_id' => $source->id, 'status' => 'converted:html']);
        @unlink($input);
    }

    public function test_documents_are_converted_inside_php_when_no_pandoc_is_configured(): void
    {
        $this->desktop();
        config(['desktop.pandoc' => null, 'internalPlugins.rtf.endpoint' => null]);
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTitle('Interview with Dana', 1);
        $section->addText('After the plant closed my salary was gone.');
        $section->addText('Nobody understood my accent at the job centre.', ['bold' => true]);
        $docx = sys_get_temp_dir().'/interview-'.Str::random(6).'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($docx);

        $converter = $this->app->make(DocumentConverter::class);
        $html = $converter->toHtml($docx);
        $this->assertStringContainsString('After the plant closed my salary was gone.', $html);
        $this->assertStringContainsString('job centre', $html);
        $this->assertStringNotContainsString('<style', $html);

        $md = sys_get_temp_dir().'/notes-'.Str::random(6).'.md';
        file_put_contents($md, "# Notes\n\n<script>alert(1)</script>\n\n*first* contact");
        $mdHtml = $converter->toHtml($md);
        $this->assertStringContainsString('<h1>Notes</h1>', $mdHtml);
        $this->assertStringNotContainsString('<script>', $mdHtml);

        $source = Source::factory()->create();
        Event::fake([ConversionCompleted::class]);
        (new ConvertFileToHtmlJob($docx, $source->project_id, $source->id, 'Interview'))->handle();
        $this->assertDatabaseHas('source_statuses', ['source_id' => $source->id, 'status' => 'converted:html']);
        @unlink($docx);
        @unlink($md);
    }

    public function test_visitors_can_register_without_the_browser_proof_of_work(): void
    {
        $this->desktop();
        $code = $this->shareOnLan();
        $this->as('lan')->post('/share/enter', ['code' => $code]);

        $this->as('lan')->post('/register', [
            'name' => 'Sam Visitor',
            'email' => 'sam@example.org',
            'password' => 'Correct-Horse-42!',
            'password_confirmation' => 'Correct-Horse-42!',
            'terms' => true,
            'privacy' => true,
        ])->assertRedirect();

        $user = User::where('email', 'sam@example.org')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_an_uploaded_word_file_is_ready_to_code_right_away(): void
    {
        $this->desktop();
        config(['desktop.pandoc' => null, 'internalPlugins.rtf.formats' => 'docx,odt,rtf,doc,md,html', 'queue.default' => 'sync']);
        Event::fake([ConversionCompleted::class]);
        $user = User::factory()->create();
        $project = Project::factory()->create(['creating_user_id' => $user->id]);
        $word = new PhpWord;
        $word->addSection()->addText('The family next door brought cake on our first day.');
        $docx = sys_get_temp_dir().'/upload-'.Str::random(6).'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($docx);
        $file = new UploadedFile($docx, 'Interview Dana.docx', null, null, true);

        $this->actingAs($user)->as('local')->post(route('source.store'), ['projectId' => $project->id, 'file' => $file])->assertOk();

        $source = Source::where('project_id', $project->id)->firstOrFail();
        $status = SourceStatus::where('source_id', $source->id)->firstOrFail();
        $this->assertSame('converted:html', $status->status);
        $this->assertStringContainsString('brought cake', file_get_contents($status->path));
        @unlink($docx);
    }

    private function fakeOllama(array $models = ['nomic-embed-text:latest']): void
    {
        Http::fake([
            '127.0.0.1:11434/api/tags' => Http::response(['models' => array_map(fn ($m) => ['name' => $m], $models)]),
            '127.0.0.1:11434/api/pull' => Http::response(['status' => 'success']),
        ]);
    }

    public function test_the_host_turns_autocoding_on_with_the_local_ollama(): void
    {
        $this->needsAutocoding();
        $this->desktop();
        $this->fakeOllama();
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->getJson(route('desktop.sharing.status'))
            ->assertOk()
            ->assertJsonPath('ai.enabled', false)
            ->assertJsonPath('ai.ollama', true)
            ->assertJsonPath('ai.models.0.name', 'nomic-embed-text')
            ->assertJsonPath('ai.models.0.installed', true);

        $this->actingAs($user)->as('local')->postJson(route('desktop.ai'), ['enabled' => true, 'model' => 'nomic-embed-text'])
            ->assertOk()
            ->assertJsonPath('ai.enabled', true);
        $this->assertSame(['enabled' => true, 'model' => 'nomic-embed-text', 'base_url' => 'http://127.0.0.1:11434'],
            $this->app->make(DesktopState::class)->get('ai'));

        // a new request (new app boot) picks the setting up: the coding page sees AI as available
        $this->refreshApplication();
        config(['desktop.enabled' => true, 'desktop.state_file' => $this->stateFile]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertTrue(config('ai.enabled'));
        $this->assertTrue(config('ai.local_only'));
        $this->assertSame('ollama', config('ai.embedding.provider'));
        $this->assertSame('http://127.0.0.1:11434', config('ai.providers.ollama.base_url'));
    }

    public function test_autocoding_cannot_be_turned_on_without_ollama_or_the_model(): void
    {
        $this->needsAutocoding();
        $this->desktop();
        $user = User::factory()->create();

        $running = false;
        Http::fake(function () use (&$running) {
            return $running ? Http::response(['models' => [['name' => 'all-minilm:latest']]]) : Http::response('', 500);
        });
        $this->actingAs($user)->as('local')->postJson(route('desktop.ai'), ['enabled' => true, 'model' => 'nomic-embed-text'])
            ->assertStatus(422)->assertJsonPath('ai.enabled', false)->assertJsonPath('ai.ollama', false);

        $running = true;
        $this->actingAs($user)->as('local')->postJson(route('desktop.ai'), ['enabled' => true, 'model' => 'nomic-embed-text'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Download the model "nomic-embed-text" first.']);
    }

    public function test_the_host_can_download_a_model_and_visitors_cannot_touch_ai_settings(): void
    {
        $this->needsAutocoding();
        $this->desktop();
        $this->fakeOllama([]);
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->postJson(route('desktop.ai.pull'), ['model' => 'nomic-embed-text'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/pull') && $r['model'] === 'nomic-embed-text' && $r['stream'] === false);

        $this->actingAs($user)->as('local')->postJson(route('desktop.ai.pull'), ['model' => 'bad model; rm -rf'])->assertStatus(422);

        $code = $this->shareOnLan();
        $this->as('lan')->post('/share/enter', ['code' => $code]);
        $this->actingAs($user)->as('lan')->postJson(route('desktop.ai'), ['enabled' => true, 'model' => 'nomic-embed-text'])->assertNotFound();
        $this->actingAs($user)->as('lan')->postJson(route('desktop.ai.pull'), ['model' => 'nomic-embed-text'])->assertNotFound();
    }

    public function test_without_the_autocoding_module_the_sharing_page_offers_no_autocoding(): void
    {
        if (Channel::autocodingInstalled()) {
            $this->markTestSkipped('The Embeddings & Autocoding module is installed.');
        }
        $this->desktop();
        $user = User::factory()->create();

        $this->actingAs($user)->as('local')->getJson(route('desktop.sharing.status'))
            ->assertOk()
            ->assertJsonPath('ai.installed', false);
        $this->actingAs($user)->as('local')->postJson(route('desktop.ai'), ['enabled' => true, 'model' => 'nomic-embed-text'])
            ->assertNotFound();
        $this->actingAs($user)->as('local')->postJson(route('desktop.ai.pull'), ['model' => 'nomic-embed-text'])
            ->assertNotFound();
    }

    private function needsAutocoding(): void
    {
        if (! Channel::autocodingInstalled()) {
            $this->markTestSkipped('Needs the Embeddings & Autocoding module.');
        }
    }
}
