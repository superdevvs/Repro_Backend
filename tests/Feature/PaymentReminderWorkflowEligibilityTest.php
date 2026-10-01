<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\ShootPaymentReminderEligibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentReminderWorkflowEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 14:00:00');
        config(['app.timezone' => 'UTC']);
        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create(['type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'Test',
            'from_email' => 'hello@example.test', 'owner_scope' => 'GLOBAL', 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function delivered(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
            'delivery_status' => 'delivered', 'completed_at' => now(), 'shoot_ready_notified_at' => now(),
            'payment_status' => 'unpaid', 'total_quote' => 300,
        ], $overrides));
    }

    private function dispatch(): void
    {
        (new DispatchScheduledMessages)->handle(app(MessagingService::class), app(AutomationWorkflowExecutor::class), app(AutomationService::class));
    }

    public function test_delivery_and_accepted_ready_notice_are_required_and_first_reminder_waits_a_full_24_hours(): void
    {
        $shoot = $this->delivered(['completed_at' => now()->subHours(4)]);
        $service = app(AutomationService::class);
        $rows = $service->schedulePaymentReminders($shoot);
        $this->assertSame('2026-10-02 14:00:00', $rows[0]->scheduled_at->toDateTimeString());
        $this->assertSame(['2026-10-02', '2026-10-03', '2026-10-05', '2026-10-08', '2026-10-15'],
            collect($rows)->take(5)->map(fn ($row) => $row->scheduled_date->toDateString())->all());
        Carbon::setTestNow('2026-10-02 13:59:59');
        $this->assertNull($service->sendPaymentReminder($shoot->fresh()));
        $this->dispatch();
        $this->assertSame(0, Message::count());
        Carbon::setTestNow('2026-10-02 14:00:00');
        $this->dispatch();
        $this->dispatch();
        $this->assertSame(1, Message::where('channel', 'EMAIL')->count());
        $this->assertSame(PaymentReminder::STATUS_SENT, $rows[0]->fresh()->status);
    }

    public function test_stale_ready_timestamp_does_not_make_scheduled_partial_or_cancelled_shoots_eligible(): void
    {
        foreach ([
            ['status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED],
            ['delivery_status' => 'partial'],
            ['status' => 'cancelled'],
            ['shoot_ready_notified_at' => null],
        ] as $overrides) {
            $shoot = $this->delivered(array_merge(['completed_at' => now()->subDays(4), 'shoot_ready_notified_at' => now()->subDays(4)], $overrides));
            $pending = PaymentReminder::create(['shoot_id' => $shoot->id, 'scheduled_date' => now()->toDateString(),
                'scheduled_at' => now()->subMinute(), 'status' => PaymentReminder::STATUS_PENDING]);
            $this->assertSame([], app(AutomationService::class)->schedulePaymentReminders($shoot));
            $this->assertSame(PaymentReminder::STATUS_CANCELLED, $pending->fresh()->status);
            $this->assertNull(app(AutomationService::class)->sendPaymentReminder($shoot));
        }
        $this->dispatch();
        $this->assertSame(0, Message::count());
    }

    public function test_completion_later_than_old_ready_notice_resets_the_earliest_possible_reminder(): void
    {
        $shoot = $this->delivered(['shoot_ready_notified_at' => now()->subDays(2)]);
        $this->assertSame(now()->toDateTimeString(), app(ShootPaymentReminderEligibility::class)->reminderAnchor($shoot)->toDateTimeString());
        $rows = app(AutomationService::class)->schedulePaymentReminders($shoot);
        $this->assertSame(now()->addDay()->toDateTimeString(), $rows[0]->scheduled_at->toDateTimeString());
    }

    public function test_settled_ledger_stops_reminders_even_if_cached_status_says_unpaid(): void
    {
        $shoot = $this->delivered();
        app(AutomationService::class)->schedulePaymentReminders($shoot);
        Payment::factory()->create(['shoot_id' => $shoot->id, 'amount' => 300, 'status' => Payment::STATUS_COMPLETED]);
        $shoot->refresh();
        $this->assertSame([], app(AutomationService::class)->schedulePaymentReminders($shoot));
        $this->assertSame(0, $shoot->paymentReminders()->where('status', PaymentReminder::STATUS_PENDING)->count());
    }

    public function test_invoice_due_pipeline_suppresses_direct_pivot_and_item_shoot_links_even_with_mismatched_client(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email' => 'maryland@example.test']);
        $shoot = $this->delivered(['state' => 'NJ', 'completed_at' => now()->subDays(3), 'shoot_ready_notified_at' => now()->subDays(3)]);
        $this->assertNotSame($client->id, $shoot->client_id);
        foreach (['direct', 'pivot', 'item'] as $association) {
            $invoice = Invoice::create(['client_id' => $client->id, 'user_id' => $client->id, 'role' => Invoice::ROLE_CLIENT,
                'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
                'shoot_id' => $association === 'direct' ? $shoot->id : null,
                'status' => 'sent', 'due_date' => now()->toDateString(), 'issue_date' => now()->toDateString(),
                'total' => 300, 'total_amount' => 300, 'invoice_number' => 'TEST-'.$association]);
            if ($association === 'pivot') {
                $invoice->shoots()->attach($shoot->id);
            } elseif ($association === 'item') {
                InvoiceItem::create(['invoice_id' => $invoice->id, 'shoot_id' => $shoot->id,
                    'type' => InvoiceItem::TYPE_CHARGE, 'description' => 'Shoot', 'quantity' => 1, 'unit_amount' => 300, 'total_amount' => 300]);
            }
        }
        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(0, Message::count());
    }

    public function test_replayed_payment_workflow_cannot_send_to_previous_client_after_reassignment(): void
    {
        $shoot = $this->delivered(['completed_at' => now()->subDays(2), 'shoot_ready_notified_at' => now()->subDays(2)]);
        $context = app(AutomationService::class)->buildShootContext($shoot);
        $newClient = User::factory()->create(['role' => 'client']);
        $shoot->update(['client_id' => $newClient->id]);
        app(AutomationService::class)->handleEvent('SHOOT_PAYMENT_REMINDER', $context);
        $this->assertSame(0, Message::count());
    }

    public function test_stored_email_and_sms_retry_cancel_after_payment_or_client_reassignment(): void
    {
        foreach (['EMAIL', 'SMS'] as $channel) {
            foreach (['paid', 'reassigned', 'updated_address'] as $change) {
                $shoot = $this->delivered(['completed_at' => now()->subDays(2), 'shoot_ready_notified_at' => now()->subDays(2)]);
                $message = Message::create(['channel' => $channel, 'direction' => 'OUTBOUND', 'send_source' => 'AUTOMATION',
                    'provider' => $channel === 'EMAIL' ? 'LOCAL_SMTP' : 'TELNYX', 'status' => 'QUEUED',
                    'related_shoot_id' => $shoot->id, 'related_account_id' => $shoot->client_id,
                    'to_address' => $channel === 'EMAIL' ? $shoot->client->email : '+12025550111',
                    'tags_json' => ['PAYMENT_REMINDER:shoot:'.$shoot->id]]);
                Carbon::setTestNow(now()->addSecond());
                $shoot->update(match ($change) {
                    'paid' => ['payment_status' => 'paid'],
                    'reassigned' => ['client_id' => User::factory()->create()->id],
                    default => ['address' => 'Updated property'],
                });
                $service = app(MessagingService::class);
                $result = $channel === 'EMAIL' ? $service->dispatchStoredEmailMessage($message) : $service->dispatchStoredSmsMessage($message);
                $this->assertSame('CANCELLED', $result->status);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_cadence_migration_preserves_authored_settings_and_sent_history_without_backfill(): void
    {
        $rule = AutomationRule::where('trigger_type', 'SHOOT_PAYMENT_REMINDER')->firstOrFail();
        $old = ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7];
        $rule->update(['schedule_json' => $old, 'workflow_definition_json' => null]);
        $shoot = $this->delivered(['completed_at' => now()->subDays(2), 'shoot_ready_notified_at' => now()->subDays(2)]);
        $sent = PaymentReminder::create(['shoot_id' => $shoot->id, 'scheduled_date' => now()->subDay()->toDateString(),
            'scheduled_at' => now()->subDay(), 'sent_at' => now()->subDay(), 'status' => PaymentReminder::STATUS_SENT]);
        $obsolete = PaymentReminder::create(['shoot_id' => $shoot->id, 'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_at' => now()->addDay(), 'status' => PaymentReminder::STATUS_PENDING]);
        $authored = AutomationRule::create(['scope' => 'SYSTEM', 'trigger_type' => 'SHOOT_PAYMENT_REMINDER', 'name' => 'Shoot Payment Reminder',
            'is_active' => false, 'schedule_json' => ['reminder_days' => [2, 5]]]);
        $migration = require database_path('migrations/2026_10_01_100000_align_payment_reminders_to_delivery_workflow.php');
        $migration->up();
        $migration->up();
        $this->assertSame([1, 2, 4, 7], $rule->fresh()->schedule_json['reminder_days']);
        $this->assertSame(PaymentReminder::STATUS_SENT, $sent->fresh()->status);
        $this->assertSame(PaymentReminder::STATUS_CANCELLED, $obsolete->fresh()->status);
        $this->assertSame(0, $shoot->paymentReminders()->where('status', 'pending')->where('scheduled_at', '<', now())->count());
        $this->assertSame(['reminder_days' => [2, 5]], $authored->fresh()->schedule_json);
        $this->assertFalse($authored->fresh()->is_active);
    }

    public function test_migration_cancels_due_rows_from_earlier_today_instead_of_replaying_them(): void
    {
        $shoot = $this->delivered(['completed_at' => now()->subDays(2)->subHours(3), 'shoot_ready_notified_at' => now()->subDays(2)->subHours(3)]);
        $pending = PaymentReminder::create(['shoot_id' => $shoot->id, 'scheduled_date' => now()->toDateString(),
            'scheduled_at' => now()->subHours(3), 'status' => PaymentReminder::STATUS_PENDING]);
        $migration = require database_path('migrations/2026_10_01_100000_align_payment_reminders_to_delivery_workflow.php');
        $migration->up();
        $this->assertSame(PaymentReminder::STATUS_CANCELLED, $pending->fresh()->status);
        $this->dispatch();
        $this->assertSame(0, Message::count());
    }
}
