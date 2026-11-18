<?php

namespace App\Providers;

use App\Services\Desktop\Channel;
use App\Services\Desktop\DesktopState;
use App\Services\Desktop\Ollama;
use App\Services\SourceService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('App\Services\SourceService', function ($app) {
            return new SourceService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyDesktopAiSettings();
    }

    /**
     * Laptop edition: autocoding (embeddings) is switched on and off on the
     * Sharing page, which stores the choice in the launcher's state file.
     * It overrides the AI_* environment settings and always uses the local
     * Ollama, so nothing leaves the computer.
     */
    private function applyDesktopAiSettings(): void
    {
        if (! config('desktop.enabled') || ! Channel::autocodingInstalled()) {
            return;
        }
        $ai = $this->app->make(DesktopState::class)->get('ai');
        if (! is_array($ai) || ! array_key_exists('enabled', $ai)) {
            return;
        }
        config([
            'ai.enabled' => (bool) $ai['enabled'],
            'ai.local_only' => true,
            'ai.embedding.provider' => 'ollama',
            'ai.embedding.model' => $ai['model'] ?? config('ai.embedding.model'),
            'ai.embedding.dimensions' => null,
            'ai.providers.ollama.base_url' => $ai['base_url'] ?? Ollama::DEFAULT_URL,
        ]);
    }
}
