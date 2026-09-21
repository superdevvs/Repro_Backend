<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_transfer_is_idempotent_and_stays_pending_until_the_provider_confirms(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        app(VoiceSettingsService::class)->update(['support_handoff_number' => '+12025550199']);
        $call = VoiceCall::query()->create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active', 'call_control_id' => 'test-control-id']);
        $this->mock(VoiceRoutingService::class)->shouldReceive('transferToStaff')->once()->andReturnUsing(function (VoiceCall $value) {
            $value->forceFill(['status' => 'human_handoff', 'metadata' => ['transfer_requested_at' => now()->toIso8601String()]])->save();

            return $value->fresh();
        });
        foreach (range(1, 2) as $attempt) {
            $this->postJson("/api/voice/calls/{$call->id}/transfer", [])
                ->assertOk()->assertJsonPath('status', 'human_handoff');
        }
        $this->assertNull($call->fresh()->ended_at);
        $this->assertNull(data_get($call->fresh()->metadata, 'transferred_at'));
    }

    public function test_an_ended_or_unconnected_call_cannot_send_a_transfer_command(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        app(VoiceSettingsService::class)->update(['support_handoff_number' => '+12025550199']);
        $this->mock(VoiceRoutingService::class)->shouldNotReceive('transferToStaff');
        $call = VoiceCall::query()->create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'dialing']);
        $this->postJson("/api/voice/calls/{$call->id}/transfer", [])->assertStatus(409);
        $call->forceFill(['status' => 'completed', 'ended_at' => now(), 'call_control_id' => 'ended-control'])->save();
        $this->postJson("/api/voice/calls/{$call->id}/transfer", [])->assertStatus(409);
    }

    public function test_transfer_requires_a_configured_staff_destination(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        app(VoiceSettingsService::class)->update(['support_handoff_number' => null]);
        $this->mock(VoiceRoutingService::class)->shouldNotReceive('transferToStaff');
        $call = VoiceCall::query()->create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active', 'call_control_id' => 'test-control']);
        $this->postJson("/api/voice/calls/{$call->id}/transfer", [])->assertStatus(422);
    }
}
