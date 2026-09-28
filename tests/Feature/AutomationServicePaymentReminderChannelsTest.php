<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\SmsSendException;
use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
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
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

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
            'body_text' => '[greeting] your balance is due. Pay at [payment_link]',
            'variables_json' => ['greeting', 'payment_link'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
        \App\Models\SmsNumber::create([
            'provider' => 'TELNYX', 'phone_number' => '+12025550999', 'label' => 'Test sender',
            'owner_type' => 'GLOBAL', 'is_default' => true,
        ]);
        \App\Models\AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        app(\App\Services\Messaging\SystemAutomationDefaults::class)->ensure();
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
                && in_array('PAYMENT_REMINDER:shoot:'.$shoot->id, (array) ($payload['tags_json'] ?? []), true)))
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

    public function test_legacy_fallback_sends_compact_sms_without_reusing_email_copy(): void
    {
        $this->seedReminderTemplate();
        $emailCopy = 'Email invoice details: balance, services, access notes, and payment instructions.';
        MessageTemplate::where('slug', 'payment-due-reminder')->update(['body_text' => $emailCopy]);
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        MessageTemplate::where('slug', 'shoot-payment-reminder-sms')->delete();
        config(['app.frontend_url' => 'https://reprodashboard.com']);
        $client = User::factory()->create([
            'role' => 'client', 'name' => 'Lauren Agent',
            'email' => 'client@example.com', 'phonenumber' => '+12025550111',
        ]);
        $shoot = $this->unpaidShootFor($client);
        $shoot->update([
            'address' => '108 James Street', 'city' => 'Woodsboro', 'state' => 'MD', 'zip' => '21798',
            'scheduled_date' => '2026-09-28', 'time' => '10:00',
        ]);
        $emailMessage = $this->fakeMessage(['channel' => 'EMAIL']);
        $smsMessage = $this->fakeMessage(['channel' => 'SMS']);
        $messaging = $this->mockMessaging();
        $emailPayload = null;
        $smsPayload = null;
        $messaging->shouldReceive('sendEmail')->once()
            ->andReturnUsing(function ($payload) use (&$emailPayload, $emailMessage): Message {
                $emailPayload = $payload;

                return $emailMessage;
            });
        $messaging->shouldReceive('sendSms')->once()
            ->andReturnUsing(function ($payload) use (&$smsPayload, $smsMessage): Message {
                $smsPayload = $payload;

                return $smsMessage;
            });

        $this->assertSame($emailMessage->id, $this->service()->sendPaymentReminder($shoot->fresh())?->id);
        $this->assertSame($emailCopy, $emailPayload['body_text']);
        $body = $smsPayload['body_text'];
        $this->assertStringStartsWith("Payment due\n108 James Street, Woodsboro, MD, 21798\n", $body);
        $this->assertStringContainsString('Sep 28, 2026', $body);
        $this->assertStringContainsString('10:00 AM', $body);
        $this->assertStringContainsString('Contact: Lauren Agent +12025550111', $body);
        $this->assertStringContainsString('Details: https://reprodashboard.com/shoots/'.$shoot->id, $body);
        $this->assertStringNotContainsString('balance, services', $body);
        $this->assertStringNotContainsString('maps', $body);
        $this->assertStringNotContainsString('{{', $body);
        $this->assertNull($smsPayload['template_id']);
        $this->assertDatabaseMissing('message_templates', ['slug' => 'shoot-payment-reminder-sms']);
    }

    public function test_legacy_fallback_uses_saved_sms_copy_and_its_template_id(): void
    {
        $this->seedReminderTemplate();
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        $template = MessageTemplate::where('slug', 'shoot-payment-reminder-sms')->firstOrFail();
        $template->update(['body_text' => 'Custom reminder: {{shoot_address}}', 'is_active' => true]);
        $client = User::factory()->create(['email' => '', 'phonenumber' => '+12025550111']);
        $shoot = $this->unpaidShootFor($client);
        $message = $this->fakeMessage(['channel' => 'SMS']);
        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')->never();
        $messaging->shouldReceive('sendSms')->once()
            ->with(Mockery::on(fn ($payload) => str_starts_with($payload['body_text'], 'Custom reminder: '.$shoot->address)
                && $payload['template_id'] === $template->id))
            ->andReturn($message);

        $this->assertSame($message->id, $this->service()->sendPaymentReminder($shoot->fresh())?->id);
        $this->assertSame('Custom reminder: {{shoot_address}}', $template->fresh()->body_text);
    }

    public function test_legacy_fallback_respects_disabled_sms_template_and_still_sends_email(): void
    {
        $this->seedReminderTemplate();
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        MessageTemplate::where('slug', 'shoot-payment-reminder-sms')->update(['is_active' => false]);
        $client = User::factory()->create(['email' => 'client@example.com', 'phonenumber' => '+12025550111']);
        $shoot = $this->unpaidShootFor($client);
        $message = $this->fakeMessage(['channel' => 'EMAIL']);
        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')->once()->andReturn($message);
        $messaging->shouldReceive('sendSms')->never();

        $this->assertSame($message->id, $this->service()->sendPaymentReminder($shoot->fresh())?->id);
    }

    public function test_legacy_sms_can_send_when_email_template_is_unavailable(): void
    {
        $this->seedReminderTemplate();
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        MessageTemplate::where('slug', 'payment-due-reminder')->update(['is_active' => false]);
        $client = User::factory()->create(['email' => 'client@example.com', 'phonenumber' => '+12025550111']);
        $shoot = $this->unpaidShootFor($client);
        $message = $this->fakeMessage(['channel' => 'SMS']);
        $messaging = $this->mockMessaging();
        $messaging->shouldReceive('sendEmail')->never();
        $messaging->shouldReceive('sendSms')->once()->andReturn($message);

        $this->assertSame($message->id, $this->service()->sendPaymentReminder($shoot->fresh())?->id);
    }

    public function test_sms_failure_does_not_prevent_email_and_row_is_marked_sent(): void
    {
        \Carbon\Carbon::setTestNow('2026-01-02 10:01:00');
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

        (new DispatchScheduledMessages)->handle(
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
