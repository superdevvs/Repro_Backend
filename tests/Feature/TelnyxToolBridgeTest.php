<?php

namespace Tests\Feature;

use App\Models\ToolBridgeInvocation;
use App\Models\VoiceCall;
use App\Models\VoiceCallVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxToolBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_auth_headers_are_rejected_when_secret_is_configured(): void
    {
        config(['services.telnyx.tool_bridge.secret' => 'bridge-secret']);

        $this->postJson('/api/telnyx-ai/tools/verify_caller', [
            'params' => ['request_otp' => true],
            'context' => ['phone_e164' => '+12025550100'],
        ])->assertUnauthorized();
    }

    public function test_verify_caller_request_otp_is_audited_and_idempotent(): void
    {
        config(['services.telnyx.tool_bridge.secret' => null]);

        $body = [
            'params' => ['request_otp' => true, 'method' => 'sms_otp'],
            'context' => ['channel' => 'VOICE', 'phone_e164' => '+12025550100'],
        ];

        $first = $this->withHeader('Idempotency-Key', 'verify-otp-1')
            ->postJson('/api/telnyx-ai/tools/verify_caller', $body)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json();

        $second = $this->withHeader('Idempotency-Key', 'verify-otp-1')
            ->postJson('/api/telnyx-ai/tools/verify_caller', $body)
            ->assertOk()
            ->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, ToolBridgeInvocation::query()->count());
        $this->assertTrue(Cache::has('sms-ai:verify:+12025550100'));
    }

    public function test_confirmation_gated_tool_returns_confirmation_token_before_execution(): void
    {
        $response = $this->withHeader('Idempotency-Key', 'confirm-book-1')
            ->postJson('/api/telnyx-ai/tools/book_shoot', [
                'params' => ['address' => '123 Main St'],
                'context' => ['channel' => 'VOICE', 'verified' => true, 'phone_e164' => '+12025550100'],
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'requires_confirmation')
            ->assertJsonStructure(['confirmation_token', 'summary']);

        $this->assertDatabaseHas('tool_bridge_invocations', [
            'tool' => 'book_shoot',
            'status' => 'requires_confirmation',
        ]);
    }

    public function test_account_bound_tools_require_verified_context(): void
    {
        $this->withHeader('Idempotency-Key', 'unverified-shoot-1')
            ->postJson('/api/telnyx-ai/tools/get_shoot_details', [
                'params' => ['shoot_id' => 123],
                'context' => ['channel' => 'VOICE', 'verified' => false, 'phone_e164' => '+12025550100'],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'unverified_caller');
    }

    public function test_transfer_to_staff_is_blocked_over_sms(): void
    {
        $this->withHeader('Idempotency-Key', 'transfer-sms-1')
            ->postJson('/api/telnyx-ai/tools/transfer_to_staff', [
                'params' => ['to' => '+12025559999'],
                'context' => ['channel' => 'SMS', 'verified' => true, 'phone_e164' => '+12025550100'],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'tool_blocked');
    }

    public function test_transfer_to_staff_over_voice_marks_call_transferred(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake();

        $voiceCall = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550000',
            'call_control_id' => 'call-xfer',
        ]);

        $this->withHeader('Idempotency-Key', 'transfer-voice-1')
            ->postJson('/api/telnyx-ai/tools/transfer_to_staff', [
                'params' => ['to' => '+12025559999'],
                'context' => [
                    'channel' => 'VOICE',
                    'verified' => true,
                    'phone_e164' => '+12025550100',
                    'call_control_id' => 'call-xfer',
                    'voice_call_id' => $voiceCall->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $voiceCall->refresh();
        $this->assertSame('transferred', $voiceCall->status);
        $this->assertSame('transferred', $voiceCall->disposition);
        $this->assertSame('+12025559999', $voiceCall->metadata['transferred_to'] ?? null);
    }

    public function test_verify_caller_over_voice_records_verification_row(): void
    {
        $voiceCall = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550000',
        ]);

        // Issue OTP so the verification cache is primed for the wrong-code attempt.
        $this->withHeader('Idempotency-Key', 'voice-verify-issue-1')
            ->postJson('/api/telnyx-ai/tools/verify_caller', [
                'params' => ['request_otp' => true, 'method' => 'sms_otp'],
                'context' => ['channel' => 'VOICE', 'phone_e164' => '+12025550100', 'voice_call_id' => $voiceCall->id],
            ])->assertOk();

        // Submit a wrong code so we exercise the failure-tracking branch.
        $this->withHeader('Idempotency-Key', 'voice-verify-attempt-1')
            ->postJson('/api/telnyx-ai/tools/verify_caller', [
                'params' => ['otp_code' => 'wrong-code', 'method' => 'sms_otp'],
                'context' => ['channel' => 'VOICE', 'phone_e164' => '+12025550100', 'voice_call_id' => $voiceCall->id],
            ])->assertStatus(422);

        $this->assertSame(1, VoiceCallVerification::query()->where('voice_call_id', $voiceCall->id)->count());
        $this->assertDatabaseHas('voice_call_verifications', [
            'voice_call_id' => $voiceCall->id,
            'phone_e164' => '+12025550100',
            'method' => 'sms_otp',
            'success' => false,
        ]);
    }
}
