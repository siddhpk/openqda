<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__proxy-check', fn () => ['secure' => request()->secure(), 'url' => url('/projects')]);
    }

    private function viaTunnel()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'openqda.example.org', 'X-Forwarded-Port' => '443'])
            ->getJson('/__proxy-check');
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->viaTunnel()->assertOk()->assertJsonPath('secure', false);
    }

    public function test_forwarded_headers_are_used_when_the_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->viaTunnel()->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('url', 'https://openqda.example.org/projects');
    }

    public function test_a_list_of_proxy_addresses_is_supported(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1, 172.18.0.0/16']);

        $this->viaTunnel()->assertOk()->assertJsonPath('secure', true);
    }
}
