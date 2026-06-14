<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: per-shoot-notifications-payment-reminders, Task 5 — cancel reminders when payment lands.
 *
 * Validates: Requirements 5.3, 5.4
 *
 * The cadence already self-heals at dispatch time (DispatchScheduledMessages re-checks paid before
 * sending) and at schedule time (schedulePaymentReminders cancels pending rows for a paid shoot).
 * Task 5 adds a proactive seam: when a payment is recorded that flips a shoot to fully paid, the
 * pending PaymentReminder rows are cancelled immediately. The single central chokepoint that every
 * payment path (Stripe, Square, invoices, admin "mark as paid", public payment) funnels through is
 * `Shoot::syncPaymentStatusFromRecords()`, which is where the cancellation is wired.
 *
 * These tests record a real completed Payment that covers the balance and then sync the status,
 * asserting that pending reminders transition to cancelled while sent reminders are left intact.
 */
class PaymentReminderCancelOnPaidWiringTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationService
    {
        return app(AutomationService::class);
    }

    /**
     * Create an unpaid shoot with a ready anchor and a fixed quote.
     * ShootFactory defaults payment_status to 'paid', so 'unpaid' is set explicitly.
     */
    private function makeUnpaidShootWithReminders(float $quote = 500.0): Shoot
    {
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'name'  => 'Casey Client',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id'               => $client->id,
            'payment_status'          => 'unpaid',
            'total_quote'             => $quote,
            'shoot_ready_notified_at' => CarbonImmutable::parse('2026-01-01 10:00:00'),
        ]);

        // Start the cadence so there are pending reminders to cancel.
        $reminders = $this->service()->schedulePaymentReminders($shoot->fresh());
        $this->assertNotEmpty($reminders, 'expected the cadence to create pending reminders for an unpaid shoot');

        return $shoot;
    }

    public function test_recording_a_completed_full_payment_cancels_pending_reminders(): void
    {
        $shoot = $this->makeUnpaidShootWithReminders(500.0);

        $pendingBefore = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->count();
        $this->assertGreaterThan(0, $pendingBefore, 'sanity: there should be pending reminders before payment');

        // Record a completed payment that covers the full balance.
        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount'   => 500.0,
            'status'   => Payment::STATUS_COMPLETED,
        ]);

        // The payment-completion seam: every payment path funnels through this.
        $summary = $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('stripe');

        $this->assertSame('paid', $summary['payment_status'], 'the shoot should now be paid');

        // Req 5.3 / 5.4 — every previously-pending reminder is now cancelled.
        $stillPending = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->count();
        $this->assertSame(0, $stillPending, 'pending reminders must be cancelled once the shoot is paid');

        $cancelled = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_CANCELLED)
            ->count();
        $this->assertSame($pendingBefore, $cancelled, 'all formerly-pending reminders should be cancelled');
    }

    public function test_partial_payment_does_not_cancel_reminders(): void
    {
        $shoot = $this->makeUnpaidShootWithReminders(500.0);

        // A partial payment that does NOT cover the balance.
        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount'   => 100.0,
            'status'   => Payment::STATUS_COMPLETED,
        ]);

        $summary = $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('stripe');
        $this->assertSame('partial', $summary['payment_status']);

        // Reminders must remain pending while a balance is still owed (Req 5.4 only fires on paid).
        $stillPending = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->count();
        $this->assertGreaterThan(0, $stillPending, 'a partial payment must not cancel pending reminders');
    }

    public function test_already_sent_reminders_are_not_touched_when_payment_lands(): void
    {
        $shoot = $this->makeUnpaidShootWithReminders(500.0);

        // Mark one reminder as already sent; it must survive the cancellation sweep.
        $sent = PaymentReminder::where('shoot_id', $shoot->id)
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->orderBy('scheduled_date')
            ->first();
        $sent->status = PaymentReminder::STATUS_SENT;
        $sent->save();

        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount'   => 500.0,
            'status'   => Payment::STATUS_COMPLETED,
        ]);

        $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('stripe');

        $this->assertSame(
            PaymentReminder::STATUS_SENT,
            $sent->fresh()->status,
            'an already-sent reminder must remain sent after payment lands'
        );

        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)->count(),
            'no pending reminders should remain after the shoot is paid'
        );
    }
}
