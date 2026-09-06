<?php

namespace Tests\Feature;

use App\Models\DropboxStudioToken;
use App\Models\OauthToken;
use App\Models\User;
use App\Services\DropboxTokenService;
use App\Services\DropboxWorkflowService;
use App\Services\UploadSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DropboxStudioCredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.dropbox.access_token' => null, 'services.dropbox.refresh_token' => null,
            'services.dropbox.client_id' => 'test-app', 'services.dropbox.client_secret' => 'test-secret',
            'services.dropbox.enabled' => true,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_plaintext_legacy_studio_tokens_remain_readable_but_personal_tokens_are_never_selected(): void
    {
        $legacy = $this->legacy();
        $this->personal(User::factory()->create());
        $tokens = app(DropboxTokenService::class);
        $this->assertSame($legacy->id, $tokens->record()->id);
        $this->assertSame('legacy-access', $tokens->getValidAccessToken());
        $this->assertArrayNotHasKey('access_token', $tokens->record()->toArray());
        $this->assertArrayNotHasKey('refresh_token', $tokens->record()->toArray());
        Http::assertNothingSent();
    }

    public function test_bind_encrypts_tokens_and_keeps_admin_attribution_and_account_pin(): void
    {
        $tokens = app(DropboxTokenService::class);
        $admin = $this->admin();
        $before = $tokens->version();
        $record = $tokens->bind($this->tokenData(), $this->account(), $before, $admin);
        $this->assertSame('new-access', $record->access_token);
        $this->assertSame('new-refresh', $record->refresh_token);
        $this->assertStringStartsWith('encrypted:v1:', $record->getRawOriginal('access_token'));
        $this->assertStringStartsWith('encrypted:v1:', $record->getRawOriginal('refresh_token'));
        $this->assertStringNotContainsString('new-access', $record->toJson());
        $this->assertSame($admin->id, $record->metadata['connected_by']);
        $this->assertNotSame($before, $tokens->version());
        $this->assertSame(64, strlen($tokens->version()));
        $this->assertHttpFailure(409, fn () => $tokens->bind($this->tokenData(), $this->account('dbid:other'), $tokens->version(), $admin));
        $this->assertSame('dbid:studio', $tokens->record()->provider_account_id);
        Http::assertNothingSent();
    }

    public function test_stale_bind_and_disconnect_and_non_primary_admins_cannot_mutate_connection(): void
    {
        $tokens = app(DropboxTokenService::class);
        $this->legacy();
        $before = $tokens->record()->getAttributes();
        $this->assertHttpFailure(409, fn () => $tokens->bind($this->tokenData(), $this->account(), 'stale', $this->admin()));
        $this->assertHttpFailure(409, fn () => $tokens->disconnect('stale', $this->admin()));
        foreach (['editor', 'editing_manager', 'photographer', 'client'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'secondary_roles' => ['admin']]);
            $this->assertHttpFailure(403, fn () => $tokens->bind($this->tokenData(), $this->account(), $tokens->version(), $actor));
            $this->assertHttpFailure(403, fn () => $tokens->disconnect($tokens->version(), $actor));
        }
        $locked = $this->admin();
        $locked->update(['locked_at' => now()]);
        $this->assertHttpFailure(403, fn () => $tokens->disconnect($tokens->version(), $locked));
        request()->attributes->set('is_impersonating', true);
        $this->assertHttpFailure(403, fn () => $tokens->disconnect($tokens->version(), $this->admin()));
        $this->assertSame($before, $tokens->record()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_expired_legacy_token_refresh_is_encrypted_and_preserves_connection_generation(): void
    {
        $legacy = $this->legacy(['expires_at' => now()->subHour()]);
        $tokens = app(DropboxTokenService::class);
        $before = $tokens->version();
        Http::fake([
            'api.dropboxapi.com/2/users/get_current_account' => Http::response([], 401),
            'api.dropboxapi.com/oauth2/token' => Http::response($this->tokenData()),
        ]);
        $this->assertSame('new-access', $tokens->getValidAccessToken());
        $this->assertSame('new-access', $tokens->getValidAccessToken());
        $this->assertSame($before, $tokens->version());
        $this->assertSame($legacy->id, $tokens->record()->id);
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('access_token'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.dropboxapi.com/oauth2/token' && $request['refresh_token'] === 'legacy-refresh');
    }

    public function test_environment_identity_migration_preserves_the_oauth_start_version(): void
    {
        config()->set(['services.dropbox.access_token' => 'env-access', 'services.dropbox.refresh_token' => 'env-refresh']);
        Http::fake(['api.dropboxapi.com/2/users/get_current_account' => Http::response($this->account())]);
        $tokens = app(DropboxTokenService::class);
        $before = $tokens->version();
        $this->assertSame('dbid:studio', $tokens->currentAccountId());
        $this->assertSame($before, $tokens->version());
        $this->assertSame('env-refresh', $tokens->record()->refresh_token);
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('access_token'));
        $tokens->bind($this->tokenData(), $this->account(), $before, $this->admin());
        $this->assertSame('new-access', $tokens->getValidAccessToken());
    }

    public function test_identity_lookup_fails_closed_when_provider_identity_is_missing(): void
    {
        $this->legacy();
        Http::fake(['api.dropboxapi.com/2/users/get_current_account' => Http::response(['email' => 'test@example.test'])]);
        $this->expectException(\RuntimeException::class);
        app(DropboxTokenService::class)->currentAccountId();
    }

    public function test_refresh_response_cannot_overwrite_a_newer_disconnect_if_a_lock_lease_was_lost(): void
    {
        $legacy = $this->legacy(['expires_at' => now()->subHour()]);
        $version = str_repeat('d', 64);
        Http::fake([
            'api.dropboxapi.com/2/users/get_current_account' => Http::response([], 401),
            'api.dropboxapi.com/oauth2/token' => function () use ($legacy, $version) {
                DB::table('oauth_tokens')->where('id', $legacy->id)->update([
                    'metadata' => json_encode(['connection_version' => $version, 'disconnected' => true]),
                ]);
                return Http::response($this->tokenData());
            },
        ]);
        $tokens = app(DropboxTokenService::class);
        $this->assertHttpFailure(409, fn () => $tokens->getValidAccessToken());
        $this->assertFalse($tokens->configured());
        $this->assertSame($version, $tokens->version());
        $this->assertSame('legacy-access', $tokens->record()->access_token);
    }

    public function test_disconnect_persists_before_revoke_and_never_resurrects_environment_credentials(): void
    {
        $this->legacy();
        config()->set(['services.dropbox.access_token' => 'env-fallback', 'services.dropbox.refresh_token' => 'env-refresh']);
        $tokens = app(DropboxTokenService::class);
        $before = $tokens->version();
        Http::fake(['api.dropboxapi.com/2/auth/token/revoke' => function () use ($tokens, $before) {
            $this->assertFalse($tokens->configured());
            $this->assertTrue($tokens->record()->metadata['disconnected']);
            $this->assertNotSame($before, $tokens->version());
            return Http::response([], 200);
        }]);
        $this->assertSame(['connected' => false, 'revocation_pending' => false], $tokens->disconnect($before, $this->admin()));
        $this->assertNotNull($tokens->record());
        $this->assertNull($tokens->record()->access_token);
        $this->assertNull($tokens->record()->refresh_token);
        $this->assertFalse(app(DropboxWorkflowService::class)->isEnabled());
        $this->assertFalse(app(DropboxWorkflowService::class)->testConnection()['success']);
        $this->assertNull($tokens->currentAccountId());
        try { $tokens->getValidAccessToken(); $this->fail('Disconnected credentials were used.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('disconnected', $e->getMessage()); }
        Http::assertSentCount(1);
        $tokens->bind($this->tokenData(), $this->account('dbid:new-studio'), $tokens->version(), $this->admin());
        $this->assertTrue($tokens->configured());
    }

    public function test_provider_failure_retains_only_encrypted_retry_credentials_and_blocks_rebind_until_revoke_completes(): void
    {
        $this->legacy();
        Http::fake(['api.dropboxapi.com/2/auth/token/revoke' => Http::sequence()->push([], 503)->push([], 200)]);
        $tokens = app(DropboxTokenService::class);
        $admin = $this->admin();
        $result = $tokens->disconnect($tokens->version(), $admin);
        $this->assertSame(['connected' => false, 'revocation_pending' => true], $result);
        $this->assertFalse($tokens->configured());
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('access_token'));
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('refresh_token'));
        $this->assertHttpFailure(409, fn () => $tokens->bind($this->tokenData(), $this->account(), $tokens->version(), $admin));
        $this->assertSame(['connected' => false, 'revocation_pending' => false], $tokens->disconnect($tokens->version(), $admin));
        $this->assertNull($tokens->record()->refresh_token);
    }

    public function test_personal_tokens_and_empty_studio_records_never_activate_studio_workflow(): void
    {
        $this->personal($this->admin());
        $tokens = app(DropboxTokenService::class);
        $this->assertFalse($tokens->configured());
        $this->assertFalse(app(DropboxWorkflowService::class)->isEnabled());
        $this->legacy(['access_token' => null, 'refresh_token' => null]);
        config()->set('services.dropbox.access_token', 'old-env-token');
        $this->assertFalse($tokens->configured());
        Http::assertNothingSent();
    }

    public function test_upload_sources_cannot_rebind_studio_even_with_an_old_encrypted_shared_state(): void
    {
        $this->legacy();
        $service = app(UploadSourceService::class);
        $admin = $this->admin();
        $this->assertHttpFailure(403, fn () => $service->buildAuthorizationUrl('dropbox', $admin, 'shared'));
        $state = Crypt::encryptString(json_encode(['provider' => 'dropbox', 'user_id' => $admin->id, 'account_type' => 'shared', 'nonce' => 'old-state']));
        $this->assertHttpFailure(403, fn () => $service->completeAuthorization('dropbox', 'unused-code', $state));
        $this->assertSame('legacy-access', app(DropboxTokenService::class)->record()->access_token);
        Sanctum::actingAs($admin);
        $this->postJson('/api/upload-sources/dropbox/connect', ['account_type' => 'shared'])->assertForbidden();
        $this->get('/api/upload-sources/dropbox/callback?' . http_build_query(['code' => 'unused-code', 'state' => $state]))->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_shared_upload_source_use_is_admin_only_and_respects_disconnect_but_personal_stays_available(): void
    {
        $this->legacy();
        $service = app(UploadSourceService::class);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $this->assertFalse($service->providerStatus('dropbox', $photographer)['connected']);
        $this->assertHttpFailure(403, fn () => $service->listItems('dropbox', $photographer));
        $admin = $this->admin();
        Http::fake([
            'api.dropboxapi.com/2/files/list_folder' => function ($request, $options) {
                $this->assertTrue($options['verify']);
                return Http::response(['entries' => [], 'has_more' => false]);
            },
            'api.dropboxapi.com/2/auth/token/revoke' => Http::response([], 200),
        ]);
        $service->listItems('dropbox', $admin);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer legacy-access'));
        $tokens = app(DropboxTokenService::class);
        $tokens->disconnect($tokens->version(), $admin);
        config()->set('services.dropbox.access_token', 'env-fallback');
        $this->assertFalse($service->providerStatus('dropbox', $admin)['connected']);
        try { $service->listItems('dropbox', $admin); $this->fail('Studio tombstone was bypassed.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('disconnected', $e->getMessage()); }
        $this->personal($photographer);
        $service->listItems('dropbox', $photographer);
        $this->assertSame('personal', $service->providerStatus('dropbox', $photographer)['account_type']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-access'));
    }

    public function test_personal_dropbox_oauth_uses_its_own_callback_and_never_changes_studio_connection(): void
    {
        $studio = $this->legacy();
        $actor = User::factory()->create(['role' => 'photographer']);
        $service = app(UploadSourceService::class);
        parse_str(parse_url($service->buildAuthorizationUrl('dropbox', $actor), PHP_URL_QUERY), $query);
        $this->assertSame(rtrim(config('app.url'), '/') . '/api/upload-sources/dropbox/callback', $query['redirect_uri']);
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => function ($request, $options) {
                $this->assertTrue($options['verify']);
                return Http::response($this->tokenData());
            },
            'api.dropboxapi.com/2/users/get_current_account' => function ($request, $options) {
                $this->assertTrue($options['verify']);
                return Http::response($this->account('dbid:personal'));
            },
        ]);
        $personal = $service->completeAuthorization('dropbox', 'test-code', $query['state']);
        $this->assertSame($actor->id, $personal->user_id);
        $this->assertSame('personal', $personal->account_type);
        $this->assertSame($studio->id, app(DropboxTokenService::class)->record()->id);
        $this->assertSame('legacy-access', app(DropboxTokenService::class)->record()->access_token);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.dropboxapi.com/oauth2/token' && $request['redirect_uri'] === $query['redirect_uri']);
    }

    public function test_admin_status_returns_only_safe_fields_and_non_admins_are_denied(): void
    {
        $this->legacy();
        $tokens = app(DropboxTokenService::class);
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/integrations/dropbox/status')->assertOk()->assertExactJson([
            'success' => true, 'data' => [
                'enabled' => true, 'configured' => true, 'connected' => true, 'storage_mode' => 'dropbox',
                'account_label' => 'Test Studio', 'revocation_pending' => false, 'connection_version' => $tokens->version(),
            ],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager', 'secondary_roles' => ['admin']]));
        $this->getJson('/api/integrations/dropbox/status')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_encryption_command_is_dry_run_by_default_and_apply_preserves_generation_and_other_tokens(): void
    {
        $legacy = $this->legacy(['metadata' => ['connected_by' => 123, 'connected_at' => '2026-01-01T00:00:00Z']]);
        $personal = $this->personal($this->admin());
        $other = OauthToken::create(['provider' => 'google_drive', 'account_type' => 'shared', 'access_token' => 'google-access', 'refresh_token' => 'google-refresh']);
        $personalBefore = $personal->fresh()->getAttributes();
        $otherBefore = $other->fresh()->getAttributes();
        $tokens = app(DropboxTokenService::class);
        $version = $tokens->version();
        $this->artisan('dropbox:encrypt-studio-credentials')
            ->expectsOutput('Dry run: no credentials changed.')
            ->expectsOutput('Studio records scanned: 1')->expectsOutput('Records needing encryption: 1')
            ->expectsOutput('Records updated: 0')->assertSuccessful();
        $this->assertSame('legacy-access', $legacy->fresh()->getRawOriginal('access_token'));
        $this->artisan('dropbox:encrypt-studio-credentials', ['--apply' => true])
            ->expectsOutput('Studio Dropbox credential encryption complete.')
            ->expectsOutput('Studio records scanned: 1')->expectsOutput('Records needing encryption: 1')
            ->expectsOutput('Records updated: 1')->assertSuccessful();
        $this->assertSame($version, $tokens->version());
        $this->assertSame('legacy-access', $tokens->getValidAccessToken());
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('access_token'));
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('refresh_token'));
        $this->assertSame(123, $tokens->record()->metadata['connected_by']);
        $this->assertSame('2026-01-01T00:00:00Z', $tokens->record()->metadata['connected_at']);
        $this->assertSame($personalBefore, $personal->fresh()->getAttributes());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame(['records_scanned' => 1, 'records_needing_encryption' => 0, 'records_updated' => 0], $tokens->encryptLegacyCredentials(true));
        Http::assertNothingSent();
    }

    public function test_final_authorization_rejection_inside_connection_lock_does_not_bind_tokens(): void
    {
        $tokens = app(DropboxTokenService::class);
        $version = $tokens->version();
        $this->assertHttpFailure(403, fn () => $tokens->bind($this->tokenData(), $this->account(), $version, $this->admin(), function () {
            // Simulates the original login token being revoked during provider requests or while waiting for the lock.
            abort(403, 'Login no longer valid.');
        }));
        $this->assertNull($tokens->record());
        $this->assertSame($version, $tokens->version());
        Http::assertNothingSent();
    }

    public function test_refresh_only_environment_credentials_keep_version_when_first_persisted(): void
    {
        config()->set('services.dropbox.refresh_token', 'env-refresh-only');
        Http::fake(['api.dropboxapi.com/oauth2/token' => Http::response($this->tokenData())]);
        $tokens = app(DropboxTokenService::class);
        $version = $tokens->version();
        $this->assertSame('new-access', $tokens->getValidAccessToken());
        $this->assertSame($version, $tokens->version());
        $this->assertStringStartsWith('encrypted:v1:', $tokens->record()->getRawOriginal('refresh_token'));
        Http::assertSentCount(1);
    }

    public function test_operator_revocation_acknowledgement_defaults_to_dry_run_and_requires_explicit_confirmation(): void
    {
        $legacy = $this->legacy(['metadata' => ['disconnected' => true, 'revocation_pending' => true]]);
        $before = $legacy->fresh()->getAttributes();
        $tokens = app(DropboxTokenService::class);
        $version = $tokens->version();
        $this->artisan('dropbox:acknowledge-revocation', ['connection_version' => $version])
            ->expectsOutput('Dry run: pending revocation can be acknowledged; no credentials changed.')
            ->expectsOutput('Connection version: '.$version)->assertSuccessful();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->artisan('dropbox:acknowledge-revocation', ['connection_version' => $version, '--apply' => true])
            ->expectsOutput('Verify app revocation in Dropbox, then supply --confirm-provider-revoked with --apply.')
            ->assertFailed();
        $this->assertHttpFailure(422, fn () => $tokens->acknowledgeProviderRevocation($version, true, false));
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_operator_revocation_acknowledgement_rejects_stale_version_and_active_connections(): void
    {
        $legacy = $this->legacy(['metadata' => ['disconnected' => true, 'revocation_pending' => true]]);
        $before = $legacy->fresh()->getAttributes();
        $this->artisan('dropbox:acknowledge-revocation', [
            'connection_version' => 'stale-version', '--apply' => true, '--confirm-provider-revoked' => true,
        ])->expectsOutput('Acknowledgement failed. Verify the current connection version and that Dropbox is disconnected with revocation pending.')->assertFailed();
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $legacy->update(['metadata' => ['disconnected' => false, 'revocation_pending' => false]]);
        $tokens = app(DropboxTokenService::class);
        $this->assertHttpFailure(409, fn () => $tokens->acknowledgeProviderRevocation($tokens->version(), true, true));
        $this->assertSame('legacy-access', $tokens->record()->access_token);
        Http::assertNothingSent();
    }

    public function test_confirmed_operator_revocation_clears_retry_tokens_allows_new_binding_and_keeps_environment_disabled(): void
    {
        $this->legacy(['metadata' => ['disconnected' => true, 'revocation_pending' => true, 'disconnected_by' => 123]]);
        config()->set(['services.dropbox.access_token' => 'old-env-access', 'services.dropbox.refresh_token' => 'old-env-refresh']);
        $tokens = app(DropboxTokenService::class);
        $before = $tokens->version();
        $this->artisan('dropbox:acknowledge-revocation', [
            'connection_version' => $before, '--apply' => true, '--confirm-provider-revoked' => true,
        ])->expectsOutput('Provider revocation acknowledged. Studio Dropbox remains disconnected.')->assertSuccessful();
        $record = $tokens->record();
        $this->assertTrue($record->metadata['disconnected']);
        $this->assertFalse($record->metadata['revocation_pending']);
        $this->assertSame(123, $record->metadata['disconnected_by']);
        $this->assertSame('operator_cli', $record->metadata['revocation_acknowledged_via']);
        $this->assertNotEmpty($record->metadata['revocation_acknowledged_at']);
        $this->assertNull($record->access_token);
        $this->assertNull($record->refresh_token);
        $this->assertNull($record->expires_at);
        $this->assertFalse($tokens->configured());
        $this->assertNotSame($before, $tokens->version());
        $this->assertSame(64, strlen($tokens->version()));
        $this->assertHttpFailure(409, fn () => $tokens->bind($this->tokenData(), $this->account('dbid:new-account'), $before, $this->admin()));
        $tokens->bind($this->tokenData(), $this->account('dbid:new-account'), $tokens->version(), $this->admin());
        $this->assertSame('dbid:new-account', $tokens->record()->provider_account_id);
        $this->assertSame('operator_cli', $tokens->record()->metadata['revocation_acknowledged_via']);
        $this->assertTrue($tokens->configured());
        Http::assertNothingSent();
    }

    private function legacy(array $attributes = []): OauthToken
    {
        return OauthToken::create(array_merge([
            'provider' => 'dropbox', 'user_id' => null, 'account_type' => 'shared',
            'access_token' => 'legacy-access', 'refresh_token' => 'legacy-refresh', 'expires_at' => now()->addHour(),
            'provider_account_id' => 'dbid:studio', 'provider_account_name' => 'Test Studio',
        ], $attributes));
    }

    private function personal(User $user): OauthToken
    {
        return OauthToken::create(['provider' => 'dropbox', 'user_id' => $user->id, 'account_type' => 'personal',
            'access_token' => 'personal-access', 'refresh_token' => 'personal-refresh', 'expires_at' => now()->addHour()]);
    }

    private function admin(): User { return User::factory()->create(['role' => 'admin']); }
    private function tokenData(): array { return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 14400]; }
    private function account(string $id = 'dbid:studio'): array { return ['account_id' => $id, 'email' => 'studio@example.test', 'name' => ['display_name' => 'Test Studio']]; }

    private function assertHttpFailure(int $status, callable $callback): void
    {
        try { $callback(); $this->fail('Expected authorization/conflict failure.'); }
        catch (HttpExceptionInterface $e) { $this->assertSame($status, $e->getStatusCode()); }
    }
}
