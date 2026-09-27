<?php

namespace Tests\Feature;

use App\Jobs\SendShootReadyEmailJob;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SendShootReadyEmailJobReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function deliveryChannels(): array
    {
        return [['email'], ['sms']];
    }

    #[DataProvider('deliveryChannels')]
    public function test_saved_wait_starts_cadence_only_after_resumed_client_delivery(string $channel): void
    {
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->delayedRule($channel);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive($channel === 'email' ? 'sendEmail' : 'sendSms')->once()
            ->andReturnUsing(fn ($payload) => $this->accepted($payload, strtoupper($channel)));
        $this->app->instance(MessagingService::class, $messaging);

        $this->runJob($shoot);
        $this->assertSame('waiting', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, Message::count());
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('skipped', $this->stage($shoot)['status']);

        $this->travel(1)->minutes();
        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $anchor = $shoot->fresh()->shoot_ready_notified_at;
        $this->assertNotNull($anchor);
        $this->assertTrue($anchor->equalTo(now()));
        $this->assertSame('completed', $this->stage($shoot)['status']);
        $this->assertSame('completed', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
        $reminders = PaymentReminder::count();
        $this->assertGreaterThan(0, $reminders);

        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertSame(1, Message::count());
        $this->assertSame($reminders, PaymentReminder::count());
        $this->assertTrue($anchor->equalTo($shoot->fresh()->shoot_ready_notified_at));
    }

    public function test_failed_deferred_delivery_remains_retryable_and_does_not_start_cadence_early(): void
    {
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->delayedRule('email');
        $attempts = 0;
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(function ($payload) use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('Provider temporarily unavailable');
            }

            return $this->accepted($payload, 'EMAIL');
        });
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        $this->travel(1)->minutes();
        try {
            app(AutomationWorkflowExecutor::class)->resumeDueSteps();
            $this->fail('Deferred failures must be visible and retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('deferred delivery', $exception->getMessage());
        }
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('failed', $this->stage($shoot)['status']);
        $this->assertSame('waiting', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);

        $this->travel(1)->minutes();
        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertNotNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame('completed', $this->stage($shoot)['status']);
        $this->assertSame('completed', AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole()->status);
        $this->assertSame(1, Message::count());
    }

    public function test_failed_workflow_retries_without_false_progress_or_anchor_and_success_is_deduplicated(): void
    {
        $shoot = $this->shoot();
        $this->rule('email');
        $attempts = 0;
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(function ($payload) use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('Provider temporarily unavailable');
            }

            return $this->accepted($payload, 'EMAIL');
        });
        $this->app->instance(MessagingService::class, $messaging);

        try {
            $this->runJob($shoot);
            $this->fail('Failed workflows must throw so the queue can retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('workflow failed', $exception->getMessage());
        }
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('failed', $this->stage($shoot)['status']);

        $this->runJob($shoot);
        $anchor = $shoot->fresh()->shoot_ready_notified_at;
        $this->assertNotNull($anchor);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame('completed', $this->stage($shoot)['status']);
        $this->runJob($shoot);
        $this->assertSame(1, Message::count());
        $this->assertTrue($anchor->equalTo($shoot->fresh()->shoot_ready_notified_at));
    }

    public function test_deferred_provider_failure_stops_after_three_attempts_with_a_visible_failed_run(): void
    {
        $this->travelTo(now()->startOfMinute());
        $shoot = $this->shoot();
        $this->delayedRule('email');
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->times(3)->andThrow(new \RuntimeException('Provider unavailable'));
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->travel(1)->minutes();
            try {
                app(AutomationWorkflowExecutor::class)->resumeDueSteps();
                $this->fail('A failed deferred attempt must surface the failure.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('deferred delivery', $exception->getMessage());
            }
            $run = AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->sole();
            $this->assertSame($attempt < 3 ? 'waiting' : 'failed', $run->status);
        }
        $this->travel(1)->minutes();
        app(AutomationWorkflowExecutor::class)->resumeDueSteps();
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(0, Message::count());
        $this->assertSame('failed', $this->stage($shoot)['status']);
        $this->assertStringContainsString('three attempts', $run->error_message);
        $this->assertSame(3, $run->steps()->where('status', 'failed')->count());
    }

    public function test_disabled_rule_skips_notification_and_does_not_start_payment_reminders(): void
    {
        $shoot = $this->shoot();
        $this->rule('email')->update(['is_active' => false]);
        $mail = Mockery::mock(MailService::class);
        $mail->shouldNotReceive('sendShootReadyEmail');
        $this->app->instance(MailService::class, $mail);
        $this->runJob($shoot);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(0, Message::count());
        $this->assertSame('skipped', $this->stage($shoot)['status']);
    }

    public function test_blocked_message_is_not_delivery_proof(): void
    {
        $shoot = $this->shoot();
        $this->rule('email');
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(fn ($payload) => $this->accepted($payload, 'EMAIL', 'BLOCKED'));
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('skipped', $this->stage($shoot)['status']);
    }

    public function test_sms_only_client_delivery_starts_cadence_using_actual_accepted_message(): void
    {
        $shoot = $this->shoot();
        $this->rule('sms');
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendSms')->once()->andReturnUsing(function ($payload) {
            $payload['to'] = '+12025550119';

            return $this->accepted($payload, 'SMS');
        });
        $messaging->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $messaging);
        $this->runJob($shoot);
        $this->assertNotNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertGreaterThan(0, PaymentReminder::count());
        $this->assertSame('completed', $this->stage($shoot)['status']);
        $this->assertSame('SMS', Message::sole()->channel);
    }

    public function test_false_legacy_provider_result_retries_without_success_timestamp(): void
    {
        $shoot = $this->shoot();
        AutomationRule::where('trigger_type', 'SHOOT_COMPLETED')->delete();
        $mail = Mockery::mock(MailService::class);
        $mail->shouldReceive('sendShootReadyEmail')->once()->andReturn(false);
        $this->app->instance(MailService::class, $mail);
        try {
            $this->runJob($shoot);
            $this->fail('Unaccepted fallback mail must retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame('failed', $this->stage($shoot)['status']);
    }

    private function shoot(): Shoot
    {
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '(202) 555-0119']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'payment_status' => 'unpaid', 'shoot_ready_notified_at' => null]);
        app(FinalizeProgressTracker::class)->start($shoot->id);

        return $shoot;
    }

    private function rule(string $channel): AutomationRule
    {
        $rule = AutomationRule::where('trigger_type', 'SHOOT_COMPLETED')->firstOrFail();
        $rule->update(['is_active' => true, 'workflow_definition_json' => [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_COMPLETED']],
                ['id' => 'send', 'type' => 'action.'.$channel, 'config' => ['subject' => 'Delivery', 'bodyText' => 'Ready', 'recipientMode' => 'roles', 'recipientRoles' => ['client']]],
                ['id' => 'end', 'type' => 'end', 'config' => []],
            ],
            'edges' => [['id' => 'one', 'source' => 'trigger', 'target' => 'send'], ['id' => 'two', 'source' => 'send', 'target' => 'end']],
        ]]);

        return $rule;
    }

    private function accepted(array $payload, string $channel, string $status = 'SENT'): Message
    {
        return Message::create([
            'channel' => $channel, 'direction' => 'OUTBOUND', 'status' => $status, 'provider' => $channel === 'SMS' ? 'TELNYX' : 'LOCAL_SMTP',
            'to_address' => $payload['to'], 'send_source' => 'AUTOMATION', 'related_shoot_id' => $payload['related_shoot_id'],
            'related_account_id' => $payload['related_account_id'], 'tags_json' => $payload['tags_json'], 'sent_at' => now(),
        ]);
    }

    private function delayedRule(string $channel): AutomationRule
    {
        $rule = $this->rule($channel);
        $workflow = $rule->workflow_definition_json;
        $workflow['nodes'][] = ['id' => 'wait', 'type' => 'wait.duration', 'config' => ['amount' => 1, 'unit' => 'minutes']];
        $workflow['edges'][0]['target'] = 'wait';
        $workflow['edges'][] = ['id' => 'three', 'source' => 'wait', 'target' => 'send'];
        $rule->update(['workflow_definition_json' => $workflow]);

        return $rule;
    }

    private function runJob(Shoot $shoot): void
    {
        (new SendShootReadyEmailJob($shoot->id))->handle(app(MailService::class), app(AutomationService::class));
    }

    private function stage(Shoot $shoot): array
    {
        return collect(app(FinalizeProgressTracker::class)->get($shoot->id)['stages'])->firstWhere('key', FinalizeProgressTracker::STAGE_DELIVERY_EMAIL);
    }
}
