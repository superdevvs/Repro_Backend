<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallToolInvocation;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\Voice\VoiceToolBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyVoiceToolBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_known_caller_and_in_memory_verification_cannot_access_private_records_or_start_actions(): void
    {
        $call = $this->makeCall(false);
        $call->verified_at = now(); // A caller-supplied/stale object is not authority.
        $this->mock(ToolDispatcher::class)->shouldNotReceive('dispatch');
        foreach (['get_payment_status', 'get_shoot_details', 'list_shoots', 'book_shoot'] as $tool) {
            $result = app(VoiceToolBridge::class)->handle($call, $tool, ['shoot_id' => 123], 'private-'.$tool);
            $this->assertFalse($result['success']);
            $this->assertSame('unverified_caller', $result['error']);
            $this->assertArrayNotHasKey('requires_confirmation', $result);
        }
        $this->assertNull($call->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_reused_provider_tool_id_cannot_replay_another_calls_private_output(): void
    {
        $first = $this->makeCall(true);
        $second = $this->makeCall(true);
        $this->mock(ToolDispatcher::class)->shouldReceive('dispatch')->twice()
            ->andReturnUsing(fn ($tool, $params, $context) => ['account_reference' => 'private-'.$context['user_id']]);
        $bridge = app(VoiceToolBridge::class);
        $one = $bridge->handle($first, 'get_payment_status', ['shoot_id' => 123], 'reused-provider-id');
        $two = $bridge->handle($second, 'get_payment_status', ['shoot_id' => 123], 'reused-provider-id');
        $this->assertSame('private-'.$first->caller_user_id, $one['result']['account_reference']);
        $this->assertSame('private-'.$second->caller_user_id, $two['result']['account_reference']);
        $this->assertNotSame($one['tool_invocation_id'], $two['tool_invocation_id']);
        $this->assertDatabaseCount('voice_call_tool_invocations', 2);
    }

    public function test_revoked_verification_and_ended_call_block_cached_private_response(): void
    {
        $call = $this->makeCall(true);
        $this->mock(ToolDispatcher::class)->shouldReceive('dispatch')->once()->andReturn(['payment' => 'private-value']);
        $bridge = app(VoiceToolBridge::class);
        $first = $bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'same-id');
        $call->update(['verified_at' => null]);
        $this->assertSame('unverified_caller', $bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'same-id')['error']);
        $this->assertSame($first, VoiceCallToolInvocation::first()->output_payload);
        $call->update(['verified_at' => now(), 'ended_at' => now(), 'status' => 'completed']);
        $this->assertSame('trusted_call_not_found', $bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'same-id')['error']);
    }

    public function test_private_read_retries_recheck_authorization_instead_of_replaying_old_data(): void
    {
        $call = $this->makeCall(true);
        $this->mock(ToolDispatcher::class)->shouldReceive('dispatch')->twice()
            ->andReturn(['payment' => 'private-value'], ['error' => 'forbidden_resource']);
        $bridge = app(VoiceToolBridge::class);
        $this->assertTrue($bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'read-id')['success']);
        $call->callerUser->update(['role' => 'photographer']);
        $retry = $bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'read-id');
        $this->assertFalse($retry['success']);
        $this->assertSame('forbidden_resource', $retry['result']['error']);
        $this->assertArrayNotHasKey('payment', $retry['result']);
        $this->assertDatabaseCount('voice_call_tool_invocations', 1);
    }

    public function test_tool_id_collision_cannot_replay_a_different_tool_or_arguments(): void
    {
        $call = $this->makeCall(false);
        $this->mock(ToolDispatcher::class)->shouldNotReceive('dispatch');
        $bridge = app(VoiceToolBridge::class);
        $this->assertTrue($bridge->handle($call, 'get_pricing', [], 'collision')['success']);
        $this->assertSame('tool_call_conflict', $bridge->handle($call, 'verify_caller', [], 'collision')['error']);
        $this->assertSame('tool_call_conflict', $bridge->handle($call, 'get_pricing', ['different' => true], 'collision')['error']);
        $this->assertDatabaseCount('voice_call_tool_invocations', 1);
    }

    public function test_public_tool_exemption_stays_unverified_and_verification_retry_does_not_send_twice(): void
    {
        $call = $this->makeCall(false);
        $dispatcher = $this->mock(ToolDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->withArgs(fn ($tool, $params, $context) => $tool === 'search_support_knowledge'
            && $context['verified'] === false && $context['user_id'] === null)->andReturn(['scope' => 'public', 'found' => true]);
        $dispatcher->shouldReceive('dispatch')->once()->withArgs(fn ($tool, $params, $context) => $tool === 'verify_caller'
            && $context['verified'] === false && $context['phone_e164'] === $call->from_phone)->andReturn(['otp_sent' => true]);
        $bridge = app(VoiceToolBridge::class);
        $this->assertSame('public', $bridge->handle($call, 'search_support_knowledge', ['query' => 'How do I download photos?'], 'help')['result']['scope']);
        $one = $bridge->handle($call, 'verify_caller', ['request_otp' => true], 'verification');
        $two = $bridge->handle($call, 'verify_caller', ['request_otp' => true], 'verification');
        $this->assertSame($one, $two);
        $this->assertNull($call->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_disabled_account_cannot_dispatch_private_tool_and_nested_failure_is_not_success(): void
    {
        $call = $this->makeCall(true);
        $this->mock(ToolDispatcher::class)->shouldReceive('dispatch')->once()->andReturn(['success' => false]);
        $bridge = app(VoiceToolBridge::class);
        $this->assertFalse($bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'first')['success']);
        $call->callerUser->update(['locked_at' => now()]);
        $this->assertSame('unverified_caller', $bridge->handle($call, 'get_payment_status', ['shoot_id' => 123], 'first')['error']);
        $this->assertTrue($bridge->handle($call, 'get_pricing', [], 'public')['success']);
    }

    private function makeCall(bool $verified): VoiceCall
    {
        $user = User::factory()->create(['role' => 'client']);

        return VoiceCall::create(['provider' => 'vapi_telnyx', 'vapi_call_id' => 'legacy-'.uniqid(), 'direction' => 'INBOUND',
            'status' => 'ai_active', 'from_phone' => '+12025550100', 'to_phone' => '+12025550200',
            'caller_user_id' => $user->id, 'verified_at' => $verified ? now() : null]);
    }
}
