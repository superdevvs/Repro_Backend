<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Exceptions\Messaging\SmsSendException;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Dual-channel delivery for automated payment reminders (Gap C channel).
 *
 * Validates: Requirements 5.1, 5.2, 5.3, 5.5
 *
 * AutomationService::sendPaymentReminder() must deliver a reminder via email AND, when the client
 * has a usable phone number, also via SMS. The two channels are independent and best-effort: an
 * absent address or a failure on one channel must never prevent or undo the other, and a reminder
 * delivered on at least one channel must still let DispatchScheduledMessages mark the row `sent`.
 */
class AutomationServicePaymentReminderChannelsTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    private function seedReminderTemplate(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Invoice Payment Reminder',
            'slug' => 'payment-due-reminder',
            'description' => 'Invoice payment reminder for pending balances',
            'category' => 'INVOICE',
            'subject' => 'Payment Reminder for your shoot',
            'body_html' => '<p>[greeting] your balance is due. [payment_link]</p>',
            'body_text' => "[greeting] your balance is due. Pay at [payment_link]",
            'variables_json' => ['greeting', 'payment_link'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function mockMessaging(): Mockery\MockInterface
    {
        $mock = Mockery::mock(MessagingService::class);
        $this->app->instance(MessagingService::class, $mock);

        return $mock;
    }

    private function service(): AutomationService
    {
        return $this->app->make(AutomationService::class);
    }

    private function unpaidShootFor(User $client): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $client->id,
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);
    }

    private function fakeMessage(array $attributes = []): Message
    {
        return Message::create(array_merge([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'status' => 'SENT',
            'send_source' => 'AUTOMATION',
            'to_address' => 'recipient@example.com',
        ], $attributes));
    }

    public function test_client_with_email_and_phone_gets_both_email_and_sms(): void
    {
        $this->seedReminderTemplate();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'phonenumber' => '+12025550111',
        ]);
        $shoot = $this->unpaidShootFor($client);

        $emailMessage = $this->fakeMessage(['channel' => 'EMAIL', 'related_shoot_id' => $shoot->id]);

        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')
            ->once()
            ->with(Mockery::on(fn ($payload) => ($payload['to'] ?? null) === 'client@example.com'
                && ($payload['contact_type'] ?? null) === 'client'))
            ->andReturn($emailMessage);
        $messaging->shouldReceive('sendSms')
            ->once()
            ->with(Mockery::on(fn ($payload) => ($payload['to'] ?? null) === '+12025550111'
                && ($payload['contact_phone'] ?? null) === '+12025550111'
                && ($payload['contact_type'] ?? null) === 'client'
                && ($payload['send_source'] ?? null) === 'AUTOMATION'
                && in_array('PAYMENT_REMINDER:shoot:' . $shoot->id, (array) ($payload['tags_json'] ?? []), true)))
            ->andReturn($this->fakeMessage(['channel' => 'SMS', 'related_shoot_id' => $shoot->id]));

        $returned = $this->service()->sendPaymentReminder($shoot->fresh());

        // Email is the primary, status-bearing record returned to the dispatcher.
        $this->assertNotNull($returned);
        $this->assertSame($emailMessage->id, $returned->id);
    }

    public function test_client_with_only_email_gets_email_only_and_returns_message(): void
    {
        $this->seedReminderTemplate();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'phonenumber' => null,
        ]);
        $shoot = $this->unpaidShootFor($client);

        $emailMessage = $this->fakeMessage(['channel' => 'EMAIL', 'related_shoot_id' => $shoot->id]);

        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')->once()->andReturn($emailMessage);
        $messaging->shouldReceive('sendSms')->never();

        $returned = $this->service()->sendPaymentReminder($shoot->fresh());

        $this->assertNotNull($returned, 'email-only reminder must return the email Message so the row is marked sent');
        $this->assertSame($emailMessage->id, $returned->id);
    }

    public function test_client_with_only_phone_gets_sms_only_and_returns_message(): void
    {
        $this->seedReminderTemplate();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => '',
            'phonenumber' => '+12025550111',
        ]);
        $shoot = $this->unpaidShootFor($client);

        $smsMessage = $this->fakeMessage(['channel' => 'SMS', 'related_shoot_id' => $shoot->id]);

        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')->never();
        $messaging->shouldReceive('sendSms')->once()->andReturn($smsMessage);

        $returned = $this->service()->sendPaymentReminder($shoot->fresh());

        $this->assertNotNull($returned, 'phone-only reminder must return the SMS Message so the row is marked sent');
        $this->assertSame($smsMessage->id, $returned->id);
    }

    public function test_sms_failure_does_not_prevent_email_and_row_is_marked_sent(): void
    {
        $this->seedReminderTemplate();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'phonenumber' => '+12025550111',
        ]);
        $shoot = $this->unpaidShootFor($client);

        $emailMessage = $this->fakeMessage(['channel' => 'EMAIL', 'related_shoot_id' => $shoot->id]);

        $messaging = $this->mockMessaging();
        // Email succeeds.
        $messaging->shouldReceive('sendEmail')->once()->andReturn($emailMessage);
        // SMS blows up (e.g. provider error / opt-out) — must be swallowed and not undo the email.
        $messaging->shouldReceive('sendSms')->once()->andThrow(new SmsSendException('Recipient is opted out of SMS.'));

        // A pending reminder that is now due, so the dispatcher will call sendPaymentReminder().
        $reminder = PaymentReminder::create([
            'shoot_id' => $shoot->id,
            'scheduled_date' => '2026-01-02',
            'scheduled_at' => now()->subMinute(),
            'status' => PaymentReminder::STATUS_PENDING,
        ]);

        // The dispatcher invokes sendPaymentReminder(): the email is sent even though the SMS
        // throws, and the row is marked sent and linked to the email message.
        $workflowExecutor = Mockery::mock(AutomationWorkflowExecutor::class);
        $workflowExecutor->shouldReceive('resumeDueSteps')->once();

        (new DispatchScheduledMessages())->handle(
            $this->app->make(MessagingService::class),
            $workflowExecutor,
            $this->app->make(AutomationService::class)
        );

        $fresh = $reminder->fresh();
        $this->assertSame(
            PaymentReminder::STATUS_SENT,
            $fresh->status,
            'a reminder delivered on at least one channel must be marked sent (Req 5.1/5.5)'
        );
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(
            $emailMessage->id,
            $fresh->message_id,
            'the row links the email Message even when the SMS channel failed'
        );
    }
}
