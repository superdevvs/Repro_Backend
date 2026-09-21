<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\ScheduledVoiceCall;
use App\Models\Shoot;
use App\Models\ToolBridgeInvocation;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Models\VoiceWrapUpOperation;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class VoiceCallWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_search_matches_contact_name_and_property(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contact = Contact::query()->create(['name' => 'Alex Morgan', 'phone' => '+12025550124', 'type' => 'client']);
        $shoot = Shoot::factory()->create(['address' => '124 Cedar Lane']);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550124',
            'to_phone' => '+12025550100',
            'caller_contact_id' => $contact->id,
            'related_shoot_id' => $shoot->id,
            'summary' => 'Gate code no longer works',
            'needs_follow_up' => true,
        ]);

        VoiceCall::query()->create([
            'direction' => 'OUTBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550999',
            'to_phone' => '+12025550100',
            'summary' => 'Unrelated booking',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/calls?search=Cedar')
            ->assertOk()
            ->assertJsonPath('data.0.related_shoot.address', '124 Cedar Lane')
            ->assertJsonPath('total', 1);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/calls?filter=needs_attention')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_wrap_up_saves_recap_and_creates_internal_task_without_scheduling_a_call(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550124',
            'to_phone' => '+12025550100',
            'ended_at' => now(),
            'duration_seconds' => 252,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
                'recap_title' => 'Access issue identified. Follow-up agreed.',
                'recap_body' => 'Alex’s gate code no longer works.',
                'outcome' => 'Follow-up required',
                'create_task' => true,
                'task_title' => 'Confirm the new gate code',
                'task_due_at' => now()->addHours(3)->toIso8601String(),
                'attach_shoot' => false,
                'send_sms' => false,
                'sms_body' => 'Hi Alex — draft only.',
            ])
            ->assertOk()
            ->assertJsonPath('summary', 'Alex’s gate code no longer works.')
            ->assertJsonPath('metadata.wrap_up.outcome', 'Follow-up required')
            ->assertJsonPath('metadata.wrap_up.sms_sent', false)
            ->assertJsonPath('metadata.wrap_up.task.title', 'Confirm the new gate code');

        $this->assertDatabaseHas('voice_follow_up_tasks', [
            'voice_call_id' => $call->id,
            'assigned_to_user_id' => $admin->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        $this->assertNull($call->fresh()->summary_generated_at);
        $this->assertSame(0, \App\Models\Message::query()->count());
    }

    public function test_wrap_up_requires_a_phone_number_before_sending_sms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => null,
            'to_phone' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
                'send_sms' => true,
                'sms_body' => 'Hi Alex',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sms_body');
    }

    public function test_private_note_is_appended_to_call_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Jamie Stone']);
        $call = VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550124',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/voice/calls/{$call->id}/note", ['body' => 'Caller prefers text over voicemail.'])
            ->assertOk()
            ->assertJsonPath('metadata.notes.0.body', 'Caller prefers text over voicemail.')
            ->assertJsonPath('metadata.notes.0.user_name', 'Jamie Stone');
    }

    public function test_insights_use_real_call_counts_and_omit_empty_deltas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550111',
            'handled_by' => 'human',
            'intent' => 'new_booking',
            'started_at' => now()->subMinutes(5),
            'answered_at' => now()->subMinutes(4),
            'ended_at' => now(),
        ]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550112',
            'handled_by' => 'ai',
            'intent' => 'new_booking',
            'started_at' => now()->subMinutes(8),
            'answered_at' => now()->subMinutes(8),
            'ended_at' => now(),
        ]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550113',
            'ended_at' => now(),
            'answered_at' => null,
            'scheduled_voice_call_id' => ScheduledVoiceCall::query()->create([
                'status' => 'scheduled',
                'target_phone' => '+12025550113',
                'reason' => 'missed_call_callback',
                'scheduled_at' => now(),
                'next_attempt_at' => now(),
            ])->id,
            'callback_status' => 'scheduled',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/calls/insights?range=7d')
            ->assertOk()
            ->assertJsonPath('inbound_total', 3)
            ->assertJsonPath('answered_rate', 66.7)
            ->assertJsonPath('bookings_from_calls', 0)
            ->assertJsonPath('missed_recovered_rate', 0)
            ->assertJsonPath('median_answer_seconds', 30)
            ->assertJsonPath('answered_rate_delta', null)
            ->assertJsonPath('intents.0.key', 'new_booking');
    }

    public function test_live_filter_returns_in_progress_calls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'in_progress',
            'from_phone' => '+12025550124',
            'answered_at' => now(),
        ]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550999',
            'ended_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/calls?filter=live')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'in_progress');
    }

    public function test_invalid_sms_prevents_task_and_recap_side_effects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['summary' => 'Original summary']);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'create_task' => true, 'task_title' => 'Call the customer', 'send_sms' => true,
            'sms_body' => '  ', 'recap_body' => 'Changed summary',
        ])->assertUnprocessable()->assertJsonValidationErrors('sms_body');
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
        $this->assertDatabaseCount('voice_wrap_up_operations', 0);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        $this->assertSame('Original summary', $call->fresh()->summary);
    }

    public function test_operator_can_complete_and_reopen_existing_task_without_changing_due_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['status' => 'in_progress', 'disposition' => 'transfer_requested', 'summary' => 'Keep recap']);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'create_task' => true, 'task_title' => 'Confirm gate code', 'task_due_at' => now()->addDay()->toIso8601String(),
            'idempotency_key' => 'create-task',
        ])->assertOk()->assertJsonPath('metadata.wrap_up.task.status', 'open')->assertJsonPath('needs_follow_up', true);
        $task = VoiceFollowUpTask::query()->sole();
        foreach (['completed', 'open'] as $status) {
            $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
                'create_task' => false, 'task_status' => $status, 'idempotency_key' => $status,
            ])->assertOk()->assertJsonPath('metadata.wrap_up.task.id', $task->id)
                ->assertJsonPath('metadata.wrap_up.task.status', $status)
                ->assertJsonPath('needs_follow_up', $status === 'open');
            $this->assertEquals($task->due_at, $task->fresh()->due_at);
        }
        $this->assertSame('transfer_requested', $call->fresh()->disposition);
        $this->assertSame('Keep recap', $call->fresh()->summary);
        $this->assertSame('in_progress', $call->fresh()->status);
        $this->assertDatabaseCount('voice_follow_up_tasks', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }

    public function test_task_status_without_opt_in_does_not_create_a_task_and_invalid_status_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'create_task' => false, 'task_status' => 'open',
        ])->assertOk()->assertJsonPath('metadata.wrap_up.task', null);
        $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'create_task' => true, 'task_status' => 'invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('task_status');
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
    }

    public function test_outbound_sms_targets_customer_and_replayed_submission_sends_only_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['direction' => 'OUTBOUND', 'from_phone' => '+12025550100', 'to_phone' => '+12025550124']);
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendSms')->once()->withArgs(fn ($payload) => $payload['to'] === '+12025550124')
                ->andReturnUsing(fn ($payload) => $this->message($payload, 'SENT'));
        });
        $payload = ['create_task' => true, 'task_title' => 'Confirm reply', 'send_sms' => true,
            'sms_body' => 'Hello', 'idempotency_key' => 'customer-send'];
        $this->actingAs($admin, 'sanctum');
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload)->assertOk()
                ->assertJsonPath('wrap_up_result.sms_status', 'sent')
                ->assertJsonPath('wrap_up_result.sms_sent', true)->assertJsonPath('needs_follow_up', true);
        }
        $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", array_merge($payload, ['sms_body' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('voice_follow_up_tasks', 1);
        $this->assertDatabaseCount('voice_wrap_up_operations', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }

    public function test_blocked_sms_keeps_saved_recap_but_does_not_claim_sent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendSms')->once()->andReturnUsing(fn ($payload) => $this->message($payload, 'BLOCKED'));
        });
        $payload = ['recap_body' => 'Saved even when blocked', 'send_sms' => true, 'sms_body' => 'Hello'];
        $this->actingAs($admin, 'sanctum');
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload)->assertOk()
                ->assertJsonPath('summary', 'Saved even when blocked')->assertJsonPath('wrap_up_result.sms_status', 'blocked')
                ->assertJsonPath('wrap_up_result.sms_sent', false);
        }
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_ambiguous_send_never_retries_transport_and_can_reconcile_later_delivery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendSms')->once()->andReturnUsing(function ($payload): void {
                $this->message($payload, 'FAILED');
                throw new \RuntimeException('Private provider details');
            });
        });
        $payload = ['send_sms' => true, 'sms_body' => 'Hello', 'idempotency_key' => 'ambiguous'];
        $this->actingAs($admin, 'sanctum');
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload)->assertOk()
                ->assertJsonPath('wrap_up_result.sms_status', 'uncertain')->assertJsonPath('wrap_up_result.sms_sent', false)
                ->assertDontSee('Private provider details');
        }
        Message::query()->sole()->update(['status' => 'DELIVERED', 'sent_at' => now()]);
        $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload)->assertOk()->assertJsonPath('wrap_up_result.sms_sent', true);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_crash_after_send_claim_does_not_send_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $payload = ['send_sms' => true, 'sms_body' => 'Hello'];
        ksort($payload);
        VoiceWrapUpOperation::query()->create([
            'voice_call_id' => $call->id, 'idempotency_key' => 'claimed', 'sms_status' => 'sending',
            'request_hash' => hash('sha256', json_encode(['actor' => $admin->id, 'data' => $payload], JSON_THROW_ON_ERROR)),
            'request_payload' => $payload,
        ]);
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendSms'));
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload, ['Idempotency-Key' => 'claimed'])
            ->assertOk()->assertJsonPath('wrap_up_result.sms_status', 'uncertain');
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_send_failure_before_transport_is_saved_without_leaking_error_and_does_not_retry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendSms')->once()->andThrow(new \RuntimeException('Private configuration value'));
        });
        $payload = ['send_sms' => true, 'sms_body' => 'Hello', 'recap_body' => 'Keep my recap', 'idempotency_key' => 'failed'];
        $this->actingAs($admin, 'sanctum');
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload)->assertOk()
                ->assertJsonPath('summary', 'Keep my recap')->assertJsonPath('wrap_up_result.sms_status', 'failed')
                ->assertJsonPath('wrap_up_result.sms_sent', false)->assertDontSee('Private configuration value');
        }
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_recovering_pending_operation_uses_its_original_recipient(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['from_phone' => '+12025550999']);
        $payload = ['send_sms' => true, 'sms_body' => 'Hello'];
        ksort($payload);
        VoiceWrapUpOperation::query()->create([
            'voice_call_id' => $call->id, 'idempotency_key' => 'pending-recipient', 'sms_status' => 'pending',
            'request_hash' => hash('sha256', json_encode(['actor' => $admin->id, 'data' => $payload], JSON_THROW_ON_ERROR)),
            'request_payload' => array_merge($payload, ['sms_to' => '+12025550124']),
        ]);
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendSms')->once()->withArgs(fn ($payload) => $payload['to'] === '+12025550124')
                ->andReturnUsing(fn ($payload) => $this->message($payload, 'SENT'));
        });
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", $payload, ['Idempotency-Key' => 'pending-recipient'])
            ->assertOk()->assertJsonPath('wrap_up_result.sms_sent', true);
    }

    public function test_missing_outbound_recipient_never_falls_back_to_our_line(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['direction' => 'OUTBOUND', 'from_phone' => '+12025550100', 'to_phone' => null]);
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendSms'));
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'send_sms' => true, 'sms_body' => 'Hello', 'create_task' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('sms_body');
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
        $this->assertDatabaseCount('voice_wrap_up_operations', 0);
    }

    public function test_sms_finishing_after_newer_recap_does_not_overwrite_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $this->mock(MessagingService::class, function (MockInterface $mock) use ($call): void {
            $mock->shouldReceive('sendSms')->once()->andReturnUsing(function ($payload) use ($call): Message {
                $metadata = $call->fresh()->metadata;
                $metadata['wrap_up'] = ['recap_body' => 'A newer recap', 'operation_id' => 999];
                $metadata['notes'] = [['body' => 'Concurrent note']];
                $call->forceFill(['summary' => 'A newer recap', 'metadata' => $metadata])->save();

                return $this->message($payload, 'SENT');
            });
        });
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'send_sms' => true, 'sms_body' => 'Hello', 'recap_body' => 'Earlier recap',
        ])->assertOk()->assertJsonPath('summary', 'A newer recap')
            ->assertJsonPath('metadata.wrap_up.recap_body', 'A newer recap')
            ->assertJsonPath('metadata.notes.0.body', 'Concurrent note')->assertJsonPath('wrap_up_result.sms_sent', true);
    }

    public function test_sms_permission_is_required_before_any_wrap_up_side_effect(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['allow' => [], 'deny' => ['messaging-sms-view']]]);
        $call = $this->makeCall();
        $this->mock(MessagingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendSms'));
        $this->actingAs($admin, 'sanctum')->patchJson("/api/voice/calls/{$call->id}/wrap-up", [
            'send_sms' => true, 'sms_body' => 'Hello', 'create_task' => true,
        ])->assertForbidden();
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
        $this->assertDatabaseCount('voice_wrap_up_operations', 0);
    }

    public function test_rejected_hangup_is_explicit_and_leaves_call_active(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake(['*/calls/live-call/actions/hangup' => Http::response(['errors' => [['detail' => 'Private provider detail']]], 503)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['status' => 'active', 'call_control_id' => 'live-call', 'answered_at' => now()]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/voice/calls/{$call->id}/hangup")
            ->assertStatus(502)->assertJsonPath('error', 'hangup_failed')->assertDontSee('Private provider detail');
        $this->assertSame('active', $call->fresh()->status);
        $this->assertNull($call->fresh()->ended_at);
        $this->assertNull($call->fresh()->disposition);
        Http::assertSentCount(1);
    }

    public function test_successful_hangup_records_end_and_repeated_request_does_not_contact_provider_again(): void
    {
        config(['services.telnyx.api_key' => 'test-key']);
        Http::fake(['*/calls/live-call/actions/hangup' => Http::response(['data' => ['result' => 'ok']])]);
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['status' => 'active', 'call_control_id' => 'live-call', 'answered_at' => now()]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/voice/calls/{$call->id}/hangup")
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('disposition', 'hung_up_by_agent');
        $endedAt = $call->fresh()->ended_at;
        $this->assertNotNull($endedAt);
        $this->travel(1)->minute();
        $this->postJson("/api/voice/calls/{$call->id}/hangup")->assertOk()->assertJsonPath('status', 'completed');
        $this->assertEquals($endedAt, $call->fresh()->ended_at);
        Http::assertSentCount(1);
    }

    public function test_hangup_of_already_ended_call_is_idempotent_without_provider_configuration(): void
    {
        config(['services.telnyx.api_key' => null]);
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall(['status' => 'failed', 'ended_at' => now()->subMinute()]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/voice/calls/{$call->id}/hangup")
            ->assertOk()->assertJsonPath('status', 'failed');
        Http::assertNothingSent();
    }

    public function test_insights_require_successful_audits_answered_callbacks_and_transfer_events(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->makeCall(['started_at' => now()->subSeconds(20), 'answered_at' => now()->subSeconds(10),
            'disposition' => 'transferred', 'call_control_id' => 'first']);
        $second = $this->makeCall(['started_at' => now()->subSeconds(40), 'answered_at' => now()->subSeconds(10),
            'disposition' => 'transferred', 'call_control_id' => 'second']);
        $missed = $this->makeCall(['ended_at' => now(), 'disposition' => 'handoff_to_staff']);
        $callback = $this->makeCall(['direction' => 'OUTBOUND', 'answered_at' => now(), 'started_at' => now()->subSeconds(500)]);
        ScheduledVoiceCall::query()->create(['status' => 'completed', 'target_phone' => $missed->from_phone,
            'scheduled_at' => now(), 'original_voice_call_id' => $missed->id, 'result_voice_call_id' => $callback->id]);
        $first->events()->create(['provider' => 'telnyx', 'event_type' => 'call.transferred',
            'received_at' => now(), 'processed_at' => now(), 'idempotency_key' => 'transfer-first']);
        $second->events()->create(['provider' => 'telnyx', 'event_type' => 'call.transferred',
            'received_at' => now(), 'processed_at' => null, 'idempotency_key' => 'transfer-second']);
        $first->toolInvocations()->create(['tool_name' => 'book_shoot', 'status' => 'executed',
            'output_payload' => ['success' => true, 'result' => ['success' => true, 'shoot_id' => 123]]]);
        foreach (['booking-first' => $first, 'booking-second' => $second] as $key => $call) {
            ToolBridgeInvocation::query()->create(['tool' => 'book_shoot', 'channel' => 'VOICE', 'status' => 'ok',
                'idempotency_key' => $key, 'call_control_id' => $call->call_control_id,
                'request_json' => ['context' => ['voice_call_id' => $call->id]],
                'response_json' => ['ok' => true, 'result' => ['success' => true, 'shoot_id' => 123]]]);
        }
        $missed->toolInvocations()->create(['tool_name' => 'book_shoot', 'status' => 'executed',
            'output_payload' => ['success' => true, 'result' => ['success' => false, 'shoot_id' => 555]]]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/voice/calls/insights?range=7d')->assertOk()
            ->assertJsonPath('inbound_total', 3)->assertJsonPath('answered_rate', 66.7)
            ->assertJsonPath('median_answer_seconds', 20)->assertJsonPath('bookings_from_calls', 2)
            ->assertJsonPath('missed_recovered_rate', 100)->assertJsonPath('handoff_connected_rate', 33.3)
            ->assertJsonPath('handoffs_needing_callback', 0);
    }

    private function makeCall(array $attributes = []): VoiceCall
    {
        return VoiceCall::query()->create(array_merge([
            'direction' => 'INBOUND', 'status' => 'completed', 'from_phone' => '+12025550124', 'to_phone' => '+12025550100',
        ], $attributes));
    }

    private function message(array $payload, string $status): Message
    {
        return Message::query()->create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'TELNYX', 'status' => $status,
            'from_address' => '+12025550100', 'to_address' => $payload['to'], 'body_text' => $payload['body_text'],
            'metadata' => $payload['metadata'], 'sent_at' => $status === 'SENT' ? now() : null,
        ]);
    }
}
