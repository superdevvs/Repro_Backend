<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceMobileOffer;
use App\Models\VoiceMobileSession;
use App\Models\VoiceMobileTicket;
use App\Services\Voice\VoiceBrowserCallService;
use App\Services\Voice\VoiceIncomingOfferService;
use App\Services\Voice\VoiceMobileCallService;
use App\Services\Voice\VoiceMobileProof;
use App\Services\Voice\VoiceMobileSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoiceMobileTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private array $connections = [];

    private int $dials = 0;

    private array $credentials = [];

    private bool $ambiguousCredential = false;

    private bool $failRevoke = false;

    private bool $failHangup = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_voice.enabled' => true, 'mobile_voice.push_verified' => true,
            'mobile_voice.bundle_id' => 'com.example.repro', 'mobile_voice.push_credentials.debug' => 'test-debug-push', 'mobile_voice.push_credentials.production' => 'test-prod-push',
            'services.telnyx.api_key' => 'fake', 'services.telnyx.from_number' => '+12025550100', 'services.telnyx.voice.browser_enabled' => true,
            'services.telnyx.voice.credential_connection_id' => 'browser', 'services.telnyx.voice.connection_id' => 'server',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice', 'services.telnyx.voice.outbound_mode' => 'all', 'services.voice.canary_mode' => false,
            'voice_calls.realtime_connection' => 'database']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->staff = User::factory()->create(['role' => 'admin']);
        \App\Models\VoiceLinePolicy::create(['phone_number' => '+12025550100', 'mode' => 'staff_voicemail',
            'recipient_ids' => [$this->staff->id], 'recipient_roles' => [], 'timezone' => 'UTC', 'weekly_hours' => []]);
        $this->staff->withAccessToken($this->staff->createToken('isolated-native-voice-test')->accessToken);
        $this->actingAs($this->staff, 'sanctum');
        Http::fake(function ($r) {
            $p = parse_url($r->url(), PHP_URL_PATH);
            if ($p === '/v2/credential_connections' && $r->method() === 'GET') {
                return Http::response(['data' => array_values($this->connections)]);
            }
            if ($p === '/v2/credential_connections' && $r->method() === 'POST') {
                $id = 'mobile-'.(count($this->connections) + 1);
                $this->connections[$id] = array_merge($r->data(), ['id' => $id]);

                return Http::response(['data' => $this->connections[$id]]);
            }
            if (preg_match('#/credential_connections/([^/]+)$#', $p, $m)) {
                if ($r->method() === 'PATCH') {
                    if ($this->failRevoke) {
                        return Http::response([], 503);
                    } $this->connections[$m[1]] = array_merge($this->connections[$m[1]] ?? [], $r->data());
                }

                return Http::response(['data' => $this->connections[$m[1]] ?? []]);
            }
            if ($p === '/v2/telephony_credentials' && $r->method() === 'GET') {
                return Http::response(['data' => array_values($this->credentials)]);
            }
            if ($p === '/v2/telephony_credentials' && $r->method() === 'POST') {
                $id = 'credential-'.$r['connection_id'];
                $this->credentials[$id] = ['id' => $id, 'name' => $r['name'], 'resource_id' => $r['connection_id'], 'sip_username' => 'gencred'.Str::random(12)];

                return $this->ambiguousCredential ? Http::response([], 503) : Http::response(['data' => $this->credentials[$id]]);
            }
            if (str_ends_with($p, '/token')) {
                return Http::response('header.'.rtrim(strtr(base64_encode(json_encode(['exp' => now()->addHour()->timestamp])), '+/', '-_'), '=').'.signature');
            }
            if (str_starts_with($p, '/v2/telephony_credentials/')) {
                return Http::response(['data' => []]);
            }
            if ($p === '/v2/calls') {
                return Http::response(['data' => ['call_control_id' => 'mobile-leg-'.++$this->dials, 'call_session_id' => 'session-'.$this->dials]]);
            }
            if ($p === '/v2/conferences') {
                return Http::response(['data' => ['id' => 'conference-mobile']]);
            }
            if (str_contains($p, '/actions/')) {
                if ($this->failHangup && str_ends_with($p, '/actions/hangup')) {
                    return Http::response([], 503);
                }

                return Http::response(['data' => ['result' => 'ok']]);
            }
            throw new \RuntimeException('Unexpected fake provider request: '.$r->method().' '.$p);
        });
    }

    private function bootstrap(?string $device = null, ?string $push = null): array
    {
        return $this->postJson('/api/voice/mobile/sessions', ['device_id' => $device ?? (string) Str::uuid(), 'platform' => 'ios', 'apns_environment' => 'debug', 'push_token' => $push ?? str_repeat('a', 64)])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function incoming(array $session, ?VoiceIncomingOffer $shared = null): VoiceMobileOffer
    {
        $shared ??= VoiceIncomingOffer::create(['voice_call_id' => VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'from_phone' => '+12025550199', 'to_phone' => '+12025550100', 'call_control_id' => 'customer-'.Str::uuid()])->id,
            'eligible_user_ids' => [$this->staff->id], 'expires_at' => now()->addSeconds(45)]);
        $o = VoiceMobileOffer::create(['incoming_offer_id' => $shared->id, 'session_id' => $session['session_id'], 'expires_at' => $shared->expires_at, 'client_state' => base64_encode((string) Str::uuid())]);
        app(VoiceMobileCallService::class)->dial($o->id);

        return $o->fresh();
    }

    private function claim(array $s, VoiceMobileOffer $o, ?string $sdk = null, ?string $proof = null)
    {
        $sdk ??= (string) Str::uuid();

        return $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/incoming/'.$sdk.'/claim', ['idempotency_key' => $sdk, 'offer_token' => $proof ?? app(VoiceMobileProof::class)->token('incoming', $s['session_id'], $o->id)]);
    }

    public function test_bootstrap_has_stable_session_bound_environment_and_encrypted_push(): void
    {
        $device = (string) Str::uuid();
        $one = $this->bootstrap($device);
        $two = $this->bootstrap($device, str_repeat('b', 64));
        $this->assertSame($one['session_id'], $two['session_id']);
        $this->assertCount(1, $this->connections);
        $this->assertTrue($two['receive_calls']);
        $this->assertFalse($two['push_when_active']);
        $this->assertArrayNotHasKey('credential_id', $two);
        $raw = DB::table('voice_mobile_sessions')->value('push_token');
        $this->assertStringNotContainsString(str_repeat('b', 64), $raw);
        $this->postJson('/api/voice/mobile/sessions', ['device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'production', 'push_token' => str_repeat('b', 64)])->assertStatus(409);
        $none = $this->bootstrap($device, '');
        $this->assertFalse($none['receive_calls']);
    }

    public function test_refresh_without_push_token_preserves_incoming_registration_and_identity(): void
    {
        $device = (string) Str::uuid();
        $first = $this->bootstrap($device);
        $session = VoiceMobileSession::findOrFail($first['session_id']);
        $encryptedPush = $session->getRawOriginal('push_token');
        $hash = $session->push_token_hash;
        $credential = $session->credential_id;
        $session->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/voice/mobile/sessions', [
            'device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug',
        ])->assertOk()->assertJsonPath('session_id', $session->id)->assertJsonPath('receive_calls', true);

        $session->refresh();
        $this->assertSame($encryptedPush, $session->getRawOriginal('push_token'));
        $this->assertSame(str_repeat('a', 64), $session->push_token);
        $this->assertSame($hash, $session->push_token_hash);
        $this->assertSame($credential, $session->credential_id);
        $this->assertTrue(app(VoiceMobileSessionService::class)->registered($session));
        $this->assertTrue(app(VoiceMobileSessionService::class)->authorized($session));
        $this->assertCount(1, $this->connections);
        $this->assertCount(1, $this->credentials);
        $this->assertSame(0, $this->dials);
    }

    public function test_first_bootstrap_without_push_token_is_outgoing_only_and_cannot_take_another_devices_token(): void
    {
        $existing = $this->bootstrap();
        $device = (string) Str::uuid();
        $data = ['device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug'];
        $response = $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()
            ->assertJsonPath('receive_calls', false)->assertJsonPath('make_calls', true)->json();
        $session = VoiceMobileSession::findOrFail($response['session_id']);
        $this->assertNotSame($existing['session_id'], $session->id);
        $this->assertNull($session->push_token);
        $this->assertNull($session->push_token_hash);
        $this->assertFalse(app(VoiceMobileSessionService::class)->registered($session));
        $this->assertTrue(app(VoiceMobileSessionService::class)->authorized($session));

        $this->postJson('/api/voice/mobile/sessions', $data + ['push_token' => str_repeat('a', 64)])->assertStatus(409);
        $this->assertNull($session->fresh()->push_token_hash);
        $this->assertTrue(app(VoiceMobileSessionService::class)->registered(VoiceMobileSession::findOrFail($existing['session_id'])));
    }

    public static function explicitPushRemoval(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    #[DataProvider('explicitPushRemoval')]
    public function test_explicit_push_removal_is_not_restored_by_an_omitted_refresh(?string $push): void
    {
        $device = (string) Str::uuid();
        $first = $this->bootstrap($device);
        $data = ['device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug'];
        $this->postJson('/api/voice/mobile/sessions', $data + ['push_token' => $push])->assertOk()
            ->assertJsonPath('session_id', $first['session_id'])->assertJsonPath('receive_calls', false);
        $this->postJson('/api/voice/mobile/sessions', $data)->assertOk()
            ->assertJsonPath('session_id', $first['session_id'])->assertJsonPath('receive_calls', false);

        $session = VoiceMobileSession::findOrFail($first['session_id']);
        $this->assertNull($session->push_token);
        $this->assertNull($session->push_token_hash);
        $this->assertFalse(app(VoiceMobileSessionService::class)->registered($session));
        $this->assertTrue(app(VoiceMobileSessionService::class)->authorized($session));
    }

    public function test_omitted_push_token_does_not_restore_a_revoked_registration(): void
    {
        $device = (string) Str::uuid();
        $first = $this->bootstrap($device);
        $this->deleteJson('/api/voice/mobile/sessions/'.$first['session_id'])->assertOk();
        $second = $this->postJson('/api/voice/mobile/sessions', [
            'device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug',
        ])->assertOk()->assertJsonPath('receive_calls', false)->json();

        $this->assertNotSame($first['session_id'], $second['session_id']);
        foreach ([$first['session_id'], $second['session_id']] as $id) {
            $session = VoiceMobileSession::findOrFail($id);
            $this->assertNull($session->push_token);
            $this->assertNull($session->push_token_hash);
            $this->assertFalse(app(VoiceMobileSessionService::class)->registered($session));
        }
        $this->assertNotNull(VoiceMobileSession::findOrFail($first['session_id'])->revoked_at);
    }

    public function test_omitted_push_token_cannot_inherit_another_accounts_device_registration(): void
    {
        $device = (string) Str::uuid();
        $first = $this->bootstrap($device);
        $other = User::factory()->create(['role' => 'admin']);
        $other->withAccessToken($other->createToken('isolated-other-mobile-user')->accessToken);
        $this->actingAs($other, 'sanctum');

        $second = $this->postJson('/api/voice/mobile/sessions', [
            'device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug',
        ])->assertOk()->assertJsonPath('receive_calls', false)->json();

        $this->assertNotSame($first['session_id'], $second['session_id']);
        $this->assertSame($other->id, VoiceMobileSession::findOrFail($second['session_id'])->user_id);
        $this->assertNull(VoiceMobileSession::findOrFail($second['session_id'])->push_token_hash);
        $this->assertTrue(app(VoiceMobileSessionService::class)->registered(VoiceMobileSession::findOrFail($first['session_id'])));
    }

    public function test_disabled_gate_and_client_role_cannot_provision(): void
    {
        config(['mobile_voice.enabled' => false]);
        $data = ['device_id' => (string) Str::uuid(), 'platform' => 'ios', 'apns_environment' => 'debug', 'push_token' => ''];
        $this->postJson('/api/voice/mobile/sessions', $data)->assertStatus(503);
        Http::assertNothingSent();
        config(['mobile_voice.enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum');
        $this->postJson('/api/voice/mobile/sessions', $data)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_impersonation_and_cross_owner_registration_are_rejected(): void
    {
        $s = $this->bootstrap();
        $this->withHeader('X-Impersonate-User-Id', (string) $this->staff->id)->deleteJson('/api/voice/mobile/sessions/'.$s['session_id'])->assertForbidden();
        $this->flushHeaders();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->deleteJson('/api/voice/mobile/sessions/'.$s['session_id'])->assertNotFound();
    }

    public function test_outbound_is_reservation_only_idempotent_and_scoped(): void
    {
        $s = $this->bootstrap();
        $key = (string) Str::uuid();
        $path = '/api/voice/mobile/sessions/'.$s['session_id'].'/outbound';
        $a = $this->postJson($path, ['to' => '+12025550199', 'idempotency_key' => $key])->assertOk()->json();
        $b = $this->postJson($path, ['to' => '+12025550199', 'idempotency_key' => $key])->assertOk()->json();
        $this->assertSame($a, $b);
        $this->assertSame(0, $this->dials);
        $this->assertSame(0, VoiceCall::count());
        $this->postJson($path, ['to' => '+12025550198', 'idempotency_key' => $key])->assertStatus(409);
        $this->postJson($path, ['to' => '+12025550198', 'idempotency_key' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/calls/'.$a['call_id'].'/end', ['idempotency_key' => $a['call_id']])->assertOk();
        $this->assertSame('cancelled', VoiceMobileTicket::find($a['call_id'])->state);
    }

    public function test_only_signed_matching_connection_and_offer_can_start_customer_bridge(): void
    {
        $s = $this->bootstrap();
        $t = $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', ['to' => '+12025550199', 'idempotency_key' => (string) Str::uuid()])->assertOk()->json();
        $connection = VoiceMobileSession::find($s['session_id'])->connection_id;
        $p = ['connection_id' => $connection, 'direction' => 'outgoing', 'to' => $t['sdk_destination'], 'call_control_id' => 'sdk-agent', 'call_session_id' => 'provider-session', 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $t['offer_token']]]];
        $wrong = $p;
        $wrong['custom_headers'][0]['value'] = 'wrong';
        $wrong['call_control_id'] = 'wrong-sdk-agent';
        $result = app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $wrong]]);
        $this->assertSame('rejected_mobile_outbound', $result['status']);
        $this->assertSame(0, VoiceCall::count());
        $result = app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $p]]);
        $this->assertSame('processed_mobile_outbound', $result['status']);
        $this->assertSame(1, VoiceCall::count());
        $this->assertSame(0, $this->dials);
        app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => 'call.answered', 'payload' => $p]]);
        $this->assertSame(0, $this->dials); // Customer is not dialed until the agent joins its conference.
        app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => 'conference.participant.joined', 'payload' => array_merge($p, ['conference_id' => 'conference-mobile'])]]);
        $this->assertSame(1, $this->dials);
        app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $p]]);
        $this->assertSame(1, VoiceCall::count());
    }

    public function test_cancelled_outbound_cannot_dial_customer_after_failed_hangup_and_late_agent_events(): void
    {
        $s = $this->bootstrap();
        $ticket = $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', [
            'to' => '+12025550199', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json();
        $payload = ['connection_id' => VoiceMobileSession::findOrFail($s['session_id'])->connection_id,
            'direction' => 'outgoing', 'to' => $ticket['sdk_destination'], 'call_control_id' => 'cancel-test-agent',
            'call_session_id' => 'cancel-test-provider-session',
            'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $ticket['offer_token']]]];
        app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $payload]]);
        $browser = VoiceBrowserCall::sole();
        $end = '/api/voice/mobile/sessions/'.$s['session_id'].'/calls/'.$ticket['call_id'].'/end';
        $this->failHangup = true;
        $this->postJson($end, ['idempotency_key' => $ticket['call_id']])->assertStatus(503);
        $this->assertSame('cancelled', VoiceMobileTicket::findOrFail($ticket['call_id'])->state);
        $this->assertSame('ending', $browser->fresh()->state);
        $this->assertNull($browser->voiceCall->fresh()->ended_at);

        foreach (['call.answered', 'conference.participant.joined'] as $event) {
            try {
                app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(),
                    'event_type' => $event, 'payload' => $payload + ['conference_id' => 'conference-mobile']]]);
            } catch (\RuntimeException $e) {
                $this->assertSame('The phone provider could not complete the request.', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->dials, 'A cancelled attempt must never dial the customer after late staff events.');
        $this->assertSame('ending', $browser->fresh()->state);
        Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/v2/conferences');

        $this->failHangup = false;
        $this->postJson($end, ['idempotency_key' => $ticket['call_id']])->assertOk();
        $this->assertSame('ended', $browser->fresh()->state);
        $this->assertNotNull($browser->voiceCall->fresh()->ended_at);
        $this->assertSame(0, $this->dials);
    }

    public function test_durable_native_cancellation_blocks_late_join_before_shared_end_obtains_call_lock(): void
    {
        $s = $this->bootstrap();
        $ticket = $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', [
            'to' => '+12025550199', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json();
        $payload = ['connection_id' => VoiceMobileSession::findOrFail($s['session_id'])->connection_id,
            'direction' => 'outgoing', 'to' => $ticket['sdk_destination'], 'call_control_id' => 'cancel-test-agent',
            'call_session_id' => 'cancel-test-provider-session',
            'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $ticket['offer_token']]]];
        app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $payload]]);
        app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(),
            'event_type' => 'call.answered', 'payload' => $payload]]);
        $browser = VoiceBrowserCall::sole();
        $this->assertSame('connecting_agent', $browser->state);

        // Model the durable End write winning before its shared call-lock acquisition.
        VoiceMobileTicket::findOrFail($ticket['call_id'])->update(['state' => 'cancelled']);
        app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(),
            'event_type' => 'conference.participant.joined', 'payload' => $payload + ['conference_id' => 'conference-mobile']]]);

        $this->assertSame(0, $this->dials);
        $this->assertSame('ended', $browser->fresh()->state);
        $this->assertSame('cancelled', $browser->voiceCall->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/cancel-test-agent/actions/hangup'));
    }

    public function test_active_native_call_stays_active_until_end_and_late_customer_join_cannot_reopen_it(): void
    {
        $s = $this->bootstrap();
        $ticket = $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', [
            'to' => '+12025550199', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json();
        $payload = ['connection_id' => VoiceMobileSession::findOrFail($s['session_id'])->connection_id,
            'direction' => 'outgoing', 'to' => $ticket['sdk_destination'], 'call_control_id' => 'active-test-agent',
            'call_session_id' => 'active-test-provider-session',
            'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $ticket['offer_token']]]];
        app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $payload]]);
        $event = fn (string $type, array $p) => app(VoiceBrowserCallService::class)->handleWebhook([
            'data' => ['id' => (string) Str::uuid(), 'event_type' => $type, 'payload' => $p],
        ]);
        $event('call.answered', $payload);
        $event('conference.participant.joined', $payload + ['conference_id' => 'conference-mobile']);
        $customer = ['call_control_id' => 'mobile-leg-1', 'conference_id' => 'conference-mobile'];
        $event('call.answered', $customer);
        $event('conference.participant.joined', $customer);
        $browser = VoiceBrowserCall::sole();
        $this->assertSame('active', $browser->state);
        $this->assertSame('started', VoiceMobileTicket::findOrFail($ticket['call_id'])->state);
        $event('conference.participant.joined', $customer);
        $this->assertSame('active', $browser->fresh()->state);
        $this->assertSame(1, $this->dials);

        $end = '/api/voice/mobile/sessions/'.$s['session_id'].'/calls/'.$ticket['call_id'].'/end';
        $this->failHangup = true;
        $this->postJson($end, ['idempotency_key' => $ticket['call_id']])->assertStatus(503);
        try {
            $event('conference.participant.joined', $customer);
        } catch (\RuntimeException $e) {
            $this->assertSame('The phone provider could not complete the request.', $e->getMessage());
        }
        $this->assertSame('ending', $browser->fresh()->state);
        $this->assertSame(1, $this->dials);

        $this->failHangup = false;
        $this->postJson($end, ['idempotency_key' => $ticket['call_id']])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/mobile-leg-1/actions/hangup'));
        $event('call.hangup', $customer);
        $this->assertSame('ended', $browser->fresh()->state);
        $this->assertNotNull($browser->voiceCall->fresh()->ended_at);
        $this->assertSame(1, $this->dials);
    }

    public function test_two_native_devices_share_one_winner_and_replay_is_stable(): void
    {
        $s = $this->bootstrap();
        $second = $this->bootstrap(null, str_repeat('b', 64));
        $o = $this->incoming($s);
        $other = $this->incoming($second, $o->incomingOffer);
        $sdk = (string) Str::uuid();
        $this->claim($s, $o, $sdk)->assertOk();
        $this->claim($s, $o, $sdk)->assertOk();
        $this->claim($second, $other)->assertStatus(409);
        $this->assertSame(1, \App\Models\VoiceBrowserCall::count());
        $this->assertSame($sdk, $o->fresh()->sdk_call_id);
        app(VoiceMobileCallService::class)->closeLosing($o->incomingOffer->fresh());
        $this->assertSame('cancelled', $other->fresh()->state);
        $this->assertSame('accepted', $o->fresh()->state);
    }

    public function test_proof_tampering_expiry_and_browser_winner_reject_native_claim(): void
    {
        $s = $this->bootstrap();
        $o = $this->incoming($s);
        $this->claim($s, $o, null, $o->id.'.wrong')->assertForbidden();
        $o->incomingOffer->update(['status' => 'claimed', 'claimed_by_id' => $this->staff->id, 'claim_key' => 'browser-won']);
        $this->claim($s, $o)->assertStatus(409);
        $o->incomingOffer->update(['status' => 'waiting', 'expires_at' => now()->subSecond()]);
        $this->claim($s, $o)->assertStatus(409);
    }

    public function test_terminal_webhook_refetches_a_claim_that_wins_after_offer_lookup(): void
    {
        $s = $this->bootstrap();
        $o = $this->incoming($s);
        $sdk = (string) Str::uuid();
        $claimed = false;

        // Pause the webhook immediately after its offer lookup and commit a real
        // claim. The webhook still holds the pre-claim model when it resumes.
        $events = VoiceMobileOffer::getEventDispatcher();
        VoiceMobileOffer::setEventDispatcher(clone $events);
        VoiceMobileOffer::retrieved(function (VoiceMobileOffer $loaded) use ($s, $o, $sdk, &$claimed): void {
            if ($claimed || $loaded->id !== $o->id) {
                return;
            }
            $claimed = true;
            $this->claim($s, $loaded, $sdk)->assertOk();
            $this->assertSame('dialing', $loaded->state);
        });
        try {
            $result = app(VoiceBrowserCallService::class)->handleWebhook(['data' => [
                'id' => (string) Str::uuid(), 'event_type' => 'call.hangup',
                'payload' => ['connection_id' => 'server', 'call_control_id' => $o->call_control_id],
            ]]);
        } finally {
            VoiceMobileOffer::setEventDispatcher($events);
        }

        $this->assertTrue($claimed);
        $this->assertSame('accepted', $o->fresh()->state);
        $this->assertSame('processed', $result['status']);
        $browser = \App\Models\VoiceBrowserCall::findOrFail($o->incomingOffer->fresh()->browser_call_id);
        $this->assertSame('failed', $browser->state);
        $this->assertSame('ended', $browser->legs()->where('role', 'agent')->firstOrFail()->state);
    }

    public function test_terminal_webhook_before_claim_prevents_a_bridge(): void
    {
        $s = $this->bootstrap();
        foreach (['call.hangup', 'call.ended', 'call.failed'] as $type) {
            $o = $this->incoming($s);
            $result = app(VoiceBrowserCallService::class)->handleWebhook(['data' => [
                'id' => (string) Str::uuid(), 'event_type' => $type,
                'payload' => ['connection_id' => 'server', 'call_control_id' => $o->call_control_id],
            ]]);

            $this->assertSame('processed_mobile_offer', $result['status']);
            $this->assertSame('ended', $o->fresh()->state);
            $this->claim($s, $o)->assertStatus(409);
            $this->assertSame('waiting', $o->incomingOffer->fresh()->status);
        }
        $this->assertSame(0, \App\Models\VoiceBrowserCall::count());
    }

    public function test_revoke_denies_new_calls_before_provider_cleanup_and_retry_is_safe(): void
    {
        $s = $this->bootstrap();
        $this->failRevoke = true;
        $path = '/api/voice/mobile/sessions/'.$s['session_id'];
        $this->deleteJson($path)->assertStatus(503);
        $stored = VoiceMobileSession::find($s['session_id']);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/telephony_credentials/'));
        $this->assertNotNull($stored->revoked_at);
        $this->assertNull($stored->push_token);
        $this->assertFalse(app(VoiceMobileSessionService::class)->authorized($stored));
        $this->failRevoke = false;
        $this->deleteJson($path)->assertOk();
        $this->deleteJson($path)->assertOk();
    }

    public function test_expired_credential_is_renewed_for_background_push_without_replacing_identity(): void
    {
        $s = $this->bootstrap();
        $model = VoiceMobileSession::find($s['session_id']);
        $credential = $model->credential_id;
        $model->update(['expires_at' => now()->subHour()]);
        $this->assertFalse(app(VoiceMobileSessionService::class)->authorized($model->fresh()));
        $this->assertTrue(app(VoiceMobileSessionService::class)->reachable($this->staff));
        $o = $this->incoming($s);
        $this->assertSame('dialing', $o->state);
        $this->assertSame($credential, $model->fresh()->credential_id);
        $this->assertTrue($model->fresh()->expires_at->isFuture());
        $model->update(['registration_expires_at' => now()->subSecond()]);
        $this->assertFalse(app(VoiceMobileSessionService::class)->reachable($this->staff));
    }

    public function test_ambiguous_credential_create_reconciles_without_duplicate(): void
    {
        $device = (string) Str::uuid();
        $this->ambiguousCredential = true;
        $this->postJson('/api/voice/mobile/sessions', ['device_id' => $device, 'platform' => 'ios', 'apns_environment' => 'debug', 'push_token' => str_repeat('a', 64)])->assertStatus(503);
        $this->ambiguousCredential = false;
        $s = $this->bootstrap($device);
        $this->assertCount(1, $this->credentials);
        $this->assertCount(1, $this->connections);
        $this->assertTrue($s['make_calls']);
    }

    public function test_cancelled_offer_cannot_claim_and_cleanup_does_not_cancel_winner(): void
    {
        $s = $this->bootstrap();
        $o = $this->incoming($s);
        $o->update(['state' => 'cancel_requested']);
        $this->claim($s, $o)->assertStatus(409);
        $this->assertSame('waiting', $o->incomingOffer->fresh()->status);
        $o->update(['state' => 'dialing']);
        $this->claim($s, $o)->assertOk();
        app(VoiceIncomingOfferService::class)->reconcile();
        $this->assertSame('accepted', $o->fresh()->state);
    }

    public function test_permission_removal_blocks_media_but_owner_can_revoke(): void
    {
        $s = $this->bootstrap();
        $this->staff->update(['role' => 'client']);
        $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', ['to' => '+12025550199', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->deleteJson('/api/voice/mobile/sessions/'.$s['session_id'])->assertOk();
    }

    public function test_real_browser_claim_and_native_claim_arbitrate_through_same_offer(): void
    {
        $s = $this->bootstrap();
        $o = $this->incoming($s);
        $browser = VoiceBrowserSession::create(['user_id' => $this->staff->id, 'device_id' => (string) Str::uuid(), 'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour(), 'sip_username' => 'gencredbrowser', 'credential_id' => 'browser-credential']);
        $this->postJson('/api/voice/incoming-offers/'.$o->incoming_offer_id.'/claim', ['device' => 'browser', 'session_id' => $browser->id, 'idempotency_key' => 'browser-first'])->assertOk();
        $this->claim($s, $o)->assertStatus(409);
        $this->assertSame(1, \App\Models\VoiceBrowserCall::count());
        app(VoiceIncomingOfferService::class)->reconcile();
        $this->assertSame('cancelled', $o->fresh()->state);
    }

    public function test_mobile_state_resolves_only_this_devices_proven_call(): void
    {
        $s = $this->bootstrap();
        $other = $this->bootstrap(null, str_repeat('b', 64));
        $o = $this->incoming($s);
        $sdk = (string) Str::uuid();
        $claim = $this->claim($s, $o, $sdk)->assertOk()->json();
        $this->getJson('/api/voice/mobile/sessions/'.$s['session_id'].'/calls/'.$sdk.'/state')->assertOk()->assertJsonPath('voice_call_id', $claim['voice_call_id']);
        $this->getJson('/api/voice/mobile/sessions/'.$other['session_id'].'/calls/'.$sdk.'/state')->assertOk()->assertJsonPath('voice_call_id', null);
    }

    public function test_expired_outbound_proof_never_creates_media(): void
    {
        $s = $this->bootstrap();
        $t = $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/outbound', ['to' => '+12025550199', 'idempotency_key' => (string) Str::uuid()])->assertOk()->json();
        VoiceMobileTicket::find($t['call_id'])->update(['expires_at' => now()->subSecond()]);
        $p = ['connection_id' => VoiceMobileSession::find($s['session_id'])->connection_id, 'direction' => 'outgoing', 'to' => $t['sdk_destination'], 'call_control_id' => 'late-sdk-agent', 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $t['offer_token']]]];
        $result = app(VoiceMobileCallService::class)->webhook(['data' => ['event_type' => 'call.initiated', 'payload' => $p]]);
        $this->assertSame('rejected_mobile_outbound', $result['status']);
        $this->assertSame(0, VoiceCall::count());
    }

    public function test_decline_before_claim_uses_device_proof_and_cannot_cancel_other_device(): void
    {
        $s = $this->bootstrap();
        $other = $this->bootstrap(null, str_repeat('b', 64));
        $o = $this->incoming($s);
        $sdk = (string) Str::uuid();
        $proof = app(VoiceMobileProof::class)->token('incoming', $s['session_id'], $o->id);
        $this->postJson('/api/voice/mobile/sessions/'.$other['session_id'].'/calls/'.$sdk.'/end', ['idempotency_key' => $sdk, 'offer_token' => $proof])->assertNotFound();
        $this->assertSame('dialing', $o->fresh()->state);
        $this->postJson('/api/voice/mobile/sessions/'.$s['session_id'].'/calls/'.$sdk.'/end', ['idempotency_key' => $sdk, 'offer_token' => $proof])->assertOk();
        $this->assertSame('cancelled', $o->fresh()->state);
        $this->assertSame('waiting', $o->incomingOffer->fresh()->status);
        $this->claim($s, $o, $sdk)->assertStatus(409);
    }

    public function test_incoming_proof_is_not_persisted_in_command_payload_or_event_audit(): void
    {
        $s = $this->bootstrap();
        $o = $this->incoming($s);
        $token = app(VoiceMobileProof::class)->token('incoming', $s['session_id'], $o->id);
        $this->assertStringNotContainsString($token, DB::table('voice_browser_commands')->value('payload'));
        $redacted = VoiceMobileProof::redact(['data' => ['custom_headers' => [['name' => 'X-Repro-Mobile-Offer', 'value' => $token]], 'push_token' => 'secret']]);
        $this->assertStringNotContainsString($token, json_encode($redacted));
        $this->assertStringNotContainsString('secret', json_encode($redacted));
    }
}
