<?php

namespace Tests\Feature;

use App\Jobs\SendShootReadyEmailJob;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\{Bus, DB, Http, Queue};
use App\Services\Messaging\{ShootDeliveryCycle, ShootDeliveryNotificationOutbox, ShootDeliveryRevision};
use Mockery;
use Tests\TestCase;

/** The protected client email and saved automation channels must retry as one delivery job. */
class SendShootReadyEmailJobReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Test delivery',
            'from_email' => 'delivery@example.test', 'is_default' => true, 'owner_scope' => 'GLOBAL',
        ]);
    }

    public function test_protected_delivery_failure_retries_before_running_the_saved_workflow(): void
    {
        $shoot = $this->shoot();
        $this->rule('email');
        $attempts = 0;
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(function (array $payload) use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('Provider temporarily unavailable');
            }

            return $this->accepted($payload, 'EMAIL');
        });
        $this->app->instance(MessagingService::class, $messaging);

        try {
            $this->runJob($shoot);
            $this->fail('A failed protected delivery must retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(0, AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->count());
        $this->assertSame('failed', $this->stage($shoot)['status']);

        $this->runJob($shoot);
        $anchor = $shoot->fresh()->shoot_ready_notified_at;
        $this->assertNotNull($anchor);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame('completed', $this->stage($shoot)['status']);

        $this->runJob($shoot);
        $this->assertSame(1, Message::where('send_source', 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, Message::where('send_source', 'AUTOMATION')->count());
        $this->assertTrue($anchor->equalTo($shoot->fresh()->shoot_ready_notified_at));
    }

    public function test_deferred_sms_runs_after_protected_email_without_moving_the_reminder_anchor(): void
    {
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->delayedRule('sms');
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $messaging->shouldReceive('sendSms')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'SMS'));
        $this->app->instance(MessagingService::class, $messaging);

        $this->runJob($shoot);
        $anchor = $shoot->fresh()->shoot_ready_notified_at;
        $this->assertNotNull($anchor);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame('waiting', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
        $this->assertSame(1, Message::where('send_source', 'SHOOT_DELIVERED')->count());

        $this->travel(1)->minutes();
        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertSame('completed', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
        $this->assertSame(1, Message::where('channel', 'SMS')->count());
        $this->assertTrue($anchor->equalTo($shoot->fresh()->shoot_ready_notified_at));
        $this->assertSame('completed', $this->stage($shoot)['status']);
    }

    public function test_failed_sms_workflow_retries_without_resending_the_protected_email(): void
    {
        $shoot = $this->shoot();
        $this->rule('sms');
        $smsAttempts = 0;
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $messaging->shouldReceive('sendSms')->twice()->andReturnUsing(function (array $payload) use (&$smsAttempts) {
            if (++$smsAttempts === 1) {
                throw new \RuntimeException('SMS provider unavailable');
            }

            return $this->accepted($payload, 'SMS');
        });
        $this->app->instance(MessagingService::class, $messaging);

        try {
            $this->runJob($shoot);
            $this->fail('A failed saved SMS action must retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('workflow failed', $exception->getMessage());
        }
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(1, Message::where('send_source', 'SHOOT_DELIVERED')->count());

        $this->runJob($shoot);
        $this->assertNotNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame(1, Message::where('send_source', 'SHOOT_DELIVERED')->count());
        $this->assertSame(1, Message::where('channel', 'SMS')->count());
        $this->assertSame('completed', $this->stage($shoot)['status']);
    }

    public function test_disabled_rule_suppresses_full_delivery_email_and_payment_cadence(): void
    {
        $shoot = $this->shoot();
        $this->rule('email')->update(['is_active' => false]);
        $mail = Mockery::mock(MailService::class);
        $mail->shouldNotReceive('sendShootReadyEmail');
        $mail->shouldNotReceive('sendShootSummaryEmail');
        $this->app->instance(MailService::class, $mail);

        $this->runJob($shoot);

        $this->assertSame(0, Message::count());
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('skipped', $this->stage($shoot)['status']);
    }

    public function test_blocked_protected_email_cannot_be_replaced_by_generic_workflow_email(): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting(false);
        $shoot = $this->shoot();
        $this->rule('email');

        try {
            $this->runJob($shoot);
            $this->fail('A blocked client email must cause a delivery retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }

        $this->assertSame('BLOCKED', Message::where('send_source', 'SHOOT_DELIVERED')->sole()->status);
        $this->assertSame(0, Message::where('send_source', 'AUTOMATION')->count());
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
    }

    public function test_prior_partial_email_cannot_mask_a_blocked_full_delivery_email(): void
    {
        $shoot = $this->shoot();
        $this->rule('email');
        $this->accepted([
            'to' => $shoot->client->email,
            'subject' => 'Earlier partial delivery',
            'send_source' => 'SHOOT_DELIVERED',
            'related_shoot_id' => $shoot->id,
        ], 'EMAIL');
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting(false);

        try {
            $this->runJob($shoot);
            $this->fail('An accepted partial email cannot satisfy the full delivery send.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }

        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(0, AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->count());
    }

    public function test_same_content_in_two_same_clock_reopened_cycles_notifies_once_per_cycle(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->notificationLine($shoot, 'Delivered plans');
        $this->rule('sms');
        $originalCompleted = now()->subDay();
        DB::table('shoots')->where('id', $shoot->id)->update(['completed_at' => $originalCompleted, 'admin_verified_at' => $originalCompleted]);
        $shoot->refresh();
        $revision = app(ShootDeliveryRevision::class)->forShoot($shoot);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->times(3)->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $messaging->shouldReceive('sendSms')->times(3)->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'SMS'));
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        $initialAnchor = $shoot->fresh()->shoot_ready_notified_at;
        $this->assertSame('initial', data_get(Message::where('channel', 'EMAIL')->sole()->metadata, 'notification.delivery_cycle_key'));
        $initialEmailKey = \App\Models\SystemEmailDispatch::where('related_shoot_id', $shoot->id)->sole()->idempotency_key;
        $this->assertStringEndsWith($revision, $initialEmailKey);

        $events = [];
        foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'] as $uuid) {
            $stale = new SendShootReadyEmailJob($shoot->id);
            DB::table('shoots')->where('id', $shoot->id)->update([
                'active_work_started_at' => now(), 'active_work_completed_at' => now(),
                'active_work_is_reopened' => true, 'active_work_cycle_key' => $uuid,
            ]);
            $shoot->refresh();
            $events[] = app(ShootDeliveryCycle::class)->eventId($shoot);
            $stale->handle(app(MailService::class), app(AutomationService::class));
            $this->runJob($shoot);
            $this->runJob($shoot);
            $this->assertSame($revision, app(ShootDeliveryRevision::class)->forShoot($shoot->fresh()));
        }
        $this->assertNotSame($events[0], $events[1]);
        $this->assertSame(3, Message::where('channel', 'EMAIL')->count());
        $this->assertSame(3, Message::where('channel', 'SMS')->count());
        $this->assertTrue($originalCompleted->equalTo($shoot->fresh()->completed_at));
        $this->assertTrue($initialAnchor->equalTo($shoot->fresh()->shoot_ready_notified_at));
    }

    public function test_same_content_paid_reopened_cycle_sends_a_new_summary_once(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        $this->notificationLine($shoot, 'Delivered plans');
        \App\Models\Payment::factory()->create(['shoot_id' => $shoot->id, 'amount' => 500, 'status' => \App\Models\Payment::STATUS_COMPLETED]);
        $this->rule('email');
        $revision = app(ShootDeliveryRevision::class)->forShoot($shoot->fresh());
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        $key = \App\Models\SystemEmailDispatch::where('related_shoot_id', $shoot->id)->sole()->idempotency_key;
        $this->assertSame('SHOOT_SUMMARY:'.$shoot->id.':'.$shoot->client_id.':'.$revision, $key);
        DB::table('shoots')->where('id', $shoot->id)->update([
            'active_work_started_at' => now(), 'active_work_completed_at' => now(), 'active_work_is_reopened' => true,
            'active_work_cycle_key' => '33333333-3333-4333-8333-333333333333',
        ]);
        $this->runJob($shoot); $this->runJob($shoot);
        $this->assertSame($revision, app(ShootDeliveryRevision::class)->forShoot($shoot->fresh()));
        $this->assertSame(2, Message::where('send_source', 'SHOOT_SUMMARY')->count());
        $this->assertSame('reopened:33333333-3333-4333-8333-333333333333',
            data_get(Message::where('send_source', 'SHOOT_SUMMARY')->latest('id')->first()->metadata, 'notification.delivery_cycle_key'));
    }

    public function test_queued_full_notice_is_skipped_when_work_has_reopened(): void
    {
        $shoot = $this->shoot();
        $job = new SendShootReadyEmailJob($shoot->id);
        DB::table('shoots')->where('id', $shoot->id)->update([
            'status' => 'active', 'workflow_status' => 'active', 'delivery_status' => 'partially_delivered',
            'active_work_started_at' => now(), 'active_work_is_reopened' => true, 'active_work_completed_at' => null,
        ]);
        $mail = Mockery::mock(MailService::class);
        $mail->shouldNotReceive('sendShootReadyEmail'); $mail->shouldNotReceive('sendShootSummaryEmail');
        $job->handle($mail, app(AutomationService::class));
        $this->assertSame(0, Message::count());
        $this->assertSame('skipped', $this->stage($shoot)['status']);
    }

    public function test_deferred_completion_sms_is_cancelled_if_another_cycle_starts(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->delayedRule('sms');
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $messaging->shouldNotReceive('sendSms');
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        DB::table('shoots')->where('id', $shoot->id)->update([
            'status' => 'active', 'workflow_status' => 'active', 'delivery_status' => 'partially_delivered',
            'active_work_started_at' => now(), 'active_work_completed_at' => null, 'active_work_is_reopened' => true,
        ]);
        $this->travel(2)->minutes();
        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertSame(0, Message::where('channel', 'SMS')->count());
        $this->assertSame('cancelled', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
    }

    public function test_partial_outbox_notifies_only_its_exact_finalized_service_scope(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        $first = $this->notificationLine($shoot, 'New provider plans');
        $this->notificationLine($shoot, 'Previously delivered photography');
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => 'editing', 'workflow_status' => 'editing', 'delivery_status' => 'partially_delivered']);
        $shoot->refresh();
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $this->app->instance(MessagingService::class, $messaging);
        $outbox = app(ShootDeliveryNotificationOutbox::class);
        $id = $outbox->record($shoot, 'provider:partial:one', false, [$first]);
        $this->assertSame($id, $outbox->record($shoot, 'provider:partial:one', false, [$first]));
        $outbox->process($id); $outbox->process($id);
        $message = Message::where('channel', 'EMAIL')->sole();
        $this->assertStringContainsString('New provider plans', $message->body_html);
        $this->assertStringNotContainsString('Previously delivered photography', $message->body_html);
        $this->assertStringContainsString('Part of Your Shoot Is Ready', $message->subject);
        $this->assertSame('completed', DB::table('shoot_delivery_notification_outbox')->where('id', $id)->value('status'));
        $this->assertSame(0, AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->count());
    }

    public function test_failed_queue_handoff_is_persisted_and_recovered_with_the_ordinary_sender(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        $outbox = app(ShootDeliveryNotificationOutbox::class);
        $id = $outbox->record($shoot, 'provider:queue-retry', true);
        $attempt = 0;
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) use (&$attempt, $id) {
            $this->assertInstanceOf(SendShootReadyEmailJob::class, $job);
            $this->assertSame($id, $job->outboxId);
            if (++$attempt === 1) throw new \RuntimeException('Queue is temporarily unavailable');
            return $job;
        });
        $this->assertFalse($outbox->dispatch($id));
        $this->assertSame('pending', DB::table('shoot_delivery_notification_outbox')->where('id', $id)->value('status'));
        $this->travel(2)->minutes();
        $this->assertSame(1, $outbox->dispatchPending());
        $this->assertSame(0, Message::count());
    }

    public function test_delivery_rollback_cannot_leave_a_notification_intent(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        try {
            DB::transaction(function () use ($shoot) {
                app(ShootDeliveryNotificationOutbox::class)->record($shoot, 'provider:rolled-back', true);
                throw new \RuntimeException('Delivery rolled back');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Delivery rolled back', $error->getMessage());
        }
        $this->assertSame(0, DB::table('shoot_delivery_notification_outbox')->count());
    }

    public function test_outbox_retry_resumes_failed_workflow_without_resending_an_accepted_email(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        $this->rule('sms');
        $attempt = 0;
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn (array $payload) => $this->accepted($payload, 'EMAIL'));
        $messaging->shouldReceive('sendSms')->twice()->andReturnUsing(function (array $payload) use (&$attempt) {
            if (++$attempt === 1) throw new \RuntimeException('Temporary SMS failure');
            return $this->accepted($payload, 'SMS');
        });
        $this->app->instance(MessagingService::class, $messaging);
        $outbox = app(ShootDeliveryNotificationOutbox::class);
        $id = $outbox->record($shoot, 'provider:retry-notice', true);
        try {
            $outbox->process($id);
            $this->fail('The failed workflow must remain recoverable.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('workflow failed', $error->getMessage());
        }
        $this->assertSame('pending', DB::table('shoot_delivery_notification_outbox')->where('id', $id)->value('status'));
        $outbox->process($id); $outbox->process($id);
        $this->assertSame('completed', DB::table('shoot_delivery_notification_outbox')->where('id', $id)->value('status'));
        $this->assertSame(1, Message::where('channel', 'EMAIL')->count());
        $this->assertSame(1, Message::where('channel', 'SMS')->count());
    }

    public function test_old_outbox_cycle_cannot_notify_a_newer_delivery(): void
    {
        Queue::fake();
        $shoot = $this->shoot();
        $outbox = app(ShootDeliveryNotificationOutbox::class);
        $id = $outbox->record($shoot, 'provider:old-cycle', true);
        DB::table('shoots')->where('id', $shoot->id)->update([
            'active_work_started_at' => now(), 'active_work_completed_at' => now(),
            'active_work_is_reopened' => true, 'active_work_cycle_key' => '22222222-2222-4222-8222-222222222222',
        ]);
        $outbox->process($id);
        $this->assertSame('obsolete', DB::table('shoot_delivery_notification_outbox')->where('id', $id)->value('status'));
        $this->assertSame(0, Message::count());
    }

    public function test_partial_outbox_requires_a_nonempty_service_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ShootDeliveryNotificationOutbox::class)->record($this->shoot(), 'provider:bad-scope', false);
    }

    private function notificationLine(Shoot $shoot, string $name): int
    {
        $service = \App\Models\Service::factory()->noIntake()->create(['name' => $name, 'photographer_required' => false]);
        return (int) $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 35, 'quantity' => 1,
            'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'is_deliverable' => true])->id;
    }

    private function shoot(): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client', 'email' => 'delivery@example.test', 'phonenumber' => '(202) 555-0119',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'total_quote' => 500,
            'base_quote' => 500,
            'payment_status' => 'unpaid',
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'delivery_status' => 'delivered',
            'shoot_ready_notified_at' => null,
        ]);
        app(FinalizeProgressTracker::class)->start($shoot->id);

        return $shoot;
    }

    private function rule(string $channel): AutomationRule
    {
        $rule = AutomationRule::where('trigger_type', 'SHOOT_COMPLETED')->firstOrFail();
        $rule->update(['is_active' => true, 'workflow_definition_json' => [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_COMPLETED']],
                ['id' => 'send', 'type' => 'action.'.$channel, 'config' => [
                    'subject' => 'Delivery', 'bodyText' => 'Ready',
                    'recipientMode' => 'roles', 'recipientRoles' => ['client'],
                ]],
                ['id' => 'end', 'type' => 'end', 'config' => []],
            ],
            'edges' => [
                ['id' => 'one', 'source' => 'trigger', 'target' => 'send'],
                ['id' => 'two', 'source' => 'send', 'target' => 'end'],
            ],
        ]]);

        return $rule;
    }

    private function delayedRule(string $channel): void
    {
        $rule = $this->rule($channel);
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'wait', 'type' => 'wait.duration', 'config' => ['amount' => 1, 'unit' => 'minutes']];
        $workflow['edges'][0]['target'] = 'wait';
        $workflow['edges'][] = ['id' => 'three', 'source' => 'wait', 'target' => 'send'];
        $rule->update(['workflow_definition_json' => $workflow]);
    }

    private function accepted(array $payload, string $channel): Message
    {
        return Message::create([
            'channel' => $channel,
            'direction' => 'OUTBOUND',
            'status' => 'SENT',
            'provider' => $channel === 'SMS' ? 'TELNYX' : 'LOCAL_SMTP',
            'to_address' => $payload['to'],
            'subject' => $payload['subject'] ?? null,
            'body_html' => $payload['body_html'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'send_source' => $payload['send_source'] ?? 'AUTOMATION',
            'related_shoot_id' => $payload['related_shoot_id'],
            'related_account_id' => $payload['related_account_id'] ?? null,
            'tags_json' => $payload['tags_json'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'sent_at' => now(),
        ]);
    }

    private function runJob(Shoot $shoot): void
    {
        (new SendShootReadyEmailJob($shoot->id))->handle(app(MailService::class), app(AutomationService::class));
    }

    private function stage(Shoot $shoot): array
    {
        return collect(app(FinalizeProgressTracker::class)->get($shoot->id)['stages'])
            ->firstWhere('key', FinalizeProgressTracker::STAGE_DELIVERY_EMAIL);
    }
}
