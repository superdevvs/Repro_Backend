<?php

namespace Tests\Feature;

use App\Jobs\ScheduledVoiceCallJob;
use App\Models\ScheduledVoiceCall;
use App\Models\Setting;
use App\Models\SmsNumber;
use App\Models\TelnyxWebhookEvent;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $this->configureDirectTelnyx('+12025550123');
        Http::fake([
            'https://api.telnyx.com/v2/calls' => Http::response(['data' => [
                'call_control_id' => 'telnyx-call-123',
                'result' => 'ok',
            ]]),
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
            ->assertJsonPath('provider', 'telnyx')
            ->assertJsonPath('call_control_id', 'telnyx-call-123');

        $this->assertDatabaseHas('voice_calls', [
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'to_phone' => '+12025550123',
            'call_control_id' => 'telnyx-call-123',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telnyx.com/v2/calls'
            && filled($request['command_id'] ?? null)
            && ! isset($request['assistant_id'])
            && ! isset($request['dynamic_variables']));
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
        $this->configureDirectTelnyx('+12025550123');
        Http::fake([
            'https://api.telnyx.com/v2/calls' => Http::response(['data' => [
                'call_control_id' => 'telnyx-scheduled-1',
                'result' => 'ok',
            ]]),
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
            app(\App\Services\Voice\VoiceCallService::class),
            app(\App\Services\TelnyxAi\ScheduledVoiceCallService::class),
        );

        $scheduled->refresh();
        $this->assertSame('completed', $scheduled->status);
        $this->assertNotNull($scheduled->result_voice_call_id);
        $this->assertDatabaseHas('voice_calls', [
            'id' => $scheduled->result_voice_call_id,
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'to_phone' => '+12025550123',
            'call_control_id' => 'telnyx-scheduled-1',
        ]);
    }

    public function test_outbound_answer_starts_assistant_once_with_current_telnyx_contract(): void
    {
        config(['services.telnyx.public_key' => null, 'services.telnyx.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok', 'conversation_id' => 'conv-new']])]);
        $call = VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'status' => 'dialing',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550123',
            'assistant_id' => 'assistant-1',
            'call_control_id' => 'call-answer-1',
            'client_state' => base64_encode('answer-test'),
            'metadata' => ['dynamic_variables' => ['reason' => 'test']],
        ]);

        foreach (['answered-1', 'answered-2'] as $eventId) {
            $this->postJson('/api/webhooks/telnyx/voice', [
                'data' => [
                    'id' => $eventId,
                    'event_type' => 'call.answered',
                    'payload' => ['call_control_id' => 'call-answer-1'],
                ],
            ])->assertOk();
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/calls/call-answer-1/actions/ai_assistant_start')
            && ($request['assistant']['id'] ?? null) === 'assistant-1'
            && ($request['send_message_history_updates'] ?? null) === true
            && filled($request['command_id'] ?? null));
        $this->assertSame('conv-new', $call->fresh()->telnyx_conversation_id);
        $this->assertNotNull($call->fresh()->answered_at);
    }

    public function test_answered_event_retries_after_a_transient_assistant_start_failure(): void
    {
        config(['services.telnyx.public_key' => null, 'services.telnyx.api_key' => 'test-key']);
        Http::fakeSequence()
            ->push(['errors' => [['detail' => 'temporary failure']]], 500)
            ->push(['data' => ['result' => 'ok', 'conversation_id' => 'conv-retried']], 200);
        $call = VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'status' => 'dialing',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550123',
            'assistant_id' => 'assistant-1',
            'call_control_id' => 'call-answer-retry',
            'client_state' => base64_encode('answer-retry-test'),
        ]);
        $payload = [
            'data' => [
                'id' => 'answered-retry-1',
                'event_type' => 'call.answered',
                'payload' => ['call_control_id' => 'call-answer-retry'],
            ],
        ];

        $this->postJson('/api/webhooks/telnyx/voice', $payload)->assertServerError();
        $this->assertDatabaseHas('telnyx_webhook_events', [
            'telnyx_event_id' => 'answered-retry-1',
            'processed_at' => null,
        ]);

        $this->postJson('/api/webhooks/telnyx/voice', $payload)->assertOk();

        Http::assertSentCount(2);
        $this->assertSame('conv-retried', $call->fresh()->telnyx_conversation_id);
        $this->assertDatabaseMissing('telnyx_webhook_events', [
            'telnyx_event_id' => 'answered-retry-1',
            'processed_at' => null,
        ]);
    }

    public function test_unidentified_provider_event_never_updates_an_unrelated_call(): void
    {
        config(['services.telnyx.public_key' => null]);
        $call = VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
        ]);

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'unidentified-event-1',
                'event_type' => 'call.unknown',
                'payload' => ['reason' => 'no identifiers'],
            ],
        ])->assertOk();

        $this->assertNull($call->fresh()->provider_event_last_seen_at);
    }

    public function test_current_conversation_events_persist_transcript_summary_and_duration(): void
    {
        config(['services.telnyx.public_key' => null]);
        User::factory()->create(['role' => 'admin']);
        $call = VoiceCall::query()->create([
            'provider' => 'telnyx',
            'direction' => 'OUTBOUND',
            'status' => 'active',
            'from_phone' => '+12025550100',
            'to_phone' => '+12025550123',
            'call_control_id' => 'call-conversation-1',
            'telnyx_conversation_id' => 'conv-1',
            'started_at' => now()->subSeconds(30),
        ]);

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'conversation-initiated-1',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-conversation-1',
                    'from' => '+12025550100',
                    'to' => '+12025550123',
                    'direction' => 'outbound',
                ],
            ],
        ])->assertOk();

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'history-1',
                'event_type' => 'call.ai_assistant.message_history_updated',
                'payload' => [
                    'call_control_id' => 'call-conversation-1',
                    'message_history' => [
                        ['id' => 'message-1', 'role' => 'user', 'content' => 'I need my shoot status.'],
                        ['id' => 'message-2', 'role' => 'assistant', 'content' => 'I can help with that.'],
                    ],
                ],
            ],
        ])->assertOk();

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'conversation-ended-1',
                'event_type' => 'call.conversation.ended',
                'payload' => [
                    'call_control_id' => 'call-conversation-1',
                    'conversation_id' => 'conv-1',
                    'duration_sec' => 42,
                    'llm_model' => 'moonshotai/Kimi-K2.5',
                ],
            ],
        ])->assertOk();

        $this->postJson('/api/webhooks/telnyx/voice', [
            'data' => [
                'id' => 'conversation-insights-1',
                'event_type' => 'call.conversation_insights.generated',
                'payload' => [
                    'call_control_id' => 'call-conversation-1',
                    'results' => [['insight_id' => 'summary', 'result' => 'Caller requested shoot status.']],
                ],
            ],
        ])->assertOk();

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(42, $call->duration_seconds);
        $this->assertSame('Caller requested shoot status.', $call->summary);
        $this->assertStringContainsString('I need my shoot status.', (string) $call->transcript);
        $this->assertSame(2, $call->transcriptRows()->count());
        $this->assertSame(2, $call->aiChatSession->messages()->count());
        $this->assertSame('conv-1', $call->aiChatSession->meta['telnyx_conversation_id']);
        $this->assertSame('Caller requested shoot status.', $call->aiChatSession->meta['summary']);
        $this->assertNotEmpty($call->aiChatSession->meta['closed_at']);
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

    private function configureDirectTelnyx(string $canaryNumber): void
    {
        config([
            'services.voice.provider' => 'telnyx',
            'services.voice.canary_mode' => true,
            'services.voice.canary_numbers' => [$canaryNumber],
            'services.telnyx.api_key' => 'test-telnyx-key',
            'services.telnyx.from_number' => '+12025550100',
            'services.telnyx.voice.enabled' => true,
            'services.telnyx.voice.assistant_id' => 'assistant-direct-1',
            'services.telnyx.voice.connection_id' => 'connection-1',
            'services.telnyx.voice.webhook_url' => 'https://api.example.test/api/webhooks/telnyx/voice',
        ]);
    }
}
