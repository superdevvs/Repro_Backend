<?php

namespace Tests\Feature;

use App\Models\DropboxStudioToken;
use App\Models\User;
use App\Services\Dropbox\DropboxOAuthFlow;
use App\Services\DropboxTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DropboxOAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.dropbox.client_id' => 'test-dropbox-client',
            'services.dropbox.client_secret' => 'test-dropbox-secret',
            'services.dropbox.redirect' => 'https://api.example.test/api/dropbox/callback',
            'services.dropbox.access_token' => null,
            'services.dropbox.refresh_token' => null,
            'services.dropbox.enabled' => true,
            'app.frontend_url' => 'https://dashboard.example.test',
        ]);
        Cache::flush();
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_public_controls_and_legacy_debug_helpers_are_closed(): void
    {
        $this->getJson('/api/dropbox/config')->assertUnauthorized();
        $this->postJson('/api/dropbox/connect')->assertUnauthorized();
        $this->postJson('/api/dropbox/disconnect')->assertUnauthorized();
        $this->getJson('/api/dropbox/connect')->assertStatus(405);
        foreach (['test/dropbox-config', 'test/dropbox-curl', 'test/dropbox-connection',
            'test/dropbox-folder', 'test/folder-structure', 'test/create-shoot', 'dropbox/setup-long-lived-token'] as $path) {
            $this->getJson('/api/'.$path)->assertNotFound();
        }
        $this->postJson('/api/test/create-shoot-api')->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_only_primary_non_impersonating_admins_can_start_or_disconnect(): void
    {
        foreach (['client', 'salesRep', 'photographer', 'editor', 'editing_manager'] as $role) {
            $user = User::factory()->create(['role' => $role, 'secondary_roles' => ['admin']]);
            $this->authenticate($user);
            $this->getJson('/api/dropbox/config')->assertForbidden();
            $this->postJson('/api/dropbox/connect')->assertForbidden();
            $this->postJson('/api/dropbox/disconnect', ['connection_version' => str_repeat('a', 64)])->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->authenticate($admin);
        $this->withHeader('X-Impersonate-User-Id', (string) $other->id)->postJson('/api/dropbox/connect')->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_start_uses_random_state_pkce_and_http_only_browser_cookie(): void
    {
        $flow = $this->begin();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $flow['params']['state']);
        $this->assertSame('S256', $flow['params']['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $flow['params']['code_challenge']);
        $this->assertSame('offline', $flow['params']['token_access_type']);
        $this->assertTrue($flow['response_cookie']->isHttpOnly());
        $this->assertSame('lax', $flow['response_cookie']->getSameSite());
        $this->assertNull($flow['response_cookie']->getDomain());
        $this->assertSame('/api/dropbox', $flow['response_cookie']->getPath());
        $this->assertStringNotContainsString('test-dropbox-secret', $flow['response']->getContent());
        $next = $this->begin();
        $this->assertNotSame($flow['state'], $next['state']);
        $this->assertNotSame($flow['cookie'], $next['cookie']);
        $this->assertNotSame($flow['params']['code_challenge'], $next['params']['code_challenge']);
        Http::assertNothingSent();
    }

    public function test_missing_wrong_and_expired_state_or_cookie_never_exchange_a_code(): void
    {
        $this->getJson('/api/dropbox/callback?code=unused&state=debug')->assertBadRequest();
        $flow = $this->begin();
        $wrong = $flow;
        $wrong['cookie'] = str_repeat('a', 43);
        $this->completeOAuth($wrong)->assertBadRequest();
        $this->completeOAuth(array_merge($flow, ['cookie' => '']))->assertBadRequest();
        $this->travel(601)->seconds();
        $this->completeOAuth($flow)->assertBadRequest();
        Http::assertNothingSent();
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_production_requires_secure_callback_configuration_and_sets_secure_cookie(): void
    {
        $this->app['env'] = 'production';
        $flow = $this->begin();
        $this->assertTrue($flow['response_cookie']->isSecure());
        config(['services.dropbox.redirect' => 'http://api.example.test/api/dropbox/callback']);
        $this->postJson('/api/dropbox/connect')->assertStatus(503);
        config(['services.dropbox.redirect' => 'https://api.example.test/api/dropbox/callback', 'services.dropbox.client_secret' => '']);
        $this->postJson('/api/dropbox/connect')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_connection_attempts_are_throttled_per_administrator(): void
    {
        $this->authenticate(User::factory()->create(['role' => 'admin']));
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/dropbox/connect')->assertOk();
        }
        $this->postJson('/api/dropbox/connect')->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_success_is_single_use_and_never_returns_tokens_even_in_local_debug_mode(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true]);
        $flow = $this->begin();
        $this->fakeExchange();
        $response = $this->completeOAuth($flow, ['debug' => 'true'])->assertOk()->assertJsonPath('connected', true);
        $this->assertStringNotContainsString('issued-access-secret', $response->getContent());
        $this->assertStringNotContainsString('issued-refresh-secret', $response->getContent());
        $this->assertStringNotContainsString('test-dropbox-secret', $response->getContent());
        $record = app(DropboxTokenService::class)->record();
        $this->assertSame('issued-access-secret', $record->access_token);
        $this->assertStringStartsWith('encrypted:v1:', $record->getRawOriginal('access_token'));
        $this->assertArrayNotHasKey('access_token', $record->toArray());
        Http::assertSent(function ($request) use ($flow) {
            if ($request->url() !== 'https://api.dropboxapi.com/oauth2/token') {
                return false;
            }
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=');
            return hash_equals($flow['params']['code_challenge'], $challenge)
                && $request['client_id'] === 'test-dropbox-client'
                && ! $request->hasHeader('Authorization')
                && $request['redirect_uri'] === 'https://api.example.test/api/dropbox/callback';
        });
        $this->completeOAuth($flow)->assertBadRequest();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('oauth_tokens', 1);
    }

    public function test_browser_callback_only_redirects_to_configured_frontend_without_credentials(): void
    {
        $flow = $this->begin();
        $this->fakeExchange();
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withUnencryptedCookies([DropboxOAuthFlow::COOKIE_NAME => $flow['cookie']]);
        $response = $this->get('/api/dropbox/callback?'.http_build_query([
            'state' => $flow['state'], 'code' => 'issued-code', 'return_to' => 'https://evil.example',
        ]))->assertRedirect('https://dashboard.example.test/integrations?dropbox=connected');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('issued-access-secret', $response->getContent());
    }

    public function test_logout_demotion_lock_and_password_change_invalidate_pending_flows(): void
    {
        foreach (['logout', 'demote', 'lock', 'password'] as $change) {
            $flow = $this->begin();
            $user = $flow['admin'];
            match ($change) {
                'logout' => $user->tokens()->delete(),
                'demote' => $user->update(['role' => 'editor']),
                'lock' => $user->update(['locked_at' => now()]),
                'password' => $user->update(['password' => 'new-security-test-password']),
            };
            $this->completeOAuth($flow)->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_disconnect_invalidates_a_pending_callback_before_exchange(): void
    {
        $flow = $this->begin();
        $tokens = app(DropboxTokenService::class);
        $tokens->disconnect($tokens->version(), $flow['admin']);
        $this->completeOAuth($flow)->assertStatus(409);
        $this->assertFalse($tokens->configured());
        Http::assertNothingSent();
    }

    public function test_logout_during_provider_exchange_cannot_complete_the_connection(): void
    {
        $flow = $this->begin();
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => function () use ($flow) {
                $flow['admin']->tokens()->delete();
                return Http::response(['access_token' => 'issued-access-secret', 'refresh_token' => 'issued-refresh-secret', 'expires_in' => 14400]);
            },
            'https://api.dropboxapi.com/2/users/get_current_account' => Http::response(['account_id' => 'dbid:studio']),
        ]);
        $this->completeOAuth($flow)->assertForbidden();
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_reconnect_cannot_replace_an_existing_account(): void
    {
        $this->studio();
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response([
                'access_token' => 'issued-access-secret', 'refresh_token' => 'issued-refresh-secret', 'expires_in' => 14400,
            ]),
            'https://api.dropboxapi.com/2/users/get_current_account' => function ($request) {
                $old = $request->hasHeader('Authorization', 'Bearer existing-access');
                return Http::response(['account_id' => $old ? 'dbid:existing' : 'dbid:different', 'name' => ['display_name' => 'Test Studio']]);
            },
        ]);
        $flow = $this->begin();
        $this->completeOAuth($flow)->assertStatus(409);
        $record = app(DropboxTokenService::class)->record();
        $this->assertSame('dbid:existing', $record->provider_account_id);
        $this->assertSame('existing-access', $record->access_token);
        $this->assertDatabaseCount('oauth_tokens', 1);
    }

    public function test_provider_errors_are_safe_and_the_failed_flow_cannot_be_replayed(): void
    {
        $flow = $this->begin();
        Http::fake(['https://api.dropboxapi.com/oauth2/token' => Http::response([
            'error_description' => 'private-provider-secret', 'access_token' => 'accidentally-returned-secret',
        ], 500)]);
        $response = $this->completeOAuth($flow)->assertStatus(502);
        $this->assertStringNotContainsString('private-provider-secret', $response->getContent());
        $this->assertStringNotContainsString('accidentally-returned-secret', $response->getContent());
        $this->completeOAuth($flow)->assertBadRequest();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_disconnect_checks_version_revokes_and_never_falls_back_to_environment(): void
    {
        $this->studio();
        config(['services.dropbox.access_token' => 'old-env-access', 'services.dropbox.refresh_token' => 'old-env-refresh']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticate($admin);
        $tokens = app(DropboxTokenService::class);
        $this->postJson('/api/dropbox/disconnect', ['connection_version' => str_repeat('a', 64)])->assertStatus(409);
        Http::assertNothingSent();
        Http::fake(['https://api.dropboxapi.com/2/auth/token/revoke' => Http::response(null, 200)]);
        $this->postJson('/api/dropbox/disconnect', ['connection_version' => $tokens->version()])
            ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('revocation_pending', false);
        Http::assertSentCount(1);
        $this->assertFalse($tokens->configured());
        $this->assertTrue($tokens->record()->metadata['disconnected']);
        $this->assertNull($tokens->record()->access_token);
        $this->assertNull($tokens->record()->refresh_token);
        $this->expectException(\RuntimeException::class);
        $tokens->getValidAccessToken();
    }

    public function test_provider_revocation_failure_still_disconnects_locally(): void
    {
        $this->studio();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticate($admin);
        $tokens = app(DropboxTokenService::class);
        Http::fake(['https://api.dropboxapi.com/2/auth/token/revoke' => Http::response(['error' => 'unavailable'], 503)]);
        $this->postJson('/api/dropbox/disconnect', ['connection_version' => $tokens->version()])
            ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('revocation_pending', true);
        $this->assertFalse($tokens->configured());
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('access_token'));
    }

    public function test_public_webhook_route_delegates_signature_verification(): void
    {
        $this->get('/api/dropbox/webhook?challenge=verify-me')->assertOk()->assertSeeText('verify-me');
        $body = '{"list_folder":{"accounts":["dbid:example"]}}';
        $this->call('POST', '/api/dropbox/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->call('POST', '/api/dropbox/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DROPBOX_SIGNATURE' => hash_hmac('sha256', $body, 'test-dropbox-secret'),
        ], $body)->assertOk();
        Http::assertNothingSent();
    }

    private function authenticate(User $user): void
    {
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withToken($user->createToken('dropbox-security-test')->plainTextToken);
    }

    private function begin(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->authenticate($admin);
        $response = $this->postJson('/api/dropbox/connect')->assertOk();
        parse_str(parse_url($response->json('authorization_url'), PHP_URL_QUERY), $params);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === DropboxOAuthFlow::COOKIE_NAME);
        $this->assertNotNull($cookie);
        return ['admin' => $admin, 'state' => $params['state'], 'params' => $params, 'cookie' => $cookie->getValue(),
            'response_cookie' => $cookie, 'response' => $response];
    }

    private function completeOAuth(array $flow, array $params = [])
    {
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withCredentials();
        $this->withUnencryptedCookies([DropboxOAuthFlow::COOKIE_NAME => $flow['cookie']]);
        return $this->getJson('/api/dropbox/callback?'.http_build_query(array_merge([
            'state' => $flow['state'], 'code' => 'issued-code',
        ], $params)));
    }

    private function fakeExchange(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response([
                'access_token' => 'issued-access-secret', 'refresh_token' => 'issued-refresh-secret', 'expires_in' => 14400,
            ]),
            'https://api.dropboxapi.com/2/users/get_current_account' => Http::response([
                'account_id' => 'dbid:studio', 'email' => 'studio@example.test', 'name' => ['display_name' => 'Test Studio'],
            ]),
        ]);
    }

    private function studio(): DropboxStudioToken
    {
        return DropboxStudioToken::create([
            'provider' => 'dropbox', 'user_id' => null, 'account_type' => 'shared',
            'access_token' => 'existing-access', 'refresh_token' => 'existing-refresh',
            'expires_at' => now()->addHour(), 'provider_account_id' => 'dbid:existing',
        ]);
    }
}
