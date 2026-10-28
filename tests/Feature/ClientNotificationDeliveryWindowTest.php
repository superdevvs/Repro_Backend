<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\CakemailProvider;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use App\Services\Messaging\Providers\FakeSmsProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientNotificationDeliveryWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'messaging.email_primary' => 'CAKEMAIL']);
        AutomationRule::query()->update(['is_active' => false]);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        SmsNumber::create(['phone_number' => '+12025550100', 'label' => 'Test', 'is_default' => true]);
        Storage::fake('local');
        // CI runs as an unprivileged user; use a group it can assign to its files.
        config(['filesystems.disks.media_local.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake('media_local', config('filesystems.disks.media_local'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('notificationMornings')]
    public function test_client_notifications_wait_until_the_allowed_local_time(string $channel, string $at, string $appointment, string $trigger, ?string $release): void
    {
        [$shoot, $client] = $this->shoot($appointment);
        $this->clock($at);
        $message = $this->send($channel, $shoot, $client, $trigger);
        $this->assertSame($release ? 'SCHEDULED' : 'SENT', $message->status);
        if ($release) {
            $this->assertSame('2026-10-17 '.$release, $message->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
            $this->assertCount(0, FakeCakemailProvider::sent());
            $this->assertCount(0, FakeSmsProvider::sent());
            $this->dispatch();
            $this->assertSame('SCHEDULED', $message->fresh()->status);
            $this->clock('2026-10-17 '.$release);
            $this->dispatch();
            $this->assertSame('SENT', $message->fresh()->status);
            $this->dispatch();
        }
        $this->assertCount($channel === 'email' ? 1 : 0, FakeCakemailProvider::sent());
        $this->assertCount($channel === 'sms' ? 1 : 0, FakeSmsProvider::sent());
        $this->assertSame(1, Message::count(), 'Deferred delivery reuses the same durable message and dedupe identity.');
    }

    public static function notificationMornings(): array
    {
        $cases = [
            ['2026-10-17 04:00', '2026-10-17 10:00', 'SHOOT_DELIVERED', '09:00'],
            ['2026-10-17 05:00', '2026-10-17 10:00', 'SHOOT_PAYMENT_REMINDER', '09:00'],
            ['2026-10-17 08:59', '2026-10-17 15:00', 'SHOOT_SUMMARY', '09:00'],
            ['2026-10-17 04:00', '2026-10-17 09:00', 'SHOOT_REMINDER', '07:00'],
            ['2026-10-17 06:59', '2026-10-17 10:00', 'SHOOT_REMINDER', '08:00'],
            ['2026-10-17 07:00', '2026-10-17 09:00', 'SHOOT_REMINDER', null],
            ['2026-10-17 08:00', '2026-10-17 10:00', 'SHOOT_REMINDER', null],
            ['2026-10-17 08:00', '2026-10-17 11:00', 'SHOOT_REMINDER', '09:00'],
            ['2026-10-17 07:00', '2026-10-17 11:01', 'SHOOT_REMINDER', '09:00'],
            ['2026-10-17 07:00', '2026-10-17 12:00', 'SHOOT_REMINDER', '09:00'],
            ['2026-10-17 05:00', '2026-10-18 09:00', 'SHOOT_REMINDER', '09:00'],
            ['2026-10-17 09:00', '2026-10-17 15:00', 'SHOOT_DELIVERED', null],
        ];
        $out = [];
        foreach (['email', 'sms'] as $channel) {
            foreach ($cases as $index => $case) {
                $out[$channel.'-'.$index] = [$channel, ...$case];
            }
        }

        return $out;
    }

    #[DataProvider('transactionalNotifications')]
    public function test_transactional_confirmations_reach_the_provider_before_9am(string $channel, string $trigger, bool $directSource): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, $trigger);
        if ($directSource) {
            $payload['send_source'] = $trigger;
            unset($payload['metadata'], $payload['automation_trigger']);
        }

        $message = $this->sendPayload($channel, $client, $payload);

        $this->assertSame('SENT', $message->status);
        $this->assertNull($message->scheduled_at);
        $this->assertSame(now()->toDateTimeString(), $message->sent_at->toDateTimeString());
        $this->assertProviderSends($channel, 1);
        $this->dispatch();
        $this->assertProviderSends($channel, 1);
    }

    public static function transactionalNotifications(): array
    {
        $types = [
            'SHOOT_REQUESTED' => false, 'SHOOT_BOOKED' => false, 'SHOOT_SCHEDULED' => true,
            'SHOOT_REQUEST_APPROVED' => false, 'SHOOT_REQUEST_MODIFIED' => true, 'SHOOT_REQUEST_DECLINED' => true,
            'PAYMENT_COMPLETED' => false, 'PAYMENT_CONFIRMATION' => true, 'SHOOT_PAID' => true,
            'PAYMENT_REFUNDED' => false, 'SHOOT_CANCELLED' => true, 'SHOOT_CANCELED' => false, 'SHOOT_ON_HOLD' => false,
            'SHOOT_REMOVED' => true, 'SHOOT_DELETED' => false,
        ];
        $cases = [];
        foreach (['email', 'sms'] as $channel) {
            foreach ($types as $trigger => $directSource) {
                $cases[$channel.'-'.$trigger] = [$channel, $trigger, $directSource];
            }
        }

        return $cases;
    }

    #[DataProvider('routineNotifications')]
    public function test_routine_automation_notices_still_wait_for_9am(string $channel, string $trigger): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 10:00');
        $this->clock('2026-10-17 05:00');
        $message = $this->send($channel, $shoot, $client, $trigger);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('2026-10-17 09:00', $message->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertProviderSends($channel, 0);
        $this->clock('2026-10-17 09:00');
        $this->dispatch();
        $this->assertSame('SENT', $message->fresh()->status);
        $this->assertProviderSends($channel, 1);
    }

    public static function routineNotifications(): array
    {
        $cases = [];
        foreach (['email', 'sms'] as $channel) {
            foreach (['PROPERTY_CONTACT_REMINDER', 'INVOICE_DUE', 'INVOICE_OVERDUE', 'WEEKLY_SALES_REPORT', 'SHOOT_COMPLETED'] as $trigger) {
                $cases[$channel.'-'.$trigger] = [$channel, $trigger];
            }
        }

        return $cases;
    }

    #[DataProvider('manualNotifications')]
    public function test_manual_notification_templates_keep_their_confirmation_or_reminder_timing(string $channel, string $type, bool $immediate): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        if (in_array($type, ['payment_receipt', 'refund_submitted'], true)) {
            $payment = Payment::factory()->create([
                'shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 100,
                'status' => Payment::STATUS_COMPLETED, 'payment_method' => 'card', 'processed_at' => now(),
            ]);
            if ($type === 'refund_submitted') {
                $payment->refunds()->create([
                    'shoot_id' => $shoot->id, 'amount' => 25, 'status' => 'succeeded', 'provider' => 'manual',
                ]);
            }
        }
        if ($type === 'payment_due_reminder') {
            Invoice::factory()->create([
                'shoot_id' => $shoot->id, 'client_id' => $client->id, 'user_id' => $client->id,
                'role' => Invoice::ROLE_CLIENT, 'total' => 100, 'subtotal' => 100, 'tax' => 0,
            ]);
        }
        $slug = ManualNotificationService::TYPES[$type].($channel === 'sms' ? '-sms' : '');
        MessageTemplate::updateOrCreate(['slug' => $slug], [
            'channel' => strtoupper($channel), 'name' => 'Timing fixture', 'category' => 'GENERAL',
            'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true, 'variables_json' => [],
            'subject' => 'Notification', 'body_text' => 'A notification for your shoot.',
            'body_html' => '<p>A notification for your shoot.</p>',
        ]);
        $sender = User::factory()->create(['role' => 'admin']);
        $message = app(ManualNotificationService::class)->send($shoot, $type, 'client', $channel, $sender);

        $this->assertSame('MANUAL', $message->send_source);
        $this->assertSame($slug, $message->template->slug);
        $this->assertSame($immediate ? 'SENT' : 'SCHEDULED', $message->status);
        $this->assertProviderSends($channel, $immediate ? 1 : 0);
        if (! $immediate) {
            $this->assertSame('2026-10-17 09:00', $message->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
            $this->clock('2026-10-17 09:00');
            $this->dispatch();
            $this->assertSame('SENT', $message->fresh()->status);
            $this->assertProviderSends($channel, 1);
        }
    }

    public static function manualNotifications(): array
    {
        return [
            'booking-email' => ['email', 'shoot_requested', true],
            'booking-sms' => ['sms', 'shoot_requested', true],
            'receipt-email' => ['email', 'payment_receipt', true],
            'receipt-sms' => ['sms', 'payment_receipt', true],
            'refund-email' => ['email', 'refund_submitted', true],
            'reminder-email' => ['email', 'shoot_reminder', false],
            'reminder-sms' => ['sms', 'shoot_reminder', false],
            'payment-reminder-email' => ['email', 'payment_due_reminder', false],
            'payment-due-email' => ['email', 'payment_due', false],
            'payment-due-sms' => ['sms', 'payment_due', false],
            'ready-sms' => ['sms', 'shoot_ready', false],
            'summary-email' => ['email', 'shoot_summary', false],
        ];
    }

    #[DataProvider('channels')]
    public function test_a_manual_10am_shoot_reminder_uses_the_same_8am_release_as_automation(string $channel): void
    {
        [$shoot] = $this->shoot('2026-10-17 10:00');
        $this->clock('2026-10-17 05:00');
        MessageTemplate::updateOrCreate(['slug' => 'shoot-reminder'.($channel === 'sms' ? '-sms' : '')], [
            'channel' => strtoupper($channel), 'name' => 'Shoot reminder', 'category' => 'REMINDER',
            'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true, 'variables_json' => [],
            'subject' => 'Shoot reminder', 'body_text' => 'Your shoot is scheduled for today.',
            'body_html' => '<p>Your shoot is scheduled for today.</p>',
        ]);
        $sender = User::factory()->create(['role' => 'admin']);
        $message = app(ManualNotificationService::class)->send($shoot, 'shoot_reminder', 'client', $channel, $sender);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('2026-10-17 08:00', $message->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertProviderSends($channel, 0);
        $this->clock('2026-10-17 07:59');
        $this->dispatch();
        $this->assertSame('SCHEDULED', $message->fresh()->status);
        $this->clock('2026-10-17 08:00');
        $this->dispatch();
        $this->assertSame('SENT', $message->fresh()->status);
        $this->assertProviderSends($channel, 1);
    }

    public static function channels(): array
    {
        return [['email'], ['sms']];
    }

    #[DataProvider('channels')]
    public function test_a_queued_manual_reminder_is_cancelled_after_its_appointment_is_rescheduled(string $channel): void
    {
        [$shoot] = $this->shoot('2026-10-17 10:00');
        $this->clock('2026-10-17 05:00');
        MessageTemplate::updateOrCreate(['slug' => 'shoot-reminder'.($channel === 'sms' ? '-sms' : '')], [
            'channel' => strtoupper($channel), 'name' => 'Shoot reminder', 'category' => 'REMINDER',
            'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true, 'variables_json' => [],
            'subject' => 'Shoot reminder', 'body_text' => 'Your shoot is at 10am.', 'body_html' => '<p>Your shoot is at 10am.</p>',
        ]);
        $sender = User::factory()->create(['role' => 'admin']);
        $message = app(ManualNotificationService::class)->send($shoot, 'shoot_reminder', 'client', $channel, $sender);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('08:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $shoot->update(['scheduled_at' => Carbon::parse('2026-10-17 15:00', 'America/New_York')->utc()]);
        $this->clock('2026-10-17 08:00');
        $this->dispatch();
        $this->assertSame('CANCELLED', $message->fresh()->status);
        $this->assertProviderSends($channel, 0);
    }

    #[DataProvider('manualReplyContexts')]
    public function test_a_direct_manual_reply_remains_immediate_when_attached_to_a_shoot_or_invoice(string $channel, string $context): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        $payload = ['to' => $client->email, 'subject' => 'Re: Your question', 'body_text' => 'Here is the answer you requested.', 'send_source' => 'MANUAL'];
        if ($context === 'invoice') {
            $invoice = Invoice::factory()->create(['user_id' => $client->id, 'client_id' => $client->id, 'shoot_id' => $shoot->id]);
            $payload['related_invoice_id'] = $invoice->id;
        } else {
            $payload['related_shoot_id'] = $shoot->id;
        }
        $message = $this->sendPayload($channel, $client, $payload);
        $this->assertSame('SENT', $message->status);
        $this->assertNull($message->template_id);
        $this->assertNull($message->scheduled_at);
        $this->assertProviderSends($channel, 1);
    }

    public static function manualReplyContexts(): array
    {
        return [['email', 'shoot'], ['sms', 'shoot'], ['email', 'invoice'], ['sms', 'invoice']];
    }

    #[DataProvider('routineManualMarkers')]
    public function test_explicit_reminder_identity_is_not_bypassed_by_a_manual_send_source(string $channel, string $identity): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, 'SHOOT_PAYMENT_REMINDER');
        $payload['send_source'] = 'MANUAL';
        if ($identity === 'template') {
            unset($payload['metadata'], $payload['automation_trigger']);
            $template = MessageTemplate::updateOrCreate(['slug' => 'shoot-payment-reminder'.($channel === 'sms' ? '-sms' : '')], [
                'channel' => strtoupper($channel), 'name' => 'Payment reminder', 'category' => 'PAYMENT',
                'scope' => 'SYSTEM', 'is_active' => true, 'body_text' => 'A payment reminder.',
            ]);
            $payload['template_id'] = $template->id;
        }
        $message = $this->sendPayload($channel, $client, $payload);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $this->assertProviderSends($channel, 0);
    }

    public static function routineManualMarkers(): array
    {
        return [['email', 'trigger'], ['sms', 'trigger'], ['email', 'template'], ['sms', 'template']];
    }

    #[DataProvider('sameDayUpdates')]
    public function test_only_future_same_day_operational_updates_bypass_quiet_hours(string $channel, string $trigger, string $appointment, bool $immediate): void
    {
        [$shoot, $client] = $this->shoot($appointment);
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, $trigger);
        if ($trigger === 'SHOOT_UPDATED') {
            $payload['metadata']['notification']['urgent'] = true;
        }
        $message = $this->sendPayload($channel, $client, $payload);
        $this->assertSame($immediate ? 'SENT' : 'SCHEDULED', $message->status);
        $this->assertProviderSends($channel, $immediate ? 1 : 0);
        if (! $immediate) {
            $this->assertSame('2026-10-17 09:00', $message->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        }
    }

    public static function sameDayUpdates(): array
    {
        $cases = [];
        foreach (['email', 'sms'] as $channel) {
            foreach (['SHOOT_UPDATED', 'PHOTOGRAPHER_CHANGED'] as $trigger) {
                foreach (['2026-10-17 10:00' => true, '2026-10-18 10:00' => false, '2026-10-17 04:00' => false] as $appointment => $immediate) {
                    $cases[$channel.'-'.$trigger.'-'.$appointment] = [$channel, $trigger, $appointment, $immediate];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('channels')]
    public function test_a_routine_update_about_todays_shoot_still_waits_until_9am(string $channel): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 10:00');
        $this->clock('2026-10-17 05:00');
        $message = $this->send($channel, $shoot, $client, 'SHOOT_UPDATED');
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $this->assertProviderSends($channel, 0);
    }

    public function test_a_requested_service_item_does_not_make_an_update_urgent_for_today(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $shoot->serviceItems()->create([
            'service_id' => \App\Models\Service::factory()->create()->id,
            'scheduled_at' => Carbon::parse('2026-10-17 10:00', 'America/New_York')->utc(),
            'workflow_status' => 'requested', 'price' => 100,
        ]);
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, 'SHOOT_UPDATED');
        $payload['metadata']['notification']['urgent'] = true;
        $message = $this->sendPayload('email', $client, $payload);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $this->assertProviderSends('email', 0);
    }

    public function test_an_urgent_update_uses_the_recipients_local_day_for_the_appointment(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 00:00'); // 9pm October 17 for the client.
        $client->update(['timezone' => 'America/Los_Angeles']);
        $this->clock('2026-10-17 05:00'); // 2am October 17 for the client.
        $payload = $this->payload($shoot, $client, 'SHOOT_UPDATED');
        $payload['metadata']['notification']['urgent'] = true;
        $message = $this->sendPayload('email', $client, $payload);
        $this->assertSame('SENT', $message->status);
        $this->assertProviderSends('email', 1);
    }

    #[DataProvider('paymentFailureOrigins')]
    public function test_payment_failures_bypass_quiet_hours_only_for_an_active_client_action(string $channel, ?string $origin): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, 'PAYMENT_FAILED');
        if ($origin) {
            $payload['metadata']['notification']['origin'] = $origin;
        }
        $message = $this->sendPayload($channel, $client, $payload);
        $immediate = $origin === 'client_action';
        $this->assertSame($immediate ? 'SENT' : 'SCHEDULED', $message->status);
        $this->assertProviderSends($channel, $immediate ? 1 : 0);
        if (! $immediate) {
            $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        }
    }

    public static function paymentFailureOrigins(): array
    {
        return [['email', 'client_action'], ['sms', 'client_action'], ['email', 'background'], ['sms', 'background'], ['email', null], ['sms', null]];
    }

    #[DataProvider('securityNotifications')]
    public function test_security_verification_and_phone_change_notices_reach_the_provider_immediately(string $channel, string $source): void
    {
        [$shoot, $client] = $this->shoot('2026-10-18 15:00');
        $this->clock('2026-10-17 05:00');
        $message = $this->sendPayload($channel, $client, [
            'to' => $client->email, 'subject' => 'Account security', 'body_text' => 'Your requested account security message.',
            'send_source' => $source, 'related_shoot_id' => $shoot->id,
        ]);
        $this->assertSame('SENT', $message->status);
        $this->assertProviderSends($channel, 1);
    }

    public static function securityNotifications(): array
    {
        return [
            ['email', 'PASSWORD_RESET'], ['email', 'CLIENT_EMAIL_VERIFICATION'], ['email', 'CLIENT_EMAIL_VERIFIED'],
            ['sms', 'VOICE_PHONE_VERIFICATION'], ['sms', 'VOICE_CALLER_VERIFICATION'],
            ['sms', 'PHONE_NUMBER_CHANGED_PREVIOUS'], ['sms', 'PHONE_NUMBER_CHANGED_NEW'],
        ];
    }

    public function test_existing_scheduled_messages_are_checked_again_and_rescheduled_reminders_are_cancelled(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 10:00');
        $this->clock('2026-10-17 05:00');
        $message = app(MessagingService::class)->scheduleEmail($this->payload($shoot, $client, 'SHOOT_REMINDER'), now());
        $this->dispatch();
        $this->assertSame('08:00', $message->fresh()->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $shoot->update(['scheduled_at' => Carbon::parse('2026-10-17 15:00', 'America/New_York')->utc()]);
        $this->clock('2026-10-17 08:00');
        $this->dispatch();
        $this->assertSame('CANCELLED', $message->fresh()->status);
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public function test_old_reminder_tags_identify_the_exact_service_appointment(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 15:00');
        $service = $shoot->serviceItems()->create(['service_id' => \App\Models\Service::factory()->create()->id,
            'scheduled_at' => Carbon::parse('2026-10-17 10:00', 'America/New_York')->utc(), 'workflow_status' => 'scheduled', 'price' => 100]);
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $client, 'SHOOT_REMINDER');
        unset($payload['metadata']);
        $payload['tags_json'] = ['SHOOT_REMINDER:rule:3:service:'.$service->id.':2026-10-17T14:00:00+00:00'];
        $message = app(MessagingService::class)->scheduleEmail($payload, now());
        $this->dispatch();
        $this->assertSame('08:00', $message->fresh()->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $service->update(['workflow_status' => 'cancelled']);
        $this->clock('2026-10-17 08:00');
        $this->dispatch();
        $this->assertSame('CANCELLED', $message->fresh()->status);
    }

    public function test_recipient_timezone_and_dst_are_used_instead_of_server_clock(): void
    {
        [$shoot, $client] = $this->shoot('2026-11-01 15:00');
        $client->update(['timezone' => 'America/Los_Angeles']);
        $this->clock('2026-11-01 10:00'); // 7am Pacific, after the DST transition.
        $message = $this->send('email', $shoot, $client, 'SHOOT_SUMMARY');
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('2026-11-01 17:00', $message->scheduled_at->utc()->format('Y-m-d H:i'));
    }

    public function test_client_cc_cannot_receive_an_early_staff_email_and_raw_attachments_survive_deferral(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 15:00');
        $staff = User::factory()->create(['role' => 'admin']);
        $this->clock('2026-10-17 05:00');
        $payload = $this->payload($shoot, $staff, 'SHOOT_SUMMARY');
        $payload['cc'] = [$client->email];
        $payload['attachments'] = [['filename' => 'invoice.pdf', 'content' => '%PDF-test', 'content_type' => 'application/pdf']];
        $message = app(MessagingService::class)->sendEmail($payload);
        $this->assertSame('SCHEDULED', $message->status);
        $attachment = $message->attachments_json[0];
        $this->assertSame('media_local', $attachment['disk']);
        // Read final permissions rather than Flysystem's cached pre-chmod stat.
        clearstatcache();
        $this->assertSame(0660, fileperms(Storage::disk('media_local')->path($attachment['storage_path'])) & 0777);
        $this->assertSame(02770, fileperms(dirname(Storage::disk('media_local')->path($attachment['storage_path']))) & 07777);
        $this->assertSame(posix_getgrnam(config('filesystems.disks.media_local.shared_group'))['gid'], filegroup(Storage::disk('media_local')->path($attachment['storage_path'])));
        $this->clock('2026-10-17 09:00');
        $provider = \Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('send')->once()->withArgs(function ($channel, $payload) use ($client) {
            return $payload['attachments'][0]['content'] === '%PDF-test' && $payload['cc'] === [$client->email];
        })->andReturn('attachment-test');
        $this->app->instance(CakemailProvider::class, $provider);
        $this->app->forgetInstance(MessagingService::class);
        $this->dispatch();
        $this->assertSame('SENT', $message->fresh()->status);
    }

    public function test_staff_reminders_and_user_requested_password_resets_still_send_immediately(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 15:00');
        $this->clock('2026-10-17 05:00');
        $staff = User::factory()->create(['role' => 'photographer']);
        $this->assertSame('SENT', $this->send('email', $shoot, $staff, 'PHOTOGRAPHER_SHOOT_REMINDER')->status);
        $payload = $this->payload($shoot, $client, 'PASSWORD_RESET');
        $payload['send_source'] = 'PASSWORD_RESET';
        $this->assertSame('SENT', app(MessagingService::class)->sendEmail($payload)->status);
    }

    public function test_fresh_sms_opt_out_is_still_enforced_when_quiet_hours_end(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 15:00');
        $this->clock('2026-10-17 05:00');
        $message = $this->send('sms', $shoot, $client, 'SHOOT_DELIVERED');
        $client->update(['metadata' => ['preferences' => ['notificationSMS' => false]]]);
        $this->clock('2026-10-17 09:00');
        $this->dispatch();
        $this->assertSame('BLOCKED', $message->fresh()->status);
        $this->assertCount(0, FakeSmsProvider::sent());
    }

    public function test_shoots_before_9am_do_not_get_an_early_exception_or_an_expired_reminder(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 08:00');
        $this->clock('2026-10-17 05:00');
        $message = $this->send('sms', $shoot, $client, 'SHOOT_REMINDER');
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('09:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
        $this->clock('2026-10-17 09:00');
        $this->dispatch();
        $this->assertSame('CANCELLED', $message->fresh()->status);
        $this->assertCount(0, FakeSmsProvider::sent());
    }

    public function test_legacy_floating_appointments_keep_their_local_clock(): void
    {
        [$shoot, $client] = $this->shoot('2026-10-17 09:00');
        $shoot->update(['timezone' => null, 'scheduled_at' => '2026-10-17 09:00:00']);
        $this->clock('2026-10-17 04:00');
        $payload = $this->payload($shoot, $client, 'SHOOT_REMINDER');
        $payload['metadata']['notification']['appointment_at'] = '2026-10-17T13:00:00+00:00';
        $message = app(MessagingService::class)->sendEmail($payload);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertSame('07:00', $message->scheduled_at->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_a_full_batch_of_deferred_messages_is_dispatched_even_when_times_and_ids_differ(): void
    {
        $this->clock('2026-10-17 09:00');
        foreach (range(1, 60) as $index) {
            Message::create(['channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'status' => 'SCHEDULED', 'to_address' => 'client@example.com',
                'scheduled_at' => now()->subMinutes(61 - $index)]);
        }
        // The last ID must not become the chunk cursor before earlier IDs are visited.
        Message::latest('id')->first()->update(['scheduled_at' => now()->subDay()]);
        $messaging = \Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('dispatchScheduledMessage')->times(60)->andReturnUsing(function (Message $message) {
            $message->update(['status' => 'SENT']);

            return $message;
        });
        (new DispatchScheduledMessages)->handle($messaging, app(AutomationWorkflowExecutor::class), app(AutomationService::class));
        $this->assertSame(60, Message::where('status', 'SENT')->count());
    }

    public function test_migration_updates_both_schedule_copies_without_enabling_rules_or_changing_actions(): void
    {
        $schedule = ['offset' => '-24h', 'client_email_schedule' => ['previous_day_time' => '07:00', 'day_of_time' => '07:00',
            'morning_start' => '07:00', 'morning_end' => '12:00', 'morning_previous_evening_time' => '18:30', 'morning_lead_minutes' => 120]];
        $action = ['id' => 'send', 'type' => 'action.email', 'config' => ['subject' => 'Authored reminder']];
        $rule = AutomationRule::create(['name' => 'Authored', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_REMINDER', 'is_active' => false,
            'schedule_json' => $schedule, 'workflow_definition_json' => ['nodes' => [
                ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_REMINDER', 'schedule' => $schedule]], $action,
            ], 'edges' => []]]);
        $migration = require database_path('migrations/2026_10_17_150000_update_client_notification_mornings.php');
        $migration->up();
        $migration->up();
        $rule->refresh();
        $this->assertFalse($rule->is_active);
        $this->assertSame('09:00', $rule->schedule_json['client_email_schedule']['previous_day_time']);
        $this->assertSame('11:00', $rule->schedule_json['client_email_schedule']['morning_end']);
        $this->assertSame('18:30', $rule->schedule_json['client_email_schedule']['morning_previous_evening_time']);
        $this->assertSame($rule->schedule_json, $rule->workflow_definition_json['nodes'][0]['config']['schedule']);
        $this->assertSame($action, $rule->workflow_definition_json['nodes'][1]);
    }

    #[DataProvider('deliveryBalances')]
    public function test_overnight_full_delivery_waits_without_retrying_and_starts_follow_up_only_after_sending(bool $settled): void
    {
        $this->clock('2026-10-17 05:00');
        [$shoot] = $this->shoot('2026-10-17 15:00');
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'total_quote' => $settled ? 0 : 500, 'base_quote' => $settled ? 0 : 500,
            'payment_status' => $settled ? 'paid' : 'unpaid', 'completed_at' => now(), 'shoot_ready_notified_at' => null]);
        AutomationRule::whereIn('trigger_type', ['SHOOT_COMPLETED', 'SHOOT_PAYMENT_REMINDER'])->update(['is_active' => true]);
        app(\App\Services\Shoots\FinalizeProgressTracker::class)->start($shoot->id);
        $job = new \App\Jobs\SendShootReadyEmailJob($shoot->id, null, true, true);
        $job->handle(app(\App\Services\MailService::class), app(AutomationService::class));
        $job->handle(app(\App\Services\MailService::class), app(AutomationService::class));
        $messages = Message::where('related_shoot_id', $shoot->id)->where('to_address', $shoot->client->email)->get();
        $this->assertCount(1, $messages);
        $this->assertSame('SCHEDULED', $messages->first()->status);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->count());
        $ledger = \App\Models\SystemEmailDispatch::where('message_id', $messages->first()->id)->sole();
        $this->assertSame('scheduled', $ledger->status);
        $this->assertCount(0, FakeCakemailProvider::sent());
        $this->clock('2026-10-17 09:00');
        $this->dispatch();
        $this->assertSame('SENT', $messages->first()->fresh()->status);
        $this->assertSame('sent', $ledger->fresh()->status);
        $this->assertSame(now()->toDateTimeString(), $shoot->fresh()->shoot_ready_notified_at->toDateTimeString());
        $count = \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->count();
        $settled ? $this->assertSame(0, $count) : $this->assertGreaterThan(0, $count);
        $this->dispatch();
        $this->assertCount(1, FakeCakemailProvider::sent());
    }

    public static function deliveryBalances(): array
    {
        return [[false], [true]];
    }

    public function test_manual_ready_notice_anchors_payment_follow_up_after_the_morning_send(): void
    {
        $this->clock('2026-10-17 05:00');
        [$shoot] = $this->shoot('2026-10-17 15:00');
        $shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'total_quote' => 500, 'base_quote' => 500, 'payment_status' => 'unpaid', 'completed_at' => now(), 'shoot_ready_notified_at' => null]);
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->update(['is_active' => true]);
        \App\Models\MessageTemplate::updateOrCreate(['slug' => 'shoot-ready'], [
            'channel' => 'EMAIL', 'name' => 'Ready', 'category' => 'GENERAL', 'scope' => 'SYSTEM', 'is_system' => true,
            'is_active' => true, 'subject' => 'Ready', 'body_text' => 'Ready for {{recipient_first_name}}',
            'body_html' => '<p>Ready for {{recipient_first_name}}</p>',
        ]);
        $sender = User::factory()->create(['role' => 'admin']);
        $message = app(\App\Services\Messaging\ManualNotificationService::class)->send($shoot, 'shoot_ready', 'client', 'email', $sender);
        $this->assertSame('SCHEDULED', $message->status);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame(0, \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->count());
        $this->clock('2026-10-17 09:00');
        $this->dispatch();
        $this->assertSame('SENT', $message->fresh()->status);
        $this->assertSame(now()->toDateTimeString(), $shoot->fresh()->shoot_ready_notified_at->toDateTimeString());
        $this->assertGreaterThan(0, \App\Models\PaymentReminder::where('shoot_id', $shoot->id)->count());
    }

    private function shoot(string $local): array
    {
        $client = User::factory()->create(['role' => 'client', 'timezone' => 'America/New_York', 'phonenumber' => '+12025550123']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'timezone' => 'America/New_York', 'scheduled_at' => Carbon::parse($local, 'America/New_York')->utc()]);

        return [$shoot, $client];
    }

    private function payload(Shoot $shoot, User $recipient, string $trigger): array
    {
        return ['to' => $recipient->email, 'subject' => 'Notification', 'body_text' => 'Notification body',
            'send_source' => 'AUTOMATION', 'automation_trigger' => $trigger, 'related_shoot_id' => $shoot->id,
            'metadata' => ['notification' => ['trigger' => $trigger, 'appointment_at' => $shoot->scheduled_at->toIso8601String()]]];
    }

    private function send(string $channel, Shoot $shoot, User $recipient, string $trigger): Message
    {
        return $this->sendPayload($channel, $recipient, $this->payload($shoot, $recipient, $trigger));
    }

    private function sendPayload(string $channel, User $recipient, array $payload): Message
    {
        if ($channel === 'sms') {
            $payload['to'] = $recipient->phonenumber;

            return app(MessagingService::class)->sendSms($payload);
        }

        return app(MessagingService::class)->sendEmail($payload);
    }

    private function assertProviderSends(string $channel, int $count): void
    {
        $this->assertCount($channel === 'email' ? $count : 0, FakeCakemailProvider::sent());
        $this->assertCount($channel === 'sms' ? $count : 0, FakeSmsProvider::sent());
    }

    private function clock(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/New_York')->utc());
    }

    private function dispatch(): void
    {
        (new DispatchScheduledMessages)->handle(app(MessagingService::class), app(AutomationWorkflowExecutor::class), app(AutomationService::class));
    }
}
