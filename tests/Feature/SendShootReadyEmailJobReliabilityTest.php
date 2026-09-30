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
            $this->assertStringContainsString('no accepted message', $exception->getMessage());
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
            $this->assertStringContainsString('no accepted message', $exception->getMessage());
        }

        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, PaymentReminder::count());
        $this->assertSame(0, AutomationRun::where('trigger_type', 'SHOOT_COMPLETED')->count());
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
