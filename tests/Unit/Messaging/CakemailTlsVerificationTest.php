<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CakemailTlsVerificationTest extends TestCase
{
    public function test_password_refresh_and_bearer_requests_require_tls_verification(): void
    {
        config([
            'cache.default' => 'array',
            'services.cakemail.username' => 'tls-fixture@example.test',
            'services.cakemail.password' => 'synthetic-password-for-testing',
            'services.cakemail.base_url' => 'https://cakemail.example.test',
        ]);
        Http::preventStrayRequests();
        // The provider must override an accidentally insecure global default.
        Http::globalOptions(['verify' => false]);
        $observed = [];
        Http::fake(function (Request $request, array $options) use (&$observed) {
            $observed[] = [$request->url(), $request['grant_type'] ?? null, $options['verify'] ?? null];
            if ($request->url() === 'https://cakemail.example.test/token') {
                return Http::response([
                    'access_token' => $request['grant_type'] === 'password' ? 'synthetic-access' : 'synthetic-refreshed-access',
                    'refresh_token' => 'synthetic-refresh',
                    'expires_in' => 3600,
                ]);
            }
            return Http::response(['data' => []]);
        });

        try {
            $provider = new CakemailProvider();
            $provider->clearCache();
            $this->assertSame('synthetic-access', $provider->getAccessToken());
            $this->assertSame('synthetic-refreshed-access', $provider->refreshAccessToken());
            $this->assertSame([], $provider->getSenders());
            $this->assertSame([
                ['https://cakemail.example.test/token', 'password', true],
                ['https://cakemail.example.test/token', 'refresh_token', true],
                ['https://cakemail.example.test/brands/default/senders', null, true],
            ], $observed);
        } finally {
            Http::globalOptions([]);
        }
    }
}
