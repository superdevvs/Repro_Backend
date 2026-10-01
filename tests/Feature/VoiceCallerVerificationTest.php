<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\SmsNumber;
use App\Models\ToolBridgeInvocation;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallToolInvocation;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeSmsProvider;
use App\Services\ReproAi\Tools\IdentityTools;
use App\Services\Voice\VoiceCallerVerificationService;
use App\Services\Voice\VoiceToolBridge;
use App\Support\VoiceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceCallerVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.telnyx.tool_bridge.secret' => null]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        SmsNumber::create(['phone_number' => '+12025550200', 'provider' => 'TELNYX', 'is_default' => true]);
    }

    public function test_accepted_sms_verifies_only_stored_call_and_keeps_role_and_audits_secret_safe(): void
    {
        $user = User::factory()->create(['role' => 'client', 'phone' => '+12025550100']);
        $call = $this->callFor($user);
        $sent = $this->invoke($call, ['request_otp' => true], 'send')->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('result.result.otp_sent', true)->assertJsonPath('result.result.delivery_status', 'accepted');
        $this->invoke($call, ['request_otp' => true], 'send')->assertJson($sent->json());
        $this->assertCount(1, FakeSmsProvider::sent());
        $this->assertSame($call->from_phone, FakeSmsProvider::sent()[0]['to']);
        $code = $this->sentCode();
        $this->assertStringNotContainsString($code, $sent->getContent());
        $this->assertNull($call->fresh()->verified_at);
        $this->assertDatabaseHas('messages', ['send_source' => 'VOICE_CALLER_VERIFICATION', 'status' => 'SENT', 'hidden_from_inbox' => true]);
        $this->invoke($call, ['otp_code' => $code], 'verify')->assertJsonPath('ok', true)->assertJsonPath('result.result.verified', true);
        $this->assertNotNull($call->fresh()->verified_at);
        $this->assertSame($user->id, $call->fresh()->caller_user_id);
        $this->assertSame('client', $user->fresh()->role);
        $this->assertFalse(VoiceCache::store()->has('voice:caller-otp:'.$call->id));
        $audit = ToolBridgeInvocation::where('tool', 'verify_caller')->get()->toJson();
        $this->assertStringNotContainsString($code, $audit);
        $this->assertStringContainsString('<redacted:verification-code>', $audit);
        $call->update(['verified_at' => null]);
        $this->invoke($call, ['otp_code' => $code], 'verify')->assertJsonPath('ok', false);
        $this->invoke($call, ['otp_code' => $code], 'new-attempt')->assertJsonPath('ok', false);
        $this->assertNull($call->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_rejected_blocked_and_missing_receipt_sends_never_claim_sent_or_keep_a_code(): void
    {
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->once()->andThrow(new \RuntimeException('private-provider-detail'));
        $messaging->shouldReceive('sendSms')->once()->andReturn(new Message(['status' => 'BLOCKED']));
        $messaging->shouldReceive('sendSms')->once()->andReturn(new Message(['status' => 'SENT']));
        foreach (range(0, 2) as $index) {
            $call = $this->callFor(null, '+1202555010'.$index);
            $response = app(VoiceCallerVerificationService::class)->verify($call, ['request_otp' => true]);
            $this->assertFalse($response['ok']);
            $this->assertFalse($response['result']['otp_sent']);
            $this->assertSame('verification_delivery_failed', $response['error']);
            $this->assertStringNotContainsString('private-provider-detail', json_encode($response));
            $this->assertFalse(VoiceCache::store()->has('voice:caller-otp:'.$call->id));
            $this->assertNull($call->fresh()->verified_at);
        }
    }

    public function test_forged_scalar_context_cannot_choose_a_call_or_sms_target(): void
    {
        $call = $this->callFor();
        $result = app(IdentityTools::class)->verifyCaller(['request_otp' => true, 'phone_e164' => '+12025559999'],
            ['channel' => 'VOICE', 'voice_call_id' => $call->id, 'phone_e164' => '+12025559999', 'user_id' => 999, 'role' => 'admin']);
        $this->assertSame('trusted_voice_context_required', $result['error']);
        $this->invoke($call, ['request_otp' => true, 'phone_e164' => '+12025559999'])->assertUnprocessable();
        $this->assertCount(0, FakeSmsProvider::sent());
    }

    public function test_legacy_bridge_uses_server_model_and_does_not_store_plaintext_submitted_code(): void
    {
        $call = $this->callFor();
        $call->update(['provider' => 'vapi_telnyx', 'vapi_call_id' => 'vapi-known']);
        $bridge = app(VoiceToolBridge::class);
        $sent = $bridge->handle($call, 'verify_caller', ['request_otp' => true, 'phone_e164' => '+12025559999'], 'request');
        $this->assertTrue($sent['success']);
        $this->assertSame($call->from_phone, FakeSmsProvider::sent()[0]['to']);
        $code = $this->sentCode();
        $this->assertTrue($bridge->handle($call, 'verify_caller', ['otp_code' => $code], 'verify')['success']);
        $audit = VoiceCallToolInvocation::where('voice_call_id', $call->id)->get()->toJson();
        $this->assertStringNotContainsString($code, $audit);
        $this->assertSame('tool_call_conflict', $bridge->handle($call, 'verify_caller', ['otp_code' => '000000'], 'verify')['error']);
        $call->update(['verified_at' => null]);
        $this->assertFalse($bridge->handle($call, 'verify_caller', ['otp_code' => $code], 'verify')['success']);
        $this->assertNull($call->fresh()->caller_user_id);
    }

    public function test_code_is_bound_to_one_call_and_expires_without_promoting_unknown_caller(): void
    {
        $first = $this->callFor();
        $second = $this->callFor();
        $service = app(VoiceCallerVerificationService::class);
        $this->assertTrue($service->verify($first, ['request_otp' => true])['ok']);
        $code = $this->sentCode();
        $this->assertFalse($service->verify($second, ['otp_code' => $code])['ok']);
        $this->assertNull($second->fresh()->verified_at);
        $this->travel(11)->minutes();
        $this->assertFalse($service->verify($first, ['otp_code' => $code])['ok']);
        $this->assertNull($first->fresh()->verified_at);
        $this->assertNull($first->fresh()->caller_user_id);
    }

    public function test_role_identity_and_account_status_changes_invalidate_pending_codes(): void
    {
        foreach (['role', 'phone', 'locked', 'deleted', 'call_user'] as $index => $change) {
            $phone = '+1202555011'.$index;
            $user = User::factory()->create(['role' => 'client', 'phone' => $phone]);
            $call = $this->callFor($user, $phone);
            $service = app(VoiceCallerVerificationService::class);
            $this->assertTrue($service->verify($call, ['request_otp' => true])['ok']);
            $code = $this->sentCode();
            match ($change) {
                'role' => $user->update(['role' => 'admin']),
                'phone' => $user->update(['phone' => '+12025559999', 'phonenumber' => '+12025559999']),
                'locked' => $user->update(['locked_at' => now()]),
                'deleted' => $user->delete(),
                'call_user' => $call->update(['caller_user_id' => null]),
            };
            $this->assertFalse($service->verify($call, ['otp_code' => $code])['ok'], $change);
            $this->assertNull($call->fresh()->verified_at, $change);
        }
    }

    public function test_resends_and_guesses_are_bounded_across_calls_and_resend_does_not_reset_guesses(): void
    {
        $call = $this->callFor();
        $other = $this->callFor();
        $service = app(VoiceCallerVerificationService::class);
        $this->assertTrue($service->verify($call, ['request_otp' => true])['ok']);
        $this->assertSame('verification_rate_limited', $service->verify($other, ['request_otp' => true])['error']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->assertFalse($service->verify($call, ['otp_code' => '000000'])['ok']);
        }
        $this->travel(61)->seconds();
        $this->assertTrue($service->verify($call, ['request_otp' => true])['ok']);
        $this->assertFalse($service->verify($call, ['otp_code' => '000000'])['ok']);
        $this->assertSame('verification_rate_limited', $service->verify($call, ['otp_code' => $this->sentCode()])['error']);
        $this->travel(61)->seconds();
        $this->assertSame('verification_rate_limited', $service->verify($other, ['request_otp' => true])['error']);
        $this->assertCount(2, FakeSmsProvider::sent());
        $this->assertNull($call->fresh()->verified_at);
    }

    public function test_send_limit_still_applies_after_cooldowns(): void
    {
        $call = $this->callFor();
        $service = app(VoiceCallerVerificationService::class);
        for ($send = 0; $send < 3; $send++) {
            $this->assertTrue($service->verify($call, ['request_otp' => true])['ok']);
            $this->travel(61)->seconds();
        }
        $this->assertSame('verification_rate_limited', $service->verify($call, ['request_otp' => true])['error']);
        $this->assertCount(3, FakeSmsProvider::sent());
    }

    public function test_closed_calls_and_ineligible_accounts_cannot_replay_private_telnyx_results(): void
    {
        foreach (['canceled', 'busy', 'no_answer', 'no-answer', 'ended', 'ended_at', 'locked', 'deleted'] as $mode) {
            $user = User::factory()->create(['role' => 'client', 'phone' => '+12025550100']);
            $call = $this->callFor($user);
            $call->update(['verified_at' => now()]);
            match ($mode) {
                'ended_at' => $call->update(['ended_at' => now()]),
                'locked' => $user->update(['locked_at' => now()]),
                'deleted' => $user->delete(),
                default => $call->update(['status' => $mode]),
            };
            $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
                ->postJson('/api/telnyx-ai/tools/get_payment_status', ['shoot_id' => 123])
                ->assertForbidden()->assertJsonPath('error', in_array($mode, ['locked', 'deleted']) ? 'unverified_caller' : 'trusted_call_not_found');
        }
        $this->assertCount(0, FakeSmsProvider::sent());
    }

    public function test_disabled_previously_verified_admin_still_gets_public_help_without_internal_guides(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'phone' => '+12025550100', 'locked_at' => now()]);
        $call = $this->callFor($user);
        $call->update(['verified_at' => now()]);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'how to download shoot photos'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('result.scope', 'public')->assertJsonPath('result.found', true);
        $this->withHeader('X-Telnyx-Call-Control-Id', $call->call_control_id)
            ->postJson('/api/telnyx-ai/tools/search_support_knowledge', ['query' => 'admin calls transcripts'])
            ->assertOk()->assertJsonPath('result.scope', 'public')->assertJsonMissing(['id' => 'admin-call-transcript']);
        $this->assertCount(0, FakeSmsProvider::sent());
    }

    private function callFor(?User $user = null, string $phone = '+12025550100'): VoiceCall
    {
        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active',
            'from_phone' => $phone, 'to_phone' => '+12025550200', 'caller_user_id' => $user?->id,
            'call_control_id' => 'otp-'.uniqid()]);
    }

    private function invoke(VoiceCall $call, array $params, ?string $key = null)
    {
        return $this->withHeaders(['X-Telnyx-Call-Control-Id' => $call->call_control_id, 'Idempotency-Key' => $key ?? uniqid()])
            ->postJson('/api/telnyx-ai/tools/verify_caller', $params);
    }

    private function sentCode(): string
    {
        $sent = FakeSmsProvider::sent();
        preg_match('/\b(\d{6})\b/', end($sent)['text'], $matches);

        return $matches[1];
    }
}
