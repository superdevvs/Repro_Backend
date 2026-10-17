<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
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
        $payload = $this->payload($shoot, $recipient, $trigger);
        if ($channel === 'sms') {
            $payload['to'] = $recipient->phonenumber;

            return app(MessagingService::class)->sendSms($payload);
        }

        return app(MessagingService::class)->sendEmail($payload);
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
