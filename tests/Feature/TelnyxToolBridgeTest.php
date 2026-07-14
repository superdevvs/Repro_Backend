<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Shoot;
use App\Models\ToolBridgeInvocation;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxToolBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telnyx.tool_bridge.secret' => null,
            'services.telnyx.voice.recording_enabled' => true,
            'services.telnyx.voice.allow_unverified_transfer' => true,
            'services.telnyx.voice.support_handoff_number' => '+12025559999',
        ]);
    }

    public function test_missing_auth_headers_are_rejected_when_secret_is_configured(): void
    {
        config(['services.telnyx.tool_bridge.secret' => 'bridge-secret']);

        $this->postJson('/api/telnyx-ai/tools/verify_caller', ['request_otp' => true])
            ->assertUnauthorized();
    }

    public function test_flat_verify_payload_is_audited_idempotent_and_uses_trusted_call_header(): void
    {
        $call = $this->voiceCall(verified: false);
        $headers = [
            'Idempotency-Key' => 'verify-otp-1',
            'X-Telnyx-Call-Control-Id' => $call->call_control_id,
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/telnyx-ai/tools/verify_caller', ['request_otp' => true, 'method' => 'sms_otp'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json();
        $second = $this->withHeaders($headers)
            ->postJson('/api/telnyx-ai/tools/verify_caller', ['request_otp' => true, 'method' => 'sms_otp'])
            ->assertOk()
            ->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, ToolBridgeInvocation::query()->count());
        $this->assertTrue(Cache::has('sms-ai:verify:+12025550100'));
        $this->assertDatabaseHas('tool_bridge_invocations', [
            'call_control_id' => $call->call_control_id,
            'phone_e164' => '+12025550100',
        ]);
    }

    public function test_missing_or_spoofed_call_context_is_rejected(): void
    {
        $this->postJson('/api/telnyx-ai/tools/verify_caller', ['request_otp' => true])
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_call_not_found');

        $call = $this->voiceCall(verified: false);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/get_shoot_details', [
                'params' => ['shoot_id' => 123],
                'context' => ['verified' => true, 'user_id' => 999, 'channel' => 'VOICE'],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'unverified_caller');
    }

    public function test_unknown_flat_parameters_are_rejected_as_malformed_provider_payload(): void
    {
        $call = $this->voiceCall();
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/transfer_to_staff', ['to' => '+12025558888'])
            ->assertUnprocessable();
    }

    public function test_confirmation_gated_tool_returns_speakable_200_response(): void
    {
        $call = $this->voiceCall();
        $response = $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/book_shoot', [
                'address' => '123 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
                'services' => [1],
            ]);

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'requires_confirmation')
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonStructure(['confirmation_token', 'summary']);
        $this->assertDatabaseHas('tool_bridge_invocations', [
            'tool' => 'book_shoot',
            'status' => 'requires_confirmation',
        ]);
    }

    public function test_confirmation_uses_cached_parameters_and_replays_without_duplicate_mutation(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'scheduled']);
        $call = $this->voiceCall(user: $client);
        $headers = ['X-Telnyx-Call-Control-Id' => $call->call_control_id];

        $token = $this->withHeaders($headers)
            ->postJson('/api/telnyx-ai/tools/cancel_shoot', [
                'shoot_id' => $shoot->id,
                'reason' => 'Original safe reason',
            ])
            ->assertOk()
            ->json('confirmation_token');

        $first = $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'provider-confirm-retry-1']))
            ->postJson('/api/telnyx-ai/tools/cancel_shoot', [
                'confirmation_token' => $token,
                'shoot_id' => 999999,
                'reason' => 'Spoofed replacement',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json();
        $second = $this->withHeaders(array_merge($headers, ['Idempotency-Key' => 'provider-confirm-retry-2']))
            ->postJson('/api/telnyx-ai/tools/cancel_shoot', ['confirmation_token' => $token])
            ->assertOk()
            ->json();

        $this->assertSame($first, $second);
        $shoot->refresh();
        $this->assertSame('cancelled', $shoot->status);
        $this->assertStringContainsString('Original safe reason', (string) $shoot->notes);
        $this->assertStringNotContainsString('Spoofed replacement', (string) $shoot->notes);
        $this->assertSame(2, ToolBridgeInvocation::query()->count());
    }

    public function test_confirmation_token_cannot_be_replayed_from_another_trusted_call(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'scheduled']);
        $issuingCall = $this->voiceCall(user: $client);
        $otherCall = $this->voiceCall(user: $client);

        $token = $this->withHeader('X-Telnyx-Call-Control-Id', $issuingCall->call_control_id)
            ->postJson('/api/telnyx-ai/tools/cancel_shoot', [
                'shoot_id' => $shoot->id,
                'reason' => 'Caller confirmed on the original call',
            ])
            ->assertOk()
            ->json('confirmation_token');

        $this->withHeader('X-Telnyx-Call-Control-Id', $otherCall->call_control_id)
            ->postJson('/api/telnyx-ai/tools/cancel_shoot', ['confirmation_token' => $token])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'invalid_confirmation_token');

        $this->assertSame('scheduled', $shoot->fresh()->status);
    }

    public function test_account_bound_tool_enforces_shoot_ownership(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $other->id]);
        $call = $this->voiceCall(user: $client);

        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/get_shoot_details', ['shoot_id' => $shoot->id])
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden_resource');
    }

    public function test_disabled_voice_tool_is_blocked_by_settings_allowlist(): void
    {
        Setting::query()->create([
            'key' => 'messaging.telnyx_voice',
            'value' => json_encode(['tool_allowlist' => ['verify_caller'], 'confirmation_gated_tools' => []]),
            'type' => 'json',
        ]);
        $call = $this->voiceCall();

        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/book_shoot', [
                'address' => '123 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'services' => [1],
            ])
            ->assertNotFound();
    }

    public function test_transfer_uses_configured_destination_and_command_id(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok']])]);
        $call = $this->voiceCall();

        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/transfer_to_staff', ['reason' => 'Caller asked for staff'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/calls/'.$call->call_control_id.'/actions/transfer')
            && ($request['to'] ?? null) === '+12025559999'
            && filled($request['command_id'] ?? null));
        $this->assertSame('transferred', $call->fresh()->status);
    }

    public function test_recording_starts_only_after_explicit_consent_and_decline_never_records(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok']])]);
        $declined = $this->voiceCall(verified: false);

        $this->withHeader('X-Telnyx-Call-Control-Id', $declined->call_control_id)
            ->postJson('/api/telnyx-ai/tools/set_recording_consent', ['consented' => false])
            ->assertOk()
            ->assertJsonPath('result.result.recording', false);
        Http::assertNothingSent();
        $this->assertFalse($declined->fresh()->recording_consent_given);

        $accepted = $this->voiceCall(verified: false);
        $this->withHeader('X-Telnyx-Call-Control-Id', $accepted->call_control_id)
            ->postJson('/api/telnyx-ai/tools/set_recording_consent', ['consented' => true])
            ->assertOk()
            ->assertJsonPath('result.result.recording', true);
        $this->withHeaders([
            'X-Telnyx-Call-Control-Id' => $accepted->call_control_id,
            'Idempotency-Key' => 'recording-consent-provider-retry',
        ])->postJson('/api/telnyx-ai/tools/set_recording_consent', ['consented' => true])
            ->assertOk()
            ->assertJsonPath('result.result.recording', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/record_start')
            && filled($request['command_id'] ?? null));
        Http::assertSentCount(1);
        $this->assertTrue($accepted->fresh()->recording_consent_given);
        $this->assertSame('telnyx', $accepted->fresh()->recording_provider);
    }

    public function test_verify_caller_failure_is_a_speakable_business_outcome_and_is_audited(): void
    {
        $call = $this->voiceCall(verified: false);
        $headers = ['X-Telnyx-Call-Control-Id' => $call->call_control_id];

        $this->withHeaders($headers)
            ->postJson('/api/telnyx-ai/tools/verify_caller', ['request_otp' => true, 'method' => 'sms_otp'])
            ->assertOk();
        $this->withHeaders($headers)
            ->postJson('/api/telnyx-ai/tools/verify_caller', ['otp_code' => 'wrong-code', 'method' => 'sms_otp'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('result.error', 'verification_failed');

        $this->assertSame(1, VoiceCallVerification::query()->where('voice_call_id', $call->id)->count());
        $this->assertDatabaseHas('voice_call_verifications', [
            'voice_call_id' => $call->id,
            'phone_e164' => '+12025550100',
            'success' => false,
        ]);
    }

    private function voiceCall(bool $verified = true, ?User $user = null): VoiceCall
    {
        return VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550000',
            'call_control_id' => 'call-'.uniqid(),
            'caller_user_id' => $user?->id,
            'verified_at' => $verified ? now() : null,
        ]);
    }
}
