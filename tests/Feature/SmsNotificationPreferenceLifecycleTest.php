<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PaymentReminder;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsNotificationPreferenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        FakeSmsProvider::reset();
        SmsNumber::create(['phone_number' => '+12025550101', 'provider' => 'TELNYX', 'is_default' => true]);
    }

    public function test_weekly_summary_choice_survives_stored_dispatch_and_keeps_other_messages_enabled(): void
    {
        $user = User::factory()->create(['phonenumber' => '+12025550188', 'role' => 'salesRep']);
        $service = app(MessagingService::class);
        $message = $service->sendSms(['to' => $user->phonenumber, 'body_text' => 'Sales summary', 'automation_trigger' => 'WEEKLY_SALES_REPORT']);
        $this->assertTrue(data_get($message->metadata, 'sms_notification.weekly_summary'));
        $message->update(['status' => 'SCHEDULED', 'sent_at' => null, 'provider_message_id' => null]);
        FakeSmsProvider::reset();
        $user->update(['metadata' => ['preferences' => ['notifications' => ['weeklySummaries' => false]]]]);

        $this->assertSame('BLOCKED', $service->dispatchStoredSmsMessage($message)->status);
        $this->assertSame('SENT', $service->sendSms(['to' => $user->phonenumber, 'body_text' => 'Manual message'])->status);
        $this->assertCount(1, FakeSmsProvider::sent());
    }

    public function test_custom_payment_template_obeys_payment_preference(): void
    {
        $user = User::factory()->create(['phonenumber' => '+12025550188', 'metadata' => ['preferences' => ['smsCategories' => ['payments' => false]]]]);
        $template = MessageTemplate::create(['name' => 'Custom payment notice', 'slug' => 'custom-payment-notice', 'channel' => 'SMS', 'category' => 'PAYMENT', 'body_text' => 'Payment due', 'is_active' => true]);
        $message = app(MessagingService::class)->sendSms(['to' => $user->phonenumber, 'body_text' => 'Payment due', 'template_id' => $template->id]);
        $this->assertSame('BLOCKED', $message->status);
        $this->assertSame('payments', data_get($message->metadata, 'sms_notification.category'));
        $this->assertSame([], FakeSmsProvider::sent());
    }

    public function test_alternate_contact_number_still_obeys_account_preferences(): void
    {
        $user = User::factory()->create(['phonenumber' => '+12025550188', 'metadata' => ['preferences' => ['notificationSMS' => false]]]);
        Contact::create(['name' => 'Alternate phone', 'user_id' => $user->id,
            'phones_json' => [['number' => '(202) 555-0199', 'label' => 'Other']]]);
        $message = app(MessagingService::class)->sendSms(['to' => '+12025550199', 'body_text' => 'Manual message']);
        $this->assertSame('BLOCKED', $message->status);
        $this->assertSame([], FakeSmsProvider::sent());
    }

    public function test_manual_ready_endpoint_does_not_report_sent_or_stamp_delivery_when_blocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550188', 'metadata' => ['preferences' => ['smsCategories' => ['deliveryUpdates' => false]]]]);
        MessageTemplate::updateOrCreate(['slug' => 'shoot-ready-sms'], ['name' => 'Ready', 'channel' => 'SMS', 'body_text' => 'Ready', 'is_active' => true]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'shoot_ready_notified_at' => null]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/messaging/notifications/manual-send', [
            'shoot_id' => $shoot->id, 'type' => 'shoot_ready', 'recipient_type' => 'client', 'channel' => 'sms',
        ])->assertUnprocessable()->assertJsonPath('status', 'blocked')
            ->assertJsonPath('message', 'Recipient has disabled this type of text message in notification settings.');
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertSame([], FakeSmsProvider::sent());
    }

    public function test_sms_only_payment_reminder_is_cancelled_once_when_declined_by_recipient(): void
    {
        $this->travelTo(now()->startOfMinute());
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550188', 'metadata' => ['preferences' => ['smsCategories' => ['payments' => false]]]]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'delivery_status' => 'delivered', 'completed_at' => now()->subDay(), 'shoot_ready_notified_at' => now()->subDay(),
            'payment_status' => 'unpaid', 'total_quote' => 300,
        ]);
        AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->delete();
        AutomationRule::create([
            'name' => 'SMS payment reminder', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_PAYMENT_REMINDER',
            'recipients_json' => ['client'], 'is_active' => true, 'engine_version' => 2, 'editor_mode' => 'visual',
            'schedule_json' => ['reminder_days' => [1]],
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_PAYMENT_REMINDER']],
                    ['id' => 'sms', 'type' => 'action.sms', 'config' => ['bodyText' => 'Payment due', 'recipientMode' => 'roles', 'recipientRoles' => ['client']]],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [['id' => 'a', 'source' => 'trigger', 'target' => 'sms'], ['id' => 'b', 'source' => 'sms', 'target' => 'end']],
            ],
        ]);
        $reminder = app(AutomationService::class)->schedulePaymentReminders($shoot)[0];
        $this->assertTrue($reminder->scheduled_at->lte(now()));
        $job = new DispatchScheduledMessages;
        $job->handle(app(MessagingService::class), app(AutomationWorkflowExecutor::class), app(AutomationService::class));
        $job->handle(app(MessagingService::class), app(AutomationWorkflowExecutor::class), app(AutomationService::class));

        $this->assertSame(PaymentReminder::STATUS_CANCELLED, $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->sent_at);
        $this->assertSame(1, Message::where('channel', 'SMS')->where('status', 'BLOCKED')->count());
        $this->assertSame([], FakeSmsProvider::sent());
    }

    public static function blockedRecipientOrder(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('blockedRecipientOrder')]
    public function test_partial_manual_batch_reports_actual_counts_and_stamps_first_success(bool $blockFirst): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $primary = User::factory()->photographer()->create(['phonenumber' => '+12025550188',
            'metadata' => ['preferences' => ['notificationSMS' => ! $blockFirst]]]);
        $second = User::factory()->photographer()->create(['phonenumber' => '+12025550189',
            'metadata' => ['preferences' => ['notificationSMS' => $blockFirst]]]);
        $service = Service::factory()->create(['photographer_required' => true]);
        $shoot = Shoot::factory()->create(['photographer_id' => $primary->id, 'shoot_ready_notified_at' => null]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $second->id, 'is_deliverable' => true]);
        $shoot->services()->attach(Service::factory()->create()->id, ['photographer_id' => $primary->id]);
        MessageTemplate::updateOrCreate(['slug' => 'shoot-ready-sms'], ['name' => 'Ready', 'channel' => 'SMS', 'body_text' => 'Ready', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/messaging/notifications/manual-send', [
            'shoot_id' => $shoot->id, 'type' => 'shoot_ready', 'recipient_type' => 'photographer', 'channel' => 'sms',
        ])->assertUnprocessable()->assertJsonPath('status', 'partial')
            ->assertJsonPath('sent_count', 1)->assertJsonPath('blocked_count', 1);
        $this->assertNotNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertCount(1, FakeSmsProvider::sent());
        $this->assertSame(1, Message::where('channel', 'SMS')->where('status', 'BLOCKED')->count());
    }
}
