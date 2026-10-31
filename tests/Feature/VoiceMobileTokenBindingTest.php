<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceMobileOffer;
use App\Models\VoiceMobileSession;
use App\Models\VoiceMobileTicket;
use App\Services\Voice\VoiceMobileCallService;
use App\Services\Voice\VoiceMobileProof;
use App\Services\Voice\VoiceMobileSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoiceMobileTokenBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private PersonalAccessToken $token;
    private array $connection = [];
    private array $credential = [];
    private bool $failCleanup = false;
    private mixed $beforeMaterial = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_voice.enabled' => true, 'mobile_voice.push_verified' => true,
            'mobile_voice.bundle_id' => 'com.example.repro', 'mobile_voice.push_credentials.debug' => 'test-debug-push',
            'services.telnyx.api_key' => 'fake', 'services.telnyx.from_number' => '+12025550100',
            'services.telnyx.voice.browser_enabled' => true, 'services.telnyx.voice.connection_id' => 'server',
            'services.telnyx.voice.credential_connection_id' => 'browser-unchanged', 'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
            'services.telnyx.voice.outbound_mode' => 'all', 'services.voice.canary_mode' => false, 'sanctum.expiration' => null]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->staff = User::factory()->create(['role' => 'admin']);
        $this->token = $this->signIn($this->staff);
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/credential_connections') {
                if ($request->method() === 'GET') { return Http::response(['data' => []]); }
                $this->connection = array_merge($request->data(), ['id' => 'isolated-mobile-connection']);
                return Http::response(['data' => $this->connection]);
            }
            if (str_starts_with($path, '/v2/credential_connections/')) {
                if ($request->method() === 'PATCH' && $this->failCleanup) { return Http::response([], 503); }
                return Http::response(['data' => $this->connection]);
            }
            if ($path === '/v2/telephony_credentials') {
                if ($request->method() === 'GET') { return Http::response(['data' => []]); }
                $this->credential = array_merge($request->data(), ['id' => 'isolated-credential', 'sip_username' => 'gencredtestbinding',
                    'user_id' => 'test-provider-user', 'resource_id' => 'connection:'.$this->connection['id'], 'expired' => false]);
                return Http::response(['data' => $this->credential]);
            }
            if ($path === '/v2/telephony_credentials/isolated-credential/token_material' && $request->method() === 'GET') {
                if ($this->beforeMaterial) { ($this->beforeMaterial)(); }
                return Http::response(['data' => ['kid' => 'test-v1', 'tel_token' => 'isolated-provider-token',
                    'signing_secret' => rtrim(strtr(base64_encode(str_repeat('t', 32)), '+/', '-_'), '=')]]);
            }
            if ($path === '/v2/telephony_credentials/isolated-credential' && in_array($request->method(), ['GET', 'PATCH'], true)) {
                if ($request->method() === 'PATCH') { $this->credential = array_merge($this->credential, $request->data()); }
                return Http::response(['data' => $this->credential]);
            }
            if ((str_starts_with($path, '/v2/telephony_credentials/') && $request->method() === 'DELETE') || str_contains($path, '/actions/hangup')) {
                return Http::response(['data' => []]);
            }
            throw new \RuntimeException('Unexpected isolated provider request.');
        });
    }

    private function signIn(User $user): PersonalAccessToken
    {
        $token = $user->createToken('isolated-mobile-binding', ['*'], now()->addDays(2))->accessToken;
        $user->withAccessToken($token);
        $this->actingAs($user, 'sanctum');
        return $token;
    }

    private function data(?string $device = null): array
    {
        return ['device_id' => $device ?? (string) Str::uuid(), 'platform' => 'ios', 'apns_environment' => 'debug', 'push_token' => str_repeat('a', 64)];
    }

    private function ready(?PersonalAccessToken $token = null): VoiceMobileSession
    {
        return VoiceMobileSession::create(['user_id' => $this->staff->id, 'personal_access_token_id' => ($token ?? $this->token)->id,
            'device_id' => (string) Str::uuid(), 'apns_environment' => 'debug', 'bundle_id' => 'com.example.repro', 'status' => 'ready',
            'expires_at' => now()->addHour(), 'registration_expires_at' => now()->addDays(30),
            'push_token' => str_repeat('a', 64), 'push_token_hash' => hash('sha256', (string) Str::uuid()),
            'connection_username' => 'test-binding', 'connection_id' => 'isolated-connection-'.Str::uuid(),
            'credential_id' => 'isolated-credential-'.Str::uuid(), 'sip_username' => 'gencredtestbinding']);
    }

    private function service(): VoiceMobileSessionService { return app(VoiceMobileSessionService::class); }

    public function test_bootstrap_binds_real_token_and_bounds_both_leases_to_its_expiry(): void
    {
        $this->token->update(['expires_at' => now()->addMinutes(20)]);
        $data = $this->data();
        $first = $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()->assertJsonMissingPath('personal_access_token_id')->json();
        $session = VoiceMobileSession::findOrFail($first['session_id']);
        $this->assertSame($this->token->id, $session->personal_access_token_id);
        $this->assertTrue($session->expires_at->lte($this->token->expires_at));
        $this->assertTrue($session->registration_expires_at->lte($this->token->expires_at));
        $this->assertTrue($this->service()->registered($session));
        $claims = json_decode(base64_decode(strtr(explode('.', $first['jwt'])[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual($this->token->expires_at->timestamp, $claims['exp']);
        $this->assertSame('test-provider-user', $claims['user_id']);
        $this->assertArrayNotHasKey('token_material', $first);
        $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()->assertJsonPath('session_id', $session->id);
        $this->assertSame('browser-unchanged', config('services.telnyx.voice.credential_connection_id'));
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/token'));
    }

    public function test_cookie_only_or_expired_login_cannot_provision(): void
    {
        $this->actingAs(User::findOrFail($this->staff->id), 'sanctum');
        $this->postJson('/api/voice/mobile/sessions', $this->data())->assertUnauthorized();
        $this->staff->withAccessToken($this->token); $this->actingAs($this->staff, 'sanctum');
        $this->token->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/voice/mobile/sessions', $this->data())->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertSame(0, VoiceMobileSession::count());
    }

    public function test_deleted_login_immediately_blocks_cached_session_and_background_routing(): void
    {
        $session = $this->ready(); $session->load(['personalAccessToken', 'user']);
        $this->assertTrue($this->service()->authorized($session));
        $this->token->delete();
        $this->assertNull($session->fresh()->personal_access_token_id);
        $this->assertFalse($this->service()->authorized($session));
        $this->assertFalse($this->service()->registered($session));
        $this->assertFalse($this->service()->reachable($this->staff));
        Http::assertNothingSent();
    }

    public function test_explicit_and_global_token_expiry_block_registration_and_renewal(): void
    {
        $session = $this->ready();
        $this->token->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($this->service()->registered($session));
        $this->token->forceFill(['expires_at' => now()->addDay(), 'created_at' => now()->subMinutes(61)])->save();
        $this->assertTrue($this->token->fresh()->created_at->lt(now()->subHour()));
        config(['sanctum.expiration' => 60]);
        $this->assertFalse($this->service()->authorized($session));
        $this->assertFalse($this->service()->registered($session));
        try { $this->service()->prepareIncoming($session); $this->fail('Expired login must not renew its provider credential.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        Http::assertNothingSent();
    }

    public function test_global_token_lifetime_caps_new_credential_and_registration_expiry(): void
    {
        $this->token->forceFill(['created_at' => now()->subMinutes(50)])->save();
        config(['sanctum.expiration' => 60]);
        $response = $this->postJson('/api/voice/mobile/sessions', $this->data())->assertOk()->json();
        $session = VoiceMobileSession::findOrFail($response['session_id']);
        $deadline = $this->token->created_at->copy()->addHour();
        $this->assertTrue($session->expires_at->lte($deadline));
        $this->assertTrue($session->registration_expires_at->lte($deadline));
        $this->assertTrue($this->service()->registered($session));
    }

    public function test_wrong_token_owner_and_missing_binding_fail_closed(): void
    {
        $session = $this->ready();
        $other = User::factory()->create(['role' => 'admin']);
        $foreign = $other->createToken('foreign-isolated')->accessToken;
        $session->update(['personal_access_token_id' => $foreign->id]);
        $this->assertFalse($this->service()->authorized($session));
        $this->assertFalse($this->service()->registered($session));
        $session->update(['personal_access_token_id' => null]);
        $this->assertFalse($this->service()->registered($session));
        $this->postJson('/api/voice/mobile/sessions', $this->data($session->device_id))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_second_login_cannot_rebind_or_operate_but_same_owner_can_clean_up(): void
    {
        $session = $this->ready();
        $second = $this->signIn($this->staff);
        $sdk = (string) Str::uuid();
        $base = '/api/voice/mobile/sessions/'.$session->id;
        $this->postJson('/api/voice/mobile/sessions', $this->data($session->device_id))->assertStatus(409);
        $this->postJson($base.'/outbound', ['to' => '+12025550199', 'idempotency_key' => $sdk])->assertForbidden();
        $this->getJson($base.'/calls/'.$sdk.'/state')->assertForbidden();
        $this->postJson($base.'/incoming/'.$sdk.'/claim', ['idempotency_key' => $sdk, 'offer_token' => 'unused'])->assertForbidden();
        $this->assertSame($this->token->id, $session->fresh()->personal_access_token_id);
        Http::assertNothingSent();
        $this->token->delete();
        $this->postJson($base.'/calls/'.$sdk.'/end', ['idempotency_key' => $sdk])->assertOk();
        $this->deleteJson($base)->assertOk();
        $this->assertSame('revoked', $session->fresh()->status);
        $this->assertNotNull($second->fresh());
    }

    public function test_other_account_cannot_clean_up_the_old_owners_session(): void
    {
        $session = $this->ready();
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/voice/mobile/sessions/'.$session->id)->assertNotFound();
        $this->assertNull($session->fresh()->revoked_at);
        Http::assertNothingSent();
    }

    public function test_failed_cleanup_never_restores_deleted_token_eligibility_and_reconciles(): void
    {
        $session = $this->ready(); $this->failCleanup = true;
        $this->deleteJson('/api/voice/mobile/sessions/'.$session->id)->assertStatus(503);
        $this->token->delete();
        $this->assertFalse($this->service()->registered($session));
        $this->assertFalse($this->service()->authorized($session));
        $this->assertSame('revocation_pending', $session->fresh()->status);
        $this->failCleanup = false; $this->service()->reconcile();
        $this->assertSame('revoked', $session->fresh()->status);
    }

    public function test_reconcile_revokes_unbound_deleted_and_expired_tokens_only(): void
    {
        $active = $this->ready();
        $legacy = $this->ready(); $legacy->update(['personal_access_token_id' => null]);
        $oldToken = $this->staff->createToken('old-isolated', ['*'], now()->subSecond())->accessToken;
        $expired = $this->ready($oldToken);
        $deletedToken = $this->staff->createToken('deleted-isolated')->accessToken;
        $deleted = $this->ready($deletedToken); $deletedToken->delete();
        $this->service()->reconcile();
        foreach ([$legacy, $expired, $deleted] as $session) {
            $this->assertSame('revoked', $session->fresh()->status);
            $this->assertNull($session->fresh()->push_token_hash);
        }
        $this->assertSame('ready', $active->fresh()->status);
        $this->assertTrue($this->service()->registered($active));
    }

    public function test_revoked_login_cannot_receive_new_or_already_queued_incoming_offer(): void
    {
        $session = $this->ready();
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'from_phone' => '+12025550199', 'to_phone' => '+12025550100', 'call_control_id' => 'isolated-customer']);
        $incoming = VoiceIncomingOffer::create(['voice_call_id' => $call->id, 'eligible_user_ids' => [$this->staff->id], 'expires_at' => now()->addSeconds(45)]);
        $queued = VoiceMobileOffer::create(['incoming_offer_id' => $incoming->id, 'session_id' => $session->id, 'expires_at' => $incoming->expires_at, 'client_state' => base64_encode((string) Str::uuid())]);
        $this->token->delete();
        app(VoiceMobileCallService::class)->offer($incoming);
        Queue::assertNothingPushed();
        app(VoiceMobileCallService::class)->dial($queued->id);
        $this->assertSame('cancel_requested', $queued->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_revoked_login_outbound_webhook_cannot_bridge_a_reserved_call(): void
    {
        $session = $this->ready();
        $ticket = VoiceMobileTicket::create(['session_id' => $session->id, 'idempotency_key' => (string) Str::uuid(), 'destination' => '+12025550199', 'caller_number' => '+12025550100', 'expires_at' => now()->addSeconds(45)]);
        $proof = app(VoiceMobileProof::class)->token('outbound', $session->id, $ticket->id);
        $this->token->delete();
        $result = app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => [
            'connection_id' => $session->connection_id, 'direction' => 'outgoing', 'to' => 'sip:mobile-'.$ticket->id.'@sip.telnyx.com',
            'call_control_id' => 'isolated-sdk-agent', 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $proof]],
        ]]]);
        $this->assertSame('rejected_mobile_outbound', $result['status']);
        $this->assertSame(0, VoiceCall::count());
        Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v2/calls');
    }

    public function test_logout_during_provider_provision_never_returns_jwt(): void
    {
        $this->beforeMaterial = fn () => $this->token->delete();
        $this->postJson('/api/voice/mobile/sessions', $this->data())->assertUnauthorized()->assertJsonMissingPath('jwt');
        $session = VoiceMobileSession::sole();
        $this->assertSame('revocation_pending', $session->status);
        $this->assertNull($session->push_token_hash);
        $this->assertFalse($this->service()->registered($session));
    }

    public function test_identity_changed_during_material_fetch_never_returns_stale_jwt_or_revokes_current_registration(): void
    {
        $data = $this->data();
        $first = $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()->json();
        $session = VoiceMobileSession::findOrFail($first['session_id']);
        $prior = $session->only(['connection_id', 'sip_username', 'push_token_hash', 'personal_access_token_id', 'expires_at', 'registration_expires_at']);
        $this->beforeMaterial = fn () => VoiceMobileSession::whereKey($session->id)
            ->update(['credential_id' => 'concurrently-adopted-credential']);

        $this->postJson('/api/voice/mobile/sessions', $data)->assertStatus(409)->assertJsonMissingPath('jwt');

        $session->refresh();
        $this->assertSame('concurrently-adopted-credential', $session->credential_id);
        $this->assertSame('ready', $session->status);
        $this->assertNull($session->revoked_at);
        $this->assertEquals($prior, $session->only(array_keys($prior)), 'A stale bootstrap must not overwrite the current registration.');
        $this->assertTrue($this->service()->registered($session));
        $this->assertTrue($this->service()->authorized($session));
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_omitted_push_refresh_cannot_rebind_a_previous_login_or_environment(): void
    {
        $session = $this->ready();
        $hash = $session->push_token_hash;
        $data = $this->data($session->device_id);
        unset($data['push_token']);
        config(['mobile_voice.push_credentials.production' => 'test-prod-push']);
        $this->postJson('/api/voice/mobile/sessions', array_replace($data, ['apns_environment' => 'production']))->assertStatus(409);
        $this->signIn($this->staff);
        $this->postJson('/api/voice/mobile/sessions', $data)->assertStatus(409);

        $this->assertSame($this->token->id, $session->fresh()->personal_access_token_id);
        $this->assertSame($hash, $session->fresh()->push_token_hash);
        Http::assertNothingSent();
    }

    public static function interruptedPushRefresh(): array
    {
        return ['logout' => ['logout'], 'session revoke' => ['revoke']];
    }

    #[DataProvider('interruptedPushRefresh')]
    public function test_omitted_push_refresh_cannot_restore_registration_after_logout_or_revocation(string $interruption): void
    {
        $data = $this->data();
        $first = $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()->json();
        $session = VoiceMobileSession::findOrFail($first['session_id']);
        $this->assertTrue($this->service()->registered($session));
        unset($data['push_token']);
        $this->beforeMaterial = function () use ($interruption, $session) {
            if ($interruption === 'logout') {
                $this->token->delete();
            } else {
                $this->service()->revoke($session);
            }
        };

        $this->postJson('/api/voice/mobile/sessions', $data)->assertUnauthorized()->assertJsonMissingPath('jwt');

        $session->refresh();
        $this->assertNotNull($session->revoked_at);
        $this->assertNull($session->push_token);
        $this->assertNull($session->push_token_hash);
        $this->assertFalse($this->service()->registered($session));
        $this->assertFalse($this->service()->authorized($session));
    }
}
