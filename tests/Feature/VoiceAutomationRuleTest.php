<?php

namespace Tests\Feature;

use App\Jobs\ScheduledVoiceCallJob;
use App\Models\Invoice;
use App\Models\ScheduledVoiceCall;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceAutomationRule;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Services\Voice\VoiceAutomationService;
use App\Services\Voice\VoiceCallService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VoiceAutomationRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 19:30:00', 'UTC'));
        config(['app.timezone' => 'UTC']);
        app(VoiceSettingsService::class)->update(['quiet_hours' => ['enabled' => false]]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Call known customers back', 'trigger_type' => 'missed_call_callback', 'enabled' => true,
            'conditions' => [['field' => 'known_caller', 'operator' => 'eq', 'value' => true]],
            'delay_minutes' => 60, 'quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC'],
            'max_attempts' => 2, 'retry_delay_minutes' => 45, 'action_type' => 'ai_callback', 'action_config' => [],
        ], $overrides);
    }

    private function sourceCall(array $overrides = []): VoiceCall
    {
        return VoiceCall::query()->create(array_merge([
            'direction' => 'INBOUND', 'status' => 'missed', 'from_phone' => '+12025550124', 'to_phone' => '+12025550100',
            'caller_user_id' => User::factory()->create()->id,
        ], $overrides));
    }

    public function test_preview_checks_conditions_and_quiet_hours_without_saving_or_queuing_anything(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/voice/automation-rules/preview', [
            'rule' => $this->definition(), 'sample' => ['known_caller' => true, 'target_phone' => '+12025550124'],
        ])->assertOk()->assertJsonPath('would_run', true)->assertJsonPath('quiet_hours_adjusted', true)
            ->assertJsonPath('scheduled_at', '2026-09-22T08:00:00+00:00')->assertJsonPath('preview_only', true);
        $this->assertDatabaseCount('voice_automation_rules', 0);
        $this->assertDatabaseCount('voice_automation_runs', 0);
        $this->assertDatabaseCount('voice_follow_up_tasks', 0);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rule_validation_rejects_incompatible_fields_operators_timezone_and_task_payload(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $cases = [
            ['conditions' => [['field' => 'amount_due', 'operator' => 'gte', 'value' => 10]]],
            ['conditions' => [['field' => 'known_caller', 'operator' => 'gte', 'value' => true]]],
            ['conditions' => [['field' => 'known_caller', 'operator' => 'eq', 'value' => 'yes']]],
            ['quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'Invalid/Zone']],
            ['action_config' => ['task_title' => 'Cannot attach task fields to a call']],
            ['action_type' => 'send_sms'], ['max_attempts' => 99],
        ];
        foreach ($cases as $case) {
            $this->postJson('/api/voice/automation-rules', $this->definition($case))->assertUnprocessable();
        }
        $this->assertDatabaseCount('voice_automation_rules', 0);
    }

    public function test_preview_accepts_browser_timezone_alias_with_correct_quiet_hours_and_rejects_invalid_zones(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        foreach (['Asia/Calcutta', 'Asia/Kolkata'] as $timezone) {
            $this->postJson('/api/voice/automation-rules/preview', [
                'rule' => $this->definition(['quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => $timezone]]),
                'sample' => ['known_caller' => true, 'target_phone' => '+12025550124'],
            ])->assertOk()->assertJsonPath('preview_only', true)
                ->assertJsonPath('would_run', true)->assertJsonPath('quiet_hours_adjusted', true)
                ->assertJsonPath('scheduled_at', '2026-09-22T02:30:00+00:00');
        }
        $this->postJson('/api/voice/automation-rules/preview', [
            'rule' => $this->definition(['quiet_hours' => ['enabled' => true, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'Invalid/Zone']]),
            'sample' => ['known_caller' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('quiet_hours.timezone');
        $this->assertDatabaseCount('voice_automation_rules', 0);
        $this->assertDatabaseCount('voice_automation_runs', 0);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_duplicate_source_events_create_one_callback_with_the_custom_budget_and_delay(): void
    {
        $rule = VoiceAutomationRule::query()->create($this->definition());
        $call = $this->sourceCall(['direction' => 'OUTBOUND', 'from_phone' => '+12025550100', 'to_phone' => '+12025550124']);
        $service = app(ScheduledVoiceCallService::class);
        $first = $service->createCallbackForCall($call, 'missed_call');
        $again = $service->createCallbackForCall($call, 'missed_call');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('+12025550124', $first->target_phone);
        $this->assertEquals(2, $first->max_attempts);
        $this->assertEquals('2026-09-22 08:00:00', $first->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertEquals($rule->id, $first->metadata['automation_rule_id']);
        $this->assertEquals(45, $service->retryDelayMinutes($first));
        $this->assertDatabaseCount('voice_automation_runs', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rule_creation_requires_a_key_and_replays_without_duplicate_rules_or_callbacks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $definition = $this->definition();
        $this->postJson('/api/voice/automation-rules', $definition)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $payload = array_merge($definition, ['idempotency_key' => '11111111-1111-4111-8111-111111111111']);
        $created = $this->postJson('/api/voice/automation-rules', $payload)->assertCreated()
            ->assertJsonMissingPath('creation_key')->assertJsonMissingPath('creation_request_hash');
        $id = $created->json('id');
        $payload['quiet_hours'] = array_reverse($payload['quiet_hours'], true);
        $this->postJson('/api/voice/automation-rules', array_reverse($payload, true))->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('voice_automation_rules', 1);
        $call = $this->sourceCall();
        app(ScheduledVoiceCallService::class)->createCallbackForCall($call, 'missed_call');
        app(ScheduledVoiceCallService::class)->createCallbackForCall($call, 'missed_call');
        $this->assertDatabaseCount('voice_automation_runs', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 1);
        Http::assertNothingSent();
    }

    public function test_rule_creation_key_rejects_changed_details_and_does_not_restore_an_archived_rule(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $payload = array_merge($this->definition(), ['idempotency_key' => '22222222-2222-4222-8222-222222222222']);
        $id = $this->postJson('/api/voice/automation-rules', $payload)->assertCreated()->json('id');
        $this->postJson('/api/voice/automation-rules', array_merge($payload, ['delay_minutes' => 90]))->assertConflict();
        $this->patchJson('/api/voice/automation-rules/'.$id, ['name' => 'Updated workflow'])->assertOk();
        $this->postJson('/api/voice/automation-rules', $payload)->assertOk()->assertJsonPath('name', 'Updated workflow');
        $this->deleteJson('/api/voice/automation-rules/'.$id)->assertOk();
        $this->postJson('/api/voice/automation-rules', $payload)->assertConflict();
        $this->assertDatabaseCount('voice_automation_rules', 1);
        $this->assertSoftDeleted('voice_automation_rules', ['id' => $id, 'enabled' => false]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_disabled_and_archived_rules_never_fall_back_to_legacy_callbacks(): void
    {
        $rule = VoiceAutomationRule::query()->create($this->definition(['enabled' => false]));
        $service = app(ScheduledVoiceCallService::class);
        $this->assertNull($service->createCallbackForCall($this->sourceCall(), 'missed_call'));
        $rule->delete();
        $this->assertNull($service->createCallbackForCall($this->sourceCall(), 'missed_call'));
        $this->assertFalse($service->automationEnabled('missed_call_callback'));
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        $this->assertDatabaseCount('voice_automation_runs', 0);
    }

    public function test_unmatched_rule_records_a_skipped_run_without_legacy_dial(): void
    {
        VoiceAutomationRule::query()->create($this->definition());
        $call = $this->sourceCall(['caller_user_id' => null]);
        $this->assertNull(app(ScheduledVoiceCallService::class)->createCallbackForCall($call, 'missed_call'));
        $this->assertDatabaseHas('voice_automation_runs', ['status' => 'skipped']);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }

    public function test_internal_task_is_durable_reopenable_and_does_not_replace_manual_wrap_up_task(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        VoiceAutomationRule::query()->create($this->definition([
            'action_type' => 'internal_task', 'action_config' => ['task_title' => 'Review missed call', 'assigned_to_user_id' => $admin->id],
        ]));
        $call = $this->sourceCall();
        $manual = VoiceFollowUpTask::query()->create(['voice_call_id' => $call->id, 'title' => 'Manual task', 'due_at' => now()]);
        $service = app(ScheduledVoiceCallService::class);
        $this->assertNull($service->createCallbackForCall($call, 'missed_call'));
        $service->createCallbackForCall($call, 'missed_call');
        $task = VoiceFollowUpTask::query()->whereNotNull('automation_run_id')->firstOrFail();
        $this->assertDatabaseCount('voice_follow_up_tasks', 2);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/voice/automation-rules/tasks/'.$task->id, ['status' => 'completed'])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertTrue((bool) $call->fresh()->needs_follow_up); // Manual task still open.
        $this->assertTrue($call->fresh()->metadata['needs_follow_up']);
        $this->patchJson('/api/voice/calls/'.$call->id.'/wrap-up', ['task_status' => 'completed', 'idempotency_key' => 'manual-completed'])
            ->assertOk()->assertJsonPath('needs_follow_up', false)->assertJsonPath('metadata.needs_follow_up', false);
        $this->patchJson('/api/voice/automation-rules/tasks/'.$task->id, ['status' => 'open'])
            ->assertOk()->assertJsonPath('completed_at', null);
        $this->assertTrue($call->fresh()->metadata['needs_follow_up']);
        $this->patchJson('/api/voice/calls/'.$call->id.'/wrap-up', ['task_status' => 'completed', 'idempotency_key' => 'manual-still-completed'])
            ->assertOk()->assertJsonPath('needs_follow_up', true)->assertJsonPath('metadata.needs_follow_up', true);
        $this->patchJson('/api/voice/automation-rules/tasks/'.$manual->id, ['status' => 'completed'])->assertNotFound();
        $this->getJson('/api/voice/automation-rules/runs')->assertOk()->assertJsonPath('data.0.effective_status', 'task_open');
    }

    public function test_proactive_shoot_rule_creates_a_task_without_a_fake_call_or_customer_phone(): void
    {
        $client = User::factory()->create(['phone' => null, 'phonenumber' => null]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'scheduled_at' => now()->addDay(), 'scheduled_date' => now()->addDay()->toDateString()]);
        VoiceAutomationRule::query()->create($this->definition([
            'trigger_type' => 'shoot_reminder', 'action_type' => 'internal_task', 'action_config' => ['task_title' => 'Confirm shoot access'],
        ]));
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        $this->assertDatabaseHas('voice_follow_up_tasks', ['voice_call_id' => null, 'related_shoot_id' => $shoot->id, 'title' => 'Confirm shoot access']);
        $this->assertDatabaseCount('voice_follow_up_tasks', 1);
        $this->assertDatabaseCount('voice_calls', 0);
    }

    public function test_invoice_rule_applies_amount_and_due_conditions_and_tracks_source_invoice(): void
    {
        $client = User::factory()->create();
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'user_id' => $client->id, 'invoice_number' => 'AUTO-100', 'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
            'status' => Invoice::STATUS_SENT, 'is_sent' => true, 'due_date' => now()->subDays(3), 'total' => 120,
        ]);
        VoiceAutomationRule::query()->create($this->definition([
            'trigger_type' => 'unpaid_invoice_reminder', 'action_type' => 'internal_task',
            'action_config' => ['task_title' => 'Review overdue invoice'],
            'conditions' => [['field' => 'days_overdue', 'operator' => 'gte', 'value' => 2], ['field' => 'amount_due', 'operator' => 'gte', 'value' => 50]],
        ]));
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        $this->assertDatabaseHas('voice_follow_up_tasks', ['voice_call_id' => null, 'related_invoice_id' => $invoice->id]);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }

    public function test_disabled_rule_pauses_existing_callback_without_attempting_provider_or_using_legacy_toggle(): void
    {
        $rule = VoiceAutomationRule::query()->create($this->definition(['conditions' => [], 'delay_minutes' => 0, 'quiet_hours' => ['enabled' => false, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC']]));
        $scheduled = app(ScheduledVoiceCallService::class)->createCallbackForCall($this->sourceCall(), 'missed_call');
        $rule->update(['enabled' => false]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));
        $this->assertSame('deferred', $scheduled->fresh()->status);
        $this->assertEquals(0, $scheduled->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_archiving_cancels_pending_callbacks_and_retains_history_and_trigger_ownership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rule = VoiceAutomationRule::query()->create($this->definition());
        $scheduled = app(ScheduledVoiceCallService::class)->createCallbackForCall($this->sourceCall(), 'missed_call');
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/voice/automation-rules/'.$rule->id)->assertOk();
        $this->assertSame('cancelled', $scheduled->fresh()->status);
        $this->assertTrue(app(VoiceAutomationService::class)->ownsTrigger('missed_call_callback'));
        $this->getJson('/api/voice/automation-rules')->assertJsonPath('rules', [])->assertJsonPath('managed_triggers.0', 'missed_call_callback');
        $this->getJson('/api/voice/automation-rules/runs')->assertJsonPath('data.0.effective_status', 'cancelled');
    }

    public function test_customer_cannot_read_create_or_preview_staff_voice_automations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum');
        $this->getJson('/api/voice/automation-rules')->assertForbidden();
        $this->postJson('/api/voice/automation-rules', $this->definition())->assertForbidden();
        $this->postJson('/api/voice/automation-rules/preview', ['rule' => $this->definition(), 'sample' => []])->assertForbidden();
    }

    public function test_paid_invoice_and_cancelled_or_rescheduled_shoot_cancel_delayed_callbacks_without_attempts(): void
    {
        $client = User::factory()->create(['phone' => '+12025550124']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'due_date' => now()->subDay(), 'total' => 200, 'is_paid' => false]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'scheduled_at' => now()->addDay(), 'scheduled_date' => now()->addDay()->toDateString()]);
        foreach (['shoot_reminder', 'unpaid_invoice_reminder'] as $trigger) {
            VoiceAutomationRule::query()->create($this->definition(['trigger_type' => $trigger, 'conditions' => [], 'delay_minutes' => 0, 'quiet_hours' => ['enabled' => false, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC']]));
        }
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        $invoiceCall = ScheduledVoiceCall::query()->where('related_invoice_id', $invoice->id)->firstOrFail();
        $shootCall = ScheduledVoiceCall::query()->where('related_shoot_id', $shoot->id)->firstOrFail();
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $shoot->update(['workflow_status' => Shoot::STATUS_CANCELLED]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');
        foreach ([$invoiceCall, $shootCall] as $scheduled) {
            (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));
            $this->assertSame('cancelled', $scheduled->fresh()->status);
            $this->assertEquals(0, $scheduled->fresh()->attempts);
            $this->assertNull($scheduled->fresh()->next_attempt_at);
        }
        $rescheduledShoot = Shoot::factory()->create(['client_id' => $client->id, 'scheduled_at' => now()->addDay(), 'scheduled_date' => now()->addDay()->toDateString()]);
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        $rescheduledCall = ScheduledVoiceCall::query()->where('related_shoot_id', $rescheduledShoot->id)->firstOrFail();
        $rescheduledShoot->update(['scheduled_at' => now()->addDays(2)]);
        (new ScheduledVoiceCallJob($rescheduledCall->id))->handle($calls, app(ScheduledVoiceCallService::class));
        $this->assertSame('cancelled', $rescheduledCall->fresh()->status);
        $this->assertStringContainsString('rescheduled', $rescheduledCall->fresh()->getRawOriginal('last_error'));
        Http::assertNothingSent();
    }

    public function test_current_rule_conditions_are_rechecked_at_claim_time_using_the_saved_run_definition(): void
    {
        $rule = VoiceAutomationRule::query()->create($this->definition(['delay_minutes' => 0, 'quiet_hours' => ['enabled' => false, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC']]));
        $call = $this->sourceCall();
        $scheduled = app(ScheduledVoiceCallService::class)->createCallbackForCall($call, 'missed_call');
        $rule->update(['conditions' => []]); // Edits affect new events, not the queued snapshot.
        $call->update(['caller_user_id' => null]);
        $calls = Mockery::mock(VoiceCallService::class);
        $calls->shouldNotReceive('startOutbound');
        (new ScheduledVoiceCallJob($scheduled->id))->handle($calls, app(ScheduledVoiceCallService::class));
        $this->assertSame('cancelled', $scheduled->fresh()->status);
        $this->assertEquals(0, $scheduled->fresh()->attempts);
    }

    public function test_hangup_only_and_no_answer_events_trigger_one_internal_task_and_keep_the_call_terminal(): void
    {
        config(['services.telnyx.public_key' => null]);
        app(VoiceSettingsService::class)->update(['enabled' => false]);
        VoiceAutomationRule::query()->create($this->definition([
            'action_type' => 'internal_task', 'action_config' => ['task_title' => 'Review missed call'],
        ]));
        $call = $this->sourceCall(['status' => 'ringing', 'call_control_id' => 'custom-missed-call']);
        foreach (['call.hangup', 'call.no_answer'] as $eventType) {
            $this->postJson('/api/webhooks/telnyx/voice', ['data' => [
                'id' => 'automation-'.$eventType, 'event_type' => $eventType,
                'payload' => ['call_control_id' => $call->call_control_id],
            ]])->assertOk();
        }
        $this->assertSame('missed', $call->fresh()->status);
        $this->assertNotNull($call->fresh()->ended_at);
        $this->assertDatabaseCount('voice_follow_up_tasks', 1);
        $this->assertDatabaseCount('voice_automation_runs', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        Http::assertNothingSent();
    }

    public function test_failed_transfer_and_delivery_events_reach_custom_task_rules(): void
    {
        app(VoiceSettingsService::class)->update(['enabled' => false]);
        foreach (['failed_transfer_callback', 'delivery_follow_up'] as $trigger) {
            VoiceAutomationRule::query()->create($this->definition([
                'trigger_type' => $trigger, 'conditions' => [], 'action_type' => 'internal_task',
                'action_config' => ['task_title' => 'Review '.$trigger],
            ]));
        }
        app(\App\Services\TelnyxAi\VoiceRoutingService::class)->createCallback($this->sourceCall(), 'transfer_failed');
        Shoot::factory()->create(['workflow_status' => Shoot::STATUS_DELIVERED, 'delivery_status' => 'delivered', 'completed_at' => now()->subHours(2)]);
        app(ScheduledVoiceCallService::class)->createDueProactiveCalls();
        $this->assertDatabaseHas('voice_automation_runs', ['trigger_type' => 'failed_transfer_callback', 'status' => 'task_open']);
        $this->assertDatabaseHas('voice_automation_runs', ['trigger_type' => 'delivery_follow_up', 'status' => 'task_open']);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        Http::assertNothingSent();
    }
}
