<?php

namespace Tests\Feature;

use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persistence-side tests for AutomationService payment-reminder upsert/cancel (Req 12.14, 12.15).
 *
 * The pure cadence is exercised by PaymentReminderSchedulerTest; this file only verifies the
 * upsert (no duplicates) and stop-on-paid (cancel pending) behavior on top of the database.
 */
class AutomationServicePaymentRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationService
    {
        return app(AutomationService::class);
    }

    public function test_schedule_persists_one_row_per_reminder_date(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        $reminders = $this->service()->schedulePaymentReminders($shoot->fresh());

        $this->assertNotEmpty($reminders);
        $this->assertCount(
            count($reminders),
            PaymentReminder::where('shoot_id', $shoot->id)->get(),
            'persisted row count should match the scheduler output'
        );

        // All persisted rows are distinct by scheduled_date and start as pending.
        $rows = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertSame($rows->count(), $rows->pluck('scheduled_date')->unique()->count());
        foreach ($rows as $row) {
            $this->assertSame(PaymentReminder::STATUS_PENDING, $row->status);
        }
    }

    public function test_rerunning_scheduler_does_not_create_duplicate_rows(): void
    {
        // Req 12.15 — at most one Payment_Reminder per Shoot per scheduled reminder date.
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        $first = $this->service()->schedulePaymentReminders($shoot->fresh());
        $countAfterFirst = PaymentReminder::where('shoot_id', $shoot->id)->count();
        $this->assertSame(count($first), $countAfterFirst);

        // Run the scheduler again with the same anchor — no new rows should appear.
        $second = $this->service()->schedulePaymentReminders($shoot->fresh());
        $countAfterSecond = PaymentReminder::where('shoot_id', $shoot->id)->count();
        $this->assertSame($countAfterFirst, $countAfterSecond, 'duplicate rows were created on re-run');
        $this->assertSame(count($first), count($second));
    }

    public function test_rerun_preserves_already_sent_or_cancelled_status(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        $this->service()->schedulePaymentReminders($shoot->fresh());

        // Simulate that the day-1 reminder was already sent.
        $sent = PaymentReminder::where('shoot_id', $shoot->id)
            ->orderBy('scheduled_date')
            ->first();
        $sent->update(['status' => PaymentReminder::STATUS_SENT, 'sent_at' => now()]);

        // Re-run; the upsert must not flip the sent row back to pending.
        $this->service()->schedulePaymentReminders($shoot->fresh());

        $this->assertSame(
            PaymentReminder::STATUS_SENT,
            $sent->fresh()->status,
            'a previously sent reminder must not be resurrected to pending'
        );
    }

    public function test_paid_shoot_cancels_pending_and_schedules_nothing(): void
    {
        // Req 12.14 — completed payment stops further reminders and cancels pending ones.
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);
        $this->service()->schedulePaymentReminders($shoot->fresh());
        $this->assertGreaterThan(0, PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)->count());

        // Mark the shoot paid and re-run.
        $shoot->update(['payment_status' => 'paid']);
        $reminders = $this->service()->schedulePaymentReminders($shoot->fresh());

        $this->assertSame([], $reminders);
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)
                ->where('status', PaymentReminder::STATUS_PENDING)->count(),
            'all pending reminders should be cancelled when shoot is paid'
        );
    }

    public function test_cancel_payment_reminders_only_cancels_pending(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);
        $this->service()->schedulePaymentReminders($shoot->fresh());

        // Mark one as already sent — it must remain sent.
        $rows = PaymentReminder::where('shoot_id', $shoot->id)->orderBy('scheduled_date')->get();
        $rows->first()->update(['status' => PaymentReminder::STATUS_SENT, 'sent_at' => now()]);
        $expectedCancelled = $rows->count() - 1;

        $cancelled = $this->service()->cancelPaymentReminders($shoot->fresh());

        $this->assertSame($expectedCancelled, $cancelled);
        $this->assertSame(
            PaymentReminder::STATUS_SENT,
            $rows->first()->fresh()->status,
            'already sent reminders must not be cancelled'
        );
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)
                ->where('status', PaymentReminder::STATUS_PENDING)->count()
        );
    }

    public function test_no_anchor_means_no_reminders_scheduled(): void
    {
        $shoot = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => null,
        ]);

        $reminders = $this->service()->schedulePaymentReminders($shoot->fresh());

        $this->assertSame([], $reminders);
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->count());
    }
}
