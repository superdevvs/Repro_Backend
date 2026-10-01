<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\MessagingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AutomationConfigurationExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AutomationRule::query()->update(['is_active' => false]);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'provider' => 'FAKE',
            'status' => 'SENT', 'send_source' => 'AUTOMATION', 'to_address' => $payload['to'],
            'subject' => $payload['subject'], 'body_html' => $payload['body_html'],
            'body_text' => $payload['body_text'], 'tags_json' => $payload['tags_json'],
            'related_shoot_id' => $payload['related_shoot_id'] ?? null,
        ]));
        $this->app->instance(MessagingService::class, $messaging);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_system_workflow_template_and_structure_edits_persist_across_list_reads(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $rule = $this->rule('SHOOT_REQUEST_DECLINED', ['client']);
        $rule->update(['is_system_locked' => true]);
        $workflow = app(AutomationWorkflowConverter::class)->getWorkflowDefinition($rule);
        $workflow['meta']['system_default_key'] = 'shoot-request-declined';
        $workflow['meta']['defaults_repaired_20260927'] = true;
        $rule->update(['workflow_definition_json' => $workflow]);
        unset($workflow['meta']);
        $response = $this->putJson('/api/messaging/automations/'.$rule->id, [
            'name' => 'Editable decline', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_REQUEST_DECLINED',
            'template_id' => $rule->template_id, 'workflow_definition_json' => $workflow,
        ])->assertOk()->assertJsonPath('template_source_of_truth', 'database')->assertJsonPath('template_override_ignored', false);
        $this->assertSame($rule->template_id, $response->json('template_id'));
        $this->assertSame('shoot-request-declined', $response->json('workflow_definition_json.meta.system_default_key'));
        $before = $rule->fresh()->toArray();
        $this->getJson('/api/messaging/automations')->assertOk();
        $this->assertSame($before, $rule->fresh()->toArray());
    }

    public function test_saved_template_is_used_for_protected_trigger_and_account_creation_is_once_only(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Avery Jones']);
        $rule = $this->rule('ACCOUNT_CREATED', ['client']);
        $context = ['account_id' => $client->id, 'account' => $client, 'client' => $client, 'automation_rule_id' => $rule->id];
        app(AutomationService::class)->handleEvent('ACCOUNT_CREATED', $context);
        app(AutomationService::class)->handleEvent('ACCOUNT_CREATED', $context);
        $this->assertSame(1, Message::count());
        $this->assertSame('Saved template for Avery Jones', Message::first()->subject);
    }

    public function test_changed_photographer_notifies_old_and_current_assignments_with_recipient_specific_copy(): void
    {
        $old = User::factory()->create(['role' => 'photographer', 'name' => 'Old Photographer']);
        $new = User::factory()->create(['role' => 'photographer', 'name' => 'New Photographer']);
        $rule = $this->rule('PHOTOGRAPHER_CHANGED', ['photographer']);
        app(AutomationService::class)->handleEvent('PHOTOGRAPHER_CHANGED', [
            'automation_rule_id' => $rule->id, 'photographer' => $new,
            'photographers' => [$new], 'affected_photographers' => [$old, $new],
        ]);
        $this->assertSame(2, Message::count());
        $this->assertStringContainsString('removed from this shoot', Message::where('to_address', $old->email)->first()->body_text);
        $this->assertStringContainsString('assigned to this shoot', Message::where('to_address', $new->email)->first()->body_text);
    }

    public function test_rule_scoped_dispatch_does_not_execute_sibling_rules(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $first = $this->rule('INVOICE_DUE', ['client']);
        $this->rule('INVOICE_DUE', ['client']);
        app(AutomationService::class)->handleEvent('INVOICE_DUE', ['client' => $client, 'automation_rule_id' => $first->id]);
        $this->assertSame(1, Message::count());
    }

    public function test_staff_account_recipient_receives_the_saved_welcome_template(): void
    {
        $account = User::factory()->create(['role' => 'photographer', 'name' => 'Staff Account']);
        $rule = $this->rule('ACCOUNT_CREATED', ['account']);
        app(AutomationService::class)->handleEvent('ACCOUNT_CREATED', array_merge(
            app(AutomationService::class)->buildUserContext($account), ['automation_rule_id' => $rule->id]
        ));
        $this->assertSame($account->email, Message::firstOrFail()->to_address);
        $this->assertSame('Saved template for Staff Account', Message::first()->subject);
    }

    public function test_account_created_saved_sms_sends_once_and_respects_disabled_rule(): void
    {
        $account = User::factory()->create(['role' => 'client', 'phonenumber' => '+14105550123']);
        $rule = $this->rule('ACCOUNT_CREATED', ['account']);
        $rule->template->update(['channel' => 'SMS', 'body_text' => 'Saved welcome {{recipient_name}}']);
        app(MessagingService::class)->shouldReceive('sendSms')->once()->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'body_text' => $payload['body_text'],
            'tags_json' => $payload['tags_json'],
        ]));
        $service = app(AutomationService::class);
        $context = $service->buildUserContext($account);
        $service->handleEvent('ACCOUNT_CREATED', $context);
        $result = $service->handleEvent('ACCOUNT_CREATED', $context);
        $this->assertSame([$account->phonenumber], $result['sms_sent_to']);
        $this->assertSame(1, Message::count());
        $this->assertSame('Saved welcome '.$account->name, Message::first()->body_text);
        $rule->update(['is_active' => false]);
        $service->handleEvent('ACCOUNT_CREATED', $context);
        $this->assertSame(1, Message::count());
    }

    public function test_weekly_payout_editor_and_accounting_recipients_are_context_scoped_and_editable(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $editor = User::factory()->create(['role' => 'editor']);
        User::factory()->create(['role' => 'editor']);
        $rule = $this->rule('WEEKLY_PAYOUT_REPORT', ['editor', 'photographer', 'rep']);
        $this->putJson('/api/messaging/automations/'.$rule->id, ['name' => 'Saved payout timing', 'scope' => 'SYSTEM',
            'trigger_type' => 'WEEKLY_PAYOUT_REPORT', 'schedule_json' => ['type' => 'weekly', 'day_of_week' => 3, 'time' => '09:15']])->assertOk();
        app(AutomationService::class)->handleEvent('WEEKLY_PAYOUT_REPORT', ['editor' => $editor]);
        $this->assertSame([$editor->email], Message::pluck('to_address')->all());
        $digest = $this->rule('WEEKLY_PAYOUT_DIGEST', ['accounting']);
        $this->putJson('/api/messaging/automations/'.$digest->id, ['name' => 'Saved accounting address', 'scope' => 'SYSTEM',
            'trigger_type' => 'WEEKLY_PAYOUT_DIGEST', 'schedule_json' => ['type' => 'weekly', 'day_of_week' => 3, 'time' => '09:15', 'accounting_email' => 'accounting@example.test']])->assertOk();
        app(AutomationService::class)->handleEvent('WEEKLY_PAYOUT_DIGEST', ['accounting' => ['name' => 'Accounting', 'email' => 'accounting@example.test']]);
        $this->assertSame('accounting@example.test', Message::latest('id')->first()->to_address);
        $this->assertSame(2, Message::count());
    }

    public function test_booking_and_assignment_sms_share_once_only_delivery_for_each_recipient(): void
    {
        $this->recordSmsMessages();
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+14105550120']);
        $photographer = User::factory()->create(['role' => 'photographer', 'phonenumber' => '+14105550121']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        foreach (['SHOOT_SCHEDULED' => ['client', 'photographer'], 'SHOOT_BOOKED' => ['photographer'], 'PHOTOGRAPHER_ASSIGNED' => ['photographer']] as $trigger => $roles) {
            $this->rule($trigger, $roles)->template->update(['channel' => 'SMS', 'body_text' => 'Saved booking for {{recipient_name}}']);
        }
        $service = app(AutomationService::class);
        $context = $service->buildShootContext($shoot);
        foreach (range(1, 2) as $attempt) {
            foreach (['SHOOT_SCHEDULED', 'SHOOT_BOOKED', 'PHOTOGRAPHER_ASSIGNED'] as $trigger) {
                $service->handleEvent($trigger, $context);
            }
        }
        $this->assertSame(2, Message::where('channel', 'SMS')->count());
        $this->assertEqualsCanonicalizing([$client->phonenumber, $photographer->phonenumber], Message::pluck('to_address')->all());
        $this->assertSame('Saved booking for '.$photographer->name, Message::where('to_address', $photographer->phonenumber)->first()->body_text);
    }

    public function test_reassignment_sms_notifies_client_old_and_new_photographers_once_with_correct_copy(): void
    {
        $this->recordSmsMessages();
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+14105550120']);
        $old = User::factory()->create(['role' => 'photographer', 'phonenumber' => '+14105550121']);
        $current = User::factory()->create(['role' => 'photographer', 'phonenumber' => '+14105550122']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $current->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $this->rule('SHOOT_UPDATED', ['client', 'photographer'])->template->update(['channel' => 'SMS']);
        $this->rule('PHOTOGRAPHER_CHANGED', ['photographer'])->template->update(['channel' => 'SMS']);
        $service = app(AutomationService::class);
        $context = array_merge($service->buildShootContext($shoot), [
            'photographer_changed' => true, 'affected_photographers' => [$old, $current],
            'shoot_changes' => 'Photographer: previous photographer -> current photographer',
        ]);
        foreach (range(1, 2) as $attempt) {
            $service->handleEvent('SHOOT_UPDATED', $context);
            $service->handleEvent('PHOTOGRAPHER_CHANGED', $context);
        }
        $this->assertSame(3, Message::where('channel', 'SMS')->count());
        $this->assertEqualsCanonicalizing([$client->phonenumber, $old->phonenumber, $current->phonenumber], Message::pluck('to_address')->all());
        $this->assertStringContainsString('removed from this shoot', Message::where('to_address', $old->phonenumber)->first()->body_text);
        $this->assertStringContainsString('assigned to this shoot', Message::where('to_address', $current->phonenumber)->first()->body_text);
    }

    public function test_waiting_invoice_reminder_is_cancelled_when_invoice_is_paid_before_resume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00', 'UTC'));
        $client = User::factory()->create(['role' => 'client']);
        $invoice = \App\Models\Invoice::create(['client_id' => $client->id, 'user_id' => $client->id,
            'role' => 'client', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'total' => 100, 'amount_paid' => 0, 'status' => 'sent', 'due_date' => now()]);
        $rule = $this->rule('INVOICE_DUE', ['client']);
        $workflow = app(AutomationWorkflowConverter::class)->getWorkflowDefinition($rule);
        $actionId = $workflow['nodes'][1]['id'];
        $workflow['nodes'][] = ['id' => 'wait', 'type' => 'wait.duration', 'config' => ['amount' => 1, 'unit' => 'hours']];
        $workflow['edges'][0]['target'] = 'wait';
        $workflow['edges'][] = ['id' => 'wait_action', 'source' => 'wait', 'target' => $actionId];
        $rule->update(['workflow_definition_json' => $workflow]);
        app(AutomationService::class)->handleEvent('INVOICE_DUE', ['client' => $client, 'invoice_id' => $invoice->id, 'automation_rule_id' => $rule->id]);
        $this->assertSame('waiting', $rule->recentRuns()->first()->status);
        $invoice->update(['amount_paid' => 100, 'status' => 'paid']);
        Carbon::setTestNow(now()->addHours(2));
        app(\App\Services\Messaging\AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertSame(0, Message::count());
        $this->assertSame('cancelled', $rule->recentRuns()->first()->status);
    }

    public function test_sms_uses_saved_channel_when_previous_email_template_is_intentionally_disabled(): void
    {
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550123']);
        $rule = $this->rule('INVOICE_DUE', ['client']);
        $template = $rule->template;
        $template->update(['is_active' => false]);
        $workflow = app(AutomationWorkflowConverter::class)->getWorkflowDefinition($rule);
        $endId = $workflow['nodes'][2]['id'];
        $number = \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550999', 'label' => 'Chosen sender', 'owner_type' => 'GLOBAL']);
        $workflow['nodes'][] = ['id' => 'sms', 'type' => 'action.sms', 'config' => [
            'bodyText' => 'Hi {{recipient_name}}', 'smsNumberId' => $number->id, 'recipientMode' => 'roles', 'recipientRoles' => ['client'],
        ]];
        $workflow['edges'][1]['target'] = 'sms';
        $workflow['edges'][] = ['id' => 'sms_end', 'source' => 'sms', 'target' => $endId];
        $rule->update(['workflow_definition_json' => $workflow]);
        app(MessagingService::class)->shouldReceive('sendSms')->once()->with(Mockery::on(fn (array $payload) => $payload['sms_number_id'] === $number->id && $payload['to'] === $client->phonenumber
        ))->andReturn(new Message(['status' => 'SENT']));
        app(AutomationService::class)->handleEvent('INVOICE_DUE', ['client' => $client, 'automation_rule_id' => $rule->id]);
        $this->assertSame('completed', $rule->recentRuns()->first()->status);
        $this->assertSame(0, Message::count());
    }

    public function test_two_hour_reminder_respects_saved_offset_status_and_once_only_delivery(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 09:00:00', 'UTC'));
        $photographer = User::factory()->create(['role' => 'photographer']);
        $client = User::factory()->create(['role' => 'client']);
        $rule = $this->rule('PHOTOGRAPHER_SHOOT_REMINDER', ['photographer'], ['offset' => '-2h']);
        $attributes = ['client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'UTC',
            'scheduled_at' => now()->addHours(2), 'scheduled_date' => now()->toDateString(), 'time' => '11:00'];
        $valid = Shoot::factory()->create($attributes);
        Shoot::factory()->create(array_merge($attributes, ['status' => 'cancelled', 'workflow_status' => 'cancelled']));
        Shoot::factory()->create(array_merge($attributes, ['scheduled_at' => now()->addHours(24),
            'scheduled_date' => now()->addDay()->toDateString(), 'time' => '09:00']));
        app(AutomationService::class)->triggerShootReminders();
        app(AutomationService::class)->triggerShootReminders();
        $this->assertSame(1, Message::count());
        $this->assertSame($valid->id, Message::first()->related_shoot_id);
        $this->assertSame($photographer->email, Message::first()->to_address);
        $rule->update(['is_active' => false]);
        $this->assertFalse(app(AutomationService::class)->shouldUseFallback('PHOTOGRAPHER_SHOOT_REMINDER'));
    }

    public function test_internal_admin_changes_do_not_dispatch_customer_update(): void
    {
        $rule = $this->rule('SHOOT_UPDATED', ['client']);
        app(AutomationService::class)->handleEvent('SHOOT_UPDATED', [
            'automation_rule_id' => $rule->id, 'client' => User::factory()->create(['role' => 'client']),
            'shoot_changes' => "Editor Notes: old -> new\nWorkflow Status: Scheduled -> Editing",
        ]);
        $this->assertSame(0, Message::count());
    }

    public function test_payment_cadence_edits_retire_obsolete_pending_rows_and_preserve_sent_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
        $rule = $this->rule('SHOOT_PAYMENT_REMINDER', ['client'], ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7]);
        $shoot = Shoot::factory()->create(['payment_status' => 'unpaid', 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED, 'delivery_status' => 'delivered', 'shoot_ready_notified_at' => now()]);
        $service = app(AutomationService::class);
        $service->schedulePaymentReminders($shoot);
        $sent = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-09-02')->firstOrFail();
        $sent->update(['status' => 'sent', 'sent_at' => now()]);
        $sentBefore = $sent->fresh()->toArray();
        $rule->update(['schedule_json' => ['reminder_days' => [1, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7]]);
        $service->schedulePaymentReminders($shoot);
        $this->assertSame('cancelled', \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-09-04')->firstOrFail()->status);
        $rule->update(['schedule_json' => ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7]]);
        $service->schedulePaymentReminders($shoot);
        $this->assertSame('pending', \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-09-04')->firstOrFail()->status);
        $rule->update(['schedule_json' => ['reminder_days' => [1, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7]]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'UTC'));
        $removed = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-09-04')->firstOrFail();
        $this->assertFalse($service->paymentReminderIsCurrent($removed));
        $this->assertSame('cancelled', $removed->fresh()->status);
        $this->assertSame($sentBefore, $sent->fresh()->toArray());
        $weekly = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-10-06')->firstOrFail();
        $this->assertSame('10:00', $weekly->scheduled_at->format('H:i'));
        $rule->update(['is_active' => false]);
        Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
        $due = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->whereDate('scheduled_date', '2026-09-08')->firstOrFail();
        $this->assertFalse($service->paymentReminderIsCurrent($due));
        $this->assertSame('cancelled', $due->fresh()->status);
        $this->assertNull($service->sendPaymentReminder($shoot));
        $this->assertSame([], $service->schedulePaymentReminders($shoot));
    }

    public function test_invalid_saved_schedule_is_rejected_by_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $rule = $this->rule('PHOTOGRAPHER_SHOOT_REMINDER', ['photographer']);
        $this->putJson('/api/messaging/automations/'.$rule->id, [
            'name' => 'Invalid schedule', 'scope' => 'SYSTEM', 'trigger_type' => 'PHOTOGRAPHER_SHOOT_REMINDER',
            'schedule_json' => ['offset' => '2h', 'time' => '25:90'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schedule.offset', 'schedule.time']);
    }

    public function test_weekly_shoot_reminder_repeat_fields_validate_and_round_trip(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $rule = $this->rule('SHOOT_PAYMENT_REMINDER', ['client']);
        $payload = [
            'name' => 'Weekly shoot balance reminder',
            'scope' => 'SYSTEM',
            'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
        ];

        $this->putJson('/api/messaging/automations/'.$rule->id, $payload + [
            'schedule_json' => ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 0, 'repeat_every_days' => 31],
        ])->assertUnprocessable()->assertJsonValidationErrors(['schedule.repeat_after_day', 'schedule.repeat_every_days']);

        $schedule = ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7];
        $this->putJson('/api/messaging/automations/'.$rule->id, $payload + ['schedule_json' => $schedule])->assertOk();
        $this->assertSame($schedule, $rule->fresh()->schedule_json);
    }

    public function test_reminder_cadence_edit_cancels_obsolete_future_pending_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
        $rule = $this->rule('SHOOT_PAYMENT_REMINDER', ['client'], [
            'reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7,
        ]);
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid', 'total_quote' => 300, 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED, 'delivery_status' => 'delivered', 'shoot_ready_notified_at' => now(),
        ]);
        $service = app(AutomationService::class);
        $service->schedulePaymentReminders($shoot);
        $obsolete = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)
            ->whereDate('scheduled_date', '2026-09-15')->firstOrFail();

        $rule->update(['schedule_json' => [
            'reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 10,
        ]]);
        $service->schedulePaymentReminders($shoot);

        $this->assertSame('cancelled', $obsolete->fresh()->status);
        $this->assertSame('pending', \App\Models\PaymentReminder::where('shoot_id', $shoot->id)
            ->whereDate('scheduled_date', '2026-09-18')->firstOrFail()->status);
    }

    private function recordSmsMessages(): void
    {
        app(MessagingService::class)->shouldReceive('sendSms')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'body_text' => $payload['body_text'],
            'tags_json' => $payload['tags_json'],
        ]));
    }

    private function rule(string $trigger, array $roles, array $schedule = []): AutomationRule
    {
        $template = MessageTemplate::create([
            'name' => 'Saved '.$trigger, 'channel' => 'EMAIL', 'scope' => 'SYSTEM', 'is_active' => true,
            'subject' => 'Saved template for {{recipient_name}}',
            'body_text' => '{{recipient_name}}: {{assignment_message}}', 'variables_json' => ['recipient_name', 'assignment_message'],
        ]);

        return AutomationRule::create([
            'name' => 'Configured '.$trigger, 'trigger_type' => $trigger, 'scope' => 'SYSTEM',
            'is_active' => true, 'is_system_locked' => false, 'template_id' => $template->id,
            'recipients_json' => $roles, 'schedule_json' => $schedule,
        ]);
    }
}
