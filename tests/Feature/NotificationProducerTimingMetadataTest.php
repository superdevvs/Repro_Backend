<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\ClientNotificationTimingPolicy;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use App\Services\ReproAi\Flows\InvoiceBillingFlow;
use App\Services\ReproAi\Flows\ClientCrmFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\ManualNotificationFixtures;
use Tests\TestCase;

class NotificationProducerTimingMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_receipts_reminders_and_delivery_keep_their_purpose_for_both_channels(): void
    {
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550199']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $sender = User::factory()->create(['role' => 'admin']);
        ManualNotificationFixtures::recordEvents($shoot);
        $captured = [];
        $this->captureMessages($captured);

        foreach (['payment_receipt', 'payment_due', 'shoot_ready', 'payment_due_reminder'] as $type) {
            $this->template(ManualNotificationService::TYPES[$type]);
            foreach (ManualNotificationService::CATALOGUE[$type]['channels'] as $channel) {
                app(ManualNotificationService::class)->send($shoot, $type, 'client', $channel, $sender);
                $notification = end($captured)['metadata']['notification'];
                $this->assertSame(strtoupper($type), $notification['type']);
                $this->assertArrayNotHasKey('origin', $notification);
                $this->assertSame($type === 'shoot_ready' ? 'SHOOT_COMPLETED' : null, $notification['trigger']);
            }
        }
        $this->assertCount(7, $captured);
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
    }

    public function test_manual_reminder_snapshots_active_service_appointment_instead_of_parent_date(): void
    {
        $shoot = Shoot::factory()->create([
            'scheduled_at' => '2026-10-23 15:00:00', 'scheduled_date' => '2026-10-23',
            'time' => '11:00', 'timezone' => 'America/New_York',
        ]);
        foreach ([['2026-10-21 13:00:00', 'cancelled'], ['2026-10-22 14:00:00', 'scheduled']] as [$at, $status]) {
            $service = Service::factory()->create();
            $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'scheduled_at' => $at, 'workflow_status' => $status]);
        }
        $this->template('shoot-reminder');
        $captured = [];
        $this->captureMessages($captured);
        app(ManualNotificationService::class)->send($shoot, 'shoot_reminder', 'client', 'email', User::factory()->create(['role' => 'admin']));

        $this->assertSame('2026-10-22T10:00:00-04:00', $captured[0]['metadata']['notification']['appointment_at']);
    }

    public function test_protected_update_retains_client_action_and_separates_operational_changes(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email_status' => 'verified']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $captured = [];
        $this->captureMessages($captured);
        $mail = app(MailService::class);
        $this->assertTrue($mail->sendShootUpdatedEmail($client, $shoot, 'Schedule: 10:00 AM → 11:00 AM', true, false, ['origin' => 'client_action']));
        $this->assertTrue($mail->sendShootUpdatedEmail($client, $shoot, 'Total: $100 → $120', true, false));

        $this->assertSame('client_action', $captured[0]['metadata']['notification']['origin']);
        $this->assertTrue($captured[0]['metadata']['notification']['urgent']);
        $this->assertSame('SHOOT_UPDATED', $captured[0]['metadata']['canonical_email']['email_alias']);
        $this->assertFalse($captured[1]['metadata']['notification']['urgent']);
        $this->assertArrayNotHasKey('origin', $captured[1]['metadata']['notification']);
    }

    public function test_generic_update_automation_marks_only_operational_changes_urgent(): void
    {
        $captured = [];
        $executor = Mockery::mock(AutomationWorkflowExecutor::class);
        $executor->shouldReceive('executeEventTrigger')->twice()->andReturnUsing(function ($trigger, array $context) use (&$captured) {
            $captured[] = $context;

            return [];
        });
        $this->app->instance(AutomationWorkflowExecutor::class, $executor);
        $automation = app(AutomationService::class);
        $automation->handleEvent('SHOOT_UPDATED', ['shoot_changes' => 'Lockbox Code: 1234 → 5678']);
        $automation->handleEvent('SHOOT_UPDATED', ['shoot_changes' => "Total: $100 → $120\nShoot Notes: Routine note"]);

        $this->assertTrue($captured[0]['metadata']['notification']['urgent']);
        $this->assertFalse($captured[1]['metadata']['notification']['urgent']);
        foreach (['Client: Name corrected', 'Total: $100', 'Shoot Notes: New note'] as $routine) {
            $this->assertFalse(ClientNotificationTimingPolicy::urgentShootUpdate($routine));
        }
    }

    public function test_multiline_notes_do_not_create_operational_summary_fields(): void
    {
        $shoot = Shoot::factory()->create(['shoot_notes' => 'Original note']);
        $mail = app(MailService::class);
        $before = $mail->captureShootSnapshot($shoot);
        $notes = "Routine note\nSchedule: mention from a conversation\r\nLockbox Code: quoted old detail";
        $shoot->update(['shoot_notes' => $notes]);
        $summary = $mail->buildShootChangeSummary($before, $shoot);

        $this->assertStringStartsWith('Shoot Notes:', $summary['summary']);
        $this->assertCount(1, $summary['lines']);
        $this->assertFalse(ClientNotificationTimingPolicy::urgentShootUpdate($summary['summary']));
        $this->assertSame($notes, $shoot->fresh()->shoot_notes);
    }

    public function test_manual_update_uses_recorded_operational_change_purpose(): void
    {
        $shoot = Shoot::factory()->create();
        ManualNotificationFixtures::recordEvents($shoot);
        $this->template('shoot-updated');
        $captured = [];
        $this->captureMessages($captured);
        app(ManualNotificationService::class)->send($shoot, 'shoot_updated', 'client', 'email', User::factory()->create(['role' => 'admin']));

        $this->assertTrue($captured[0]['metadata']['notification']['urgent']);
        $this->assertArrayNotHasKey('origin', $captured[0]['metadata']['notification']);
    }

    public function test_legacy_automation_preserves_trigger_and_appointment_for_custom_templates(): void
    {
        $this->template('custom-booking-message');
        $template = MessageTemplate::where('slug', 'custom-booking-message')->firstOrFail();
        $rule = AutomationRule::create([
            'name' => 'Legacy reminder', 'scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_REMINDER',
            'template_id' => $template->id, 'is_active' => true,
        ]);
        $captured = [];
        $this->captureMessages($captured);
        $method = new \ReflectionMethod(AutomationService::class, 'sendMessage');
        $method->invoke(app(AutomationService::class), $rule, ['email' => 'client@example.com', 'type' => 'client'], [
            'notification_appointment_at' => '2026-10-22T14:00:00+00:00',
            'metadata' => ['notification' => ['origin' => 'client_action']],
        ]);

        $this->assertSame('SHOOT_REMINDER', $captured[0]['metadata']['notification']['trigger']);
        $this->assertSame('2026-10-22T14:00:00+00:00', $captured[0]['metadata']['notification']['appointment_at']);
        $this->assertSame('client_action', $captured[0]['metadata']['notification']['origin']);
    }

    public function test_invoice_fulfillment_is_direct_only_for_the_invoice_clients_own_session(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'user_id' => $client->id]);
        $captured = [];
        $this->captureMessages($captured);
        foreach ([$client, User::factory()->create(['role' => 'admin'])] as $actor) {
            $session = AiChatSession::create([
                'title' => 'Invoice timing test',
                'user_id' => $actor->id, 'step' => 'send_invoice', 'state_data' => ['invoice_id' => $invoice->id],
            ]);
            app(InvoiceBillingFlow::class)->handle($session, 'Send the invoice');
        }

        $this->assertCount(2, $captured);
        $this->assertSame(['type' => 'CLIENT_INVOICE', 'origin' => 'client_action', 'intent' => 'requested_link'], $captured[0]['metadata']['notification']);
        $this->assertSame(['type' => 'CLIENT_INVOICE'], $captured[1]['metadata']['notification']);
    }

    public function test_crm_follow_ups_keep_routine_timing_and_report_queued_delivery(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $captured = [];
        $this->captureMessages($captured);
        foreach (['thank_you', 'feedback', 'referral', 'rebook'] as $type) {
            $session = AiChatSession::create([
                'title' => 'CRM follow-up timing test',
                'user_id' => $admin->id, 'step' => 'send_follow_up',
                'state_data' => ['client_id' => $client->id, 'follow_up_type' => $type],
            ]);
            $result = app(ClientCrmFlow::class)->handle($session, 'Send the follow-up');
            $payload = end($captured);

            $this->assertSame('CRM_FOLLOW_UP', $payload['send_source']);
            $this->assertSame(['type' => 'CRM_FOLLOW_UP'], $payload['metadata']['notification']);
            $this->assertTrue(app(ClientNotificationTimingPolicy::class)->requiresQuietHours(Message::make([
                'direction' => 'OUTBOUND', 'send_source' => $payload['send_source'], 'metadata' => $payload['metadata'],
            ]), 'America/New_York'));
            $this->assertStringContainsString('Follow-up queued', $result['assistant_messages'][0]['content']);
            $this->assertFalse($result['assistant_messages'][0]['metadata']['email_sent']);
            $this->assertTrue($result['assistant_messages'][0]['metadata']['email_queued']);
        }
        $this->assertCount(4, $captured);
    }

    private function captureMessages(array &$captured): void
    {
        $messaging = Mockery::mock(MessagingService::class);
        foreach (['sendEmail', 'sendSms'] as $method) {
            $messaging->shouldReceive($method)->andReturnUsing(function (array $payload) use (&$captured) {
                $captured[] = $payload;

                return Message::make(['status' => 'SCHEDULED', 'channel' => 'EMAIL', 'to_address' => $payload['to']]);
            });
        }
        $this->app->instance(MessagingService::class, $messaging);
    }

    private function template(string $slug): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL', 'name' => $slug, 'slug' => $slug, 'category' => 'GENERAL',
            'subject' => 'Notification', 'body_html' => '<p>Hello {{recipient_first_name}}</p>',
            'body_text' => 'Hello {{recipient_first_name}}', 'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
        ]);
    }
}
