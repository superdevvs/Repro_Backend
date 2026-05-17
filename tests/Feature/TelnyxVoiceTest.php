<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ScheduledVoiceCall;
use App\Models\SmsNumber;
use App\Models\TelnyxWebhookEvent;
use App\Models\User;
use App\Models\VoiceCall;
use App\Jobs\ScheduledVoiceCallJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelnyxVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_routes_require_voice_calls_permission(): void
    {
        $user = User::factory()->create(['role' => 'client']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/voice/calls')
            ->assertForbidden();
    }

    public function test_admin_can_read_and_update_voice_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/voice/settings', [
                'enabled' => true,
                'recording_enabled' => true,
                'disclosure_text' => 'Disclosure test',
                'gather_prompt' => 'Press 1 for booking',
                'support_handoff_number' => '+12025550100',
                'allow_unverified_transfer' => false,
                'callback_retry_delay_minutes' => 30,
                'callback_max_attempts' => 2,
                'quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC'],
                'automation_toggles' => ['missed_call_callback' => true, 'failed_transfer_callback' => true],
                'tool_allowlist' => ['verify_caller', 'handoff_to_staff'],
                'confirmation_gated_tools' => ['handoff_to_staff'],
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('recording_enabled', true)
            ->assertJsonPath('disclosure_text', 'Disclosure test')
            ->assertJsonPath('callback_max_attempts', 2)
            ->assertJsonPath('tool_allowlist.0', 'verify_caller');

        $this->assertDatabaseHas('settings', ['key' => 'messaging.telnyx_voice']);
    }

    public function test_admin_can_place_outbound_voice_call(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake([
            'api.telnyx.com/v2/calls' => Http::response([
                'data' => [
                    'call_control_id' => 'call-123',
                    'conversation_id' => 'conv-123',
                ],
            ]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/voice/calls/outbound', [
                'to' => '+12025550123',
                'from' => '+12025550100',
                'dynamic_variables' => ['name' => 'Test'],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'dialing')
            ->assertJsonPath('call_control_id', 'call-123');

        $this->assertDatabaseHas('voice_calls', [
            'direction' => 'OUTBOUND',
            'to_phone' => '+12025550123',
        ]);
    }

    public function test_voice_webhook_initiated_is_idempotent(): void
    {
        config(['services.telnyx.public_key' => null]);
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake();
        User::factory()->create(['role' => 'admin']);

        $payload = [
            'data' => [
                'id' => 'event-voice-1',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-abc',
                    'conversation_id' => 'conv-abc',
                    'from' => '+12025550123',
                    'to' => '+12025550100',
                ],
            ],
        ];

        $this->postJson('/api/webhooks/telnyx/voice', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->postJson('/api/webhooks/telnyx/voice', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, VoiceCall::query()->where('call_control_id', 'call-abc')->count());
        $this->assertDatabaseHas('telnyx_webhook_events', ['telnyx_event_id' => 'event-voice-1']);
        $this->assertDatabaseHas('voice_calls', [
            'call_control_id' => 'call-abc',
            'intent' => 'routing',
        ]);
    }

    public function test_gather_digit_routes_to_booking_assistant(): void
    {
        config(['services.telnyx.public_key' => null]);
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake();

        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
            'call_control_id' => 'call-menu-1',
            'assistant_id' => 'assistant-1',
        ]);

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'event-menu-1',
                'event_type' => 'call.gather.ended',
                'payload' => ['call_control_id' => 'call-menu-1', 'digits' => '1'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('voice_calls', [
            'id' => $call->id,
            'intent' => 'booking_or_reschedule',
            'menu_digit' => '1',
        ]);
    }

    public function test_zero_digit_transfer_failure_creates_callback(): void
    {
        config(['services.telnyx.public_key' => null]);
        config(['services.telnyx.api_key' => 'test-key']);
        Setting::query()->create([
            'key' => 'messaging.telnyx_voice',
            'value' => json_encode([
                'support_handoff_number' => '+12025559999',
                'automation_toggles' => ['failed_transfer_callback' => true],
                'callback_retry_delay_minutes' => 15,
            ]),
            'type' => 'json',
        ]);
        Http::fake(['*' => Http::response(['error' => 'failed'], 500)]);

        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
            'call_control_id' => 'call-menu-0',
            'assistant_id' => 'assistant-1',
        ]);

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'event-menu-0',
                'event_type' => 'call.dtmf.received',
                'payload' => ['call_control_id' => 'call-menu-0', 'digit' => '0'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('voice_calls', [
            'id' => $call->id,
            'intent' => 'human_transfer',
            'disposition' => 'callback_needed',
            'callback_status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('scheduled_voice_calls', [
            'original_voice_call_id' => $call->id,
            'reason' => 'transfer_failed',
            'target_phone' => '+12025550123',
        ]);
    }

    public function test_admin_can_manage_scheduled_voice_calls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/voice/scheduled-calls', [
                'target_phone' => '+12025550123',
                'reason' => 'manual_callback',
                'scheduled_at' => now()->toIso8601String(),
                'max_attempts' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'scheduled')
            ->json();

        $id = $created['id'];

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/scheduled-calls?status=scheduled')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/voice/scheduled-calls/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_admin_can_read_voice_health(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
            'last_telnyx_command_status' => ['action' => 'transfer', 'ok' => true],
        ]);
        TelnyxWebhookEvent::query()->create([
            'provider' => 'TELNYX',
            'channel' => 'VOICE',
            'telnyx_event_id' => 'health-event-1',
            'event_type' => 'call.initiated',
            'event_received_at' => now(),
            'processed_at' => now(),
            'related_voice_call_id' => $call->id,
        ]);
        ScheduledVoiceCall::query()->create([
            'status' => 'scheduled',
            'target_phone' => '+12025550123',
            'reason' => 'manual_callback',
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/health')
            ->assertOk()
            ->assertJsonPath('latest_webhook_event.event_type', 'call.initiated')
            ->assertJsonPath('latest_command_status.action', 'transfer')
            ->assertJsonPath('scheduler.due_scheduled_calls', 1);
    }

    public function test_dispatch_scheduled_voice_calls_command_dispatches_due_jobs(): void
    {
        Queue::fake();
        ScheduledVoiceCall::query()->create([
            'status' => 'scheduled',
            'target_phone' => '+12025550123',
            'reason' => 'manual_callback',
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
        ]);

        $this->artisan('voice:dispatch-scheduled-calls')
            ->assertExitCode(0);

        Queue::assertPushed(ScheduledVoiceCallJob::class);
    }

    public function test_scheduled_voice_call_job_places_outbound_call(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake([
            'api.telnyx.com/v2/calls' => Http::response([
                'data' => [
                    'call_control_id' => 'call-scheduled-1',
                    'conversation_id' => 'conv-scheduled-1',
                ],
            ]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $scheduled = ScheduledVoiceCall::query()->create([
            'status' => 'scheduled',
            'target_phone' => '+12025550123',
            'reason' => 'manual_callback',
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
            'created_by_user_id' => $admin->id,
        ]);

        (new ScheduledVoiceCallJob($scheduled->id))->handle(
            app(\App\Services\TelnyxAi\TelnyxVoiceCallService::class),
            app(\App\Services\TelnyxAi\ScheduledVoiceCallService::class),
        );

        $scheduled->refresh();
        $this->assertSame('completed', $scheduled->status);
        $this->assertNotNull($scheduled->result_voice_call_id);
        $this->assertDatabaseHas('voice_calls', [
            'id' => $scheduled->result_voice_call_id,
            'direction' => 'OUTBOUND',
            'to_phone' => '+12025550123',
        ]);
    }

    public function test_voice_number_flags_can_be_updated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $number = SmsNumber::query()->create([
            'provider' => 'telnyx',
            'phone_number' => '+12025550100',
            'label' => 'Main',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/voice/numbers/{$number->id}", [
                'voice_ai_enabled' => true,
                'voice_assistant_id_override' => 'assistant-1',
            ])
            ->assertOk()
            ->assertJsonPath('voice_ai_enabled', true);

        $this->assertDatabaseHas('sms_numbers', [
            'id' => $number->id,
            'voice_ai_enabled' => true,
            'voice_assistant_id_override' => 'assistant-1',
        ]);
    }
}
