<?php

namespace App\Http\Controllers;

use App\Services\Desktop\Channel;
use App\Services\Desktop\DesktopState;
use App\Services\Desktop\Ollama;
use App\Services\Desktop\SharingManager;
use App\Services\Desktop\Tailscale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Laptop edition: sharing controls (host only) and the access-code page
 * (visitors). None of these routes exist outside the laptop edition.
 */
class DesktopController extends Controller
{
    public function __construct(
        private SharingManager $sharing,
        private Tailscale $tailscale,
        private Ollama $ollama,
        private DesktopState $state,
    ) {}

    public function show()
    {
        return Inertia::render('SharingPage', ['initial' => $this->payload()]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([SharingManager::INTERNET, SharingManager::LAN])],
            'keep_on' => ['sometimes', 'boolean'],
        ]);
        $result = $this->sharing->start($data['mode'], (bool) ($data['keep_on'] ?? false));

        return response()->json($result + $this->payload(), $result['ok'] ? 200 : 422);
    }

    public function stop(): JsonResponse
    {
        $this->sharing->stop();

        return response()->json($this->payload());
    }

    public function rotate(): JsonResponse
    {
        $this->sharing->rotateCode();

        return response()->json($this->payload());
    }

    public function autostart(Request $request): JsonResponse
    {
        $this->sharing->setAutostart((bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled']);

        return response()->json($this->payload());
    }

    /**
     * Turn autocoding (embeddings through the local Ollama) on or off.
     */
    public function ai(Request $request): JsonResponse
    {
        abort_unless(Channel::autocodingInstalled(), 404);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'model' => ['required_if:enabled,true', 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
        ]);
        $model = $data['model'] ?? config('ai.embedding.model');
        if ($data['enabled']) {
            $status = $this->ollama->status();
            if (! $status['running']) {
                return response()->json(['message' => 'Ollama is not running. Install or start Ollama first.'] + $this->payload(), 422);
            }
            if (! Ollama::installed($status['models'], $model)) {
                return response()->json(['message' => "Download the model \"{$model}\" first."] + $this->payload(), 422);
            }
        }
        $this->state->update(function (array $state) use ($data, $model) {
            $state['ai'] = ['enabled' => (bool) $data['enabled'], 'model' => $model, 'base_url' => Ollama::DEFAULT_URL];

            return $state;
        });
        // apply right away for this response
        config(['ai.enabled' => (bool) $data['enabled'], 'ai.embedding.provider' => 'ollama', 'ai.embedding.model' => $model, 'ai.providers.ollama.base_url' => Ollama::DEFAULT_URL]);

        return response()->json($this->payload());
    }

    public function pullModel(Request $request): JsonResponse
    {
        abort_unless(Channel::autocodingInstalled(), 404);
        $model = (string) $request->validate([
            'model' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
        ])['model'];
        $result = $this->ollama->pull($model);

        return response()->json($result + $this->payload(), $result['ok'] ? 200 : 422);
    }

    public function shutdown(): JsonResponse
    {
        $this->sharing->requestShutdown();

        return response()->json(['message' => 'OpenQDA is shutting down. You can close this tab.']);
    }

    public function gate(Request $request)
    {
        abort_unless(Channel::desktop(), 404);
        if ($this->sharing->admitted($request)) {
            return redirect('/');
        }

        return response()->view('desktop.gate', ['error' => session('gate_error')]);
    }

    public function enter(Request $request)
    {
        abort_unless(Channel::desktop(), 404);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        $result = $this->sharing->attempt($request, $code);
        if (! $result['ok']) {
            return redirect('/share/enter')->with('gate_error', $result['message']);
        }

        return redirect()->intended('/login');
    }

    private function payload(): array
    {
        return [
            'sharing' => $this->sharing->forHost(),
            'tailscale' => $this->tailscale->status(),
            'lanAddress' => $this->sharing->lanAddress(),
            'maxHours' => (int) config('desktop.sharing.max_hours', 8),
            'host' => Channel::isHost(request()),
            'ai' => $this->aiPayload(),
        ];
    }

    private function aiPayload(): array
    {
        if (! Channel::autocodingInstalled()) {
            return ['installed' => false, 'enabled' => false, 'ollama' => false, 'models' => []];
        }
        $status = $this->ollama->status();
        $current = (string) config('ai.embedding.model');
        $models = Ollama::MODELS;
        if ($current && ! array_key_exists($current, $models)) {
            $models = [$current => 'Configured model'] + $models;
        }

        return [
            'installed' => true,
            'enabled' => (bool) config('ai.enabled'),
            'provider' => config('ai.embedding.provider'),
            'model' => $current,
            'ollama' => $status['running'],
            'models' => collect($models)->map(fn ($label, $name) => [
                'name' => $name,
                'label' => $label,
                'installed' => Ollama::installed($status['models'], $name),
            ])->values()->all(),
        ];
    }
}
