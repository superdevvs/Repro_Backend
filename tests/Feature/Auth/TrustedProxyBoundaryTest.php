<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/__trusted-proxy-check', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
        ]));
    }

    public function test_unknown_direct_and_private_peers_cannot_spoof_client_ip(): void
    {
        foreach (['198.51.100.25', '127.0.0.1', '10.0.0.9', '2001:db8::dead'] as $peer) {
            $this->withServerVariables(['REMOTE_ADDR' => $peer])
                ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
                ->getJson('/api/__trusted-proxy-check')->assertOk()
                ->assertJsonPath('ip', $peer)->assertJsonPath('scheme', 'http');
        }
    }

    public function test_cloudflare_ipv4_chain_uses_the_last_untrusted_client_hop(): void
    {
        // A caller-controlled prefix must not override the actual client address
        // appended by the edge; known Cloudflare hops are removed from the end.
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.5'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.77, 203.0.113.9, 104.16.0.5', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/api/__trusted-proxy-check')->assertOk()
            ->assertJsonPath('ip', '203.0.113.9')->assertJsonPath('scheme', 'https');
    }

    public function test_cloudflare_ipv6_peer_preserves_the_actual_ipv6_client(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '2606:4700::1111'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.77, 2001:db8::1234', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/api/__trusted-proxy-check')->assertOk()
            ->assertJsonPath('ip', '2001:db8::1234')->assertJsonPath('scheme', 'https');
    }

    public function test_missing_config_fails_closed_without_hostname_based_proxy_trust(): void
    {
        config(['trusted_proxies.addresses' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->withHeaders(['Host' => 'untrusted.on-vapor.com', 'X-Forwarded-For' => '203.0.113.9'])
            ->getJson('/api/__trusted-proxy-check')->assertOk()->assertJsonPath('ip', '198.51.100.25');
    }
}
