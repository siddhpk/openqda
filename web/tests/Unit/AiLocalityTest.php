<?php

namespace Tests\Unit;

use App\Services\AI\AiManager;
use Tests\TestCase;

class AiLocalityTest extends TestCase
{
    private function manager(array $ollama = [], array $extra = []): AiManager
    {
        return new AiManager(array_replace_recursive([
            'enabled' => true,
            'local_only' => true,
            'redaction' => 'cloud',
            'embedding' => ['provider' => 'ollama', 'model' => 'nomic-embed-text'],
            'providers' => ['ollama' => ['base_url' => 'http://localhost:11434', 'cloud' => false]],
        ], ['providers' => ['ollama' => $ollama]], $extra));
    }

    public function test_private_hosts_are_recognised(): void
    {
        foreach (['localhost', '127.0.0.1', '::1', '[::1]', '10.1.2.3', '172.16.0.9', '192.168.1.20', '169.254.1.1',
            '100.101.102.103', 'fd12:3456::1', 'ollama', 'host.docker.internal', 'gpu-box.lan', 'nas.local', 'x.localhost'] as $host) {
            $this->assertTrue(AiManager::isPrivateHost($host), $host);
        }
        foreach (['', 'api.example.com', '8.8.8.8', '172.32.0.1', '2001:4860:4860::8888', 'ollama.example.org'] as $host) {
            $this->assertFalse(AiManager::isPrivateHost($host), $host);
        }
        $this->assertTrue(AiManager::isPrivateHost('embed.uni.example', ['EMBED.uni.example']));
    }

    public function test_locality_comes_from_the_endpoint_and_the_flag_can_only_be_stricter(): void
    {
        $this->assertFalse($this->manager()->usesCloud());
        // a remote endpoint is a third party even if marked local
        $remote = $this->manager(['base_url' => 'https://ollama.example.org']);
        $this->assertTrue($remote->usesCloud());
        $this->assertTrue($remote->blockedByLocalOnly());
        $this->assertFalse($remote->describe()['embedding']['endpointPrivate']);
        // marking a local endpoint as cloud makes it stricter
        $this->assertTrue($this->manager(['cloud' => true])->usesCloud());
        // an administrator can declare institutional hosts as their own
        $this->assertFalse($this->manager(['base_url' => 'https://embed.uni.example'], ['local_hosts' => ['embed.uni.example']])->usesCloud());
    }

    public function test_consent_fingerprint_covers_endpoint_redaction_and_policy(): void
    {
        $base = $this->manager()->configFingerprint();
        $this->assertSame($base, $this->manager()->configFingerprint());
        $this->assertNotSame($base, $this->manager(['base_url' => 'http://localhost:11435'])->configFingerprint());
        $this->assertNotSame($base, $this->manager(['base_url' => 'http://localhost:11434/v2'])->configFingerprint());
        $this->assertNotSame($base, $this->manager([], ['redaction' => 'always'])->configFingerprint());
        $this->assertNotSame($base, $this->manager([], ['consent_policy_version' => 2])->configFingerprint());
        // credentials and query strings are not part of the endpoint
        $this->assertSame('http://localhost:11434', $this->manager(['base_url' => 'http://user:pw@LOCALHOST:11434/?x=1'])->endpoint('ollama'));
    }
}
