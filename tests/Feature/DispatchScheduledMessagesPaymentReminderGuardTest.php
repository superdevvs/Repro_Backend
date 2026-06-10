<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\Message;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Send-time guards for the DispatchScheduledMessages job (Req 12.14, 12.15).
 *
 * The cadence/upsert/cancel logic is covered by AutomationServicePaymentRemindersTest and
 * PaymentReminderStopOnPaidNoDuplicatePropertyTest. This file verifies the dispatcher itself:
 *   - a pending reminder for an already-paid shoot is SKIPPED and CANCELLED at send time, so a
 *     payment that landed after scheduling can never trigger a stale reminder (Req 12.14); and
 *   - a reminder row already marked `sent` for a (shoot_id, scheduled_date) is never picked up
 *     again, so a re-run cannot double-send (Req 12.15).
 */
class DispatchScheduledMessagesPaymentReminderGuardTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(): void
    {
        // The workflow-resume + scheduled-message paths are out of scope here; stub the executor
        // so the test exercises only the payment-reminder dispatch guard.
        $workflowExecutor = Mockery::mock(AutomationWorkflowExecutor::class);
        $workflowExecutor->shouldReceive('resumeDueSteps')->once();

        (new DispatchScheduledMessages())->handle(
            $this->app->make(MessagingService::class),
            $workflowExecutor,
            $this->app->make(AutomationService::class)
        );
    }

    public function test_due_reminder_for_paid_shoot_is_skipped_and_cancelled(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'paid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        // A pending reminder that came due before the payment was recorded.
        $reminder = PaymentReminder::create([
            'shoot_id' => $shoot->id,
            'scheduled_date' => '2026-01-02',
            'scheduled_at' => now()->subMinute(),
            'status' => PaymentReminder::STATUS_PENDING,
        ]);

        $this->runJob();

        $this->assertSame(
            PaymentReminder::STATUS_CANCELLED,
            $reminder->fresh()->status,
            'a due reminder for an already-paid shoot must be cancelled, not sent (Req 12.14)'
        );
        $this->assertNull($reminder->fresh()->sent_at);
        $this->assertSame(
            0,
            Message::where('related_shoot_id', $shoot->id)->count(),
            'no reminder message should be sent for a paid shoot'
        );
    }

    public function test_already_sent_reminder_is_not_resent_on_rerun(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        $sentAt = now()->subHour();
        $reminder = PaymentReminder::create([
            'shoot_id' => $shoot->id,
            'scheduled_date' => '2026-01-02',
            'scheduled_at' => now()->subMinute(),
            'status' => PaymentReminder::STATUS_SENT,
            'sent_at' => $sentAt,
        ]);

        $this->runJob();

        $fresh = $reminder->fresh();
        $this->assertSame(
            PaymentReminder::STATUS_SENT,
            $fresh->status,
            'an already-sent (shoot_id, scheduled_date) reminder must not be re-sent (Req 12.15)'
        );
        $this->assertSame(
            $sentAt->toDateTimeString(),
            $fresh->sent_at->toDateTimeString(),
            'sent_at must be unchanged — the row was not re-dispatched'
        );
        $this->assertSame(
            0,
            Message::where('related_shoot_id', $shoot->id)->count(),
            'no new reminder message should be created on re-run'
        );
    }
}
