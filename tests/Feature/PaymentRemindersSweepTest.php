<?php

namespace Tests\Feature;

use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Continuous weekly cadence via the rolling-horizon sweep (Req 4.6).
 *
 * Verifies that re-running scheduling on a recurring sweep keeps weekly
 * reminders rolling forward with no fixed 6-month stop, only ever persists future-dated rows
 * (never back-dating Day 1/3/7 for an old anchor), never creates duplicate
 * (shoot_id, scheduled_date) rows, and that the sweep command targets exactly the unpaid,
 * ready-notified shoots.
 *
 * Validates: Requirements 4.6
 */
class PaymentRemindersSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): AutomationService
    {
        return app(AutomationService::class);
    }

    private function unpaidAnchoredShoot(string $anchor): Shoot
    {
        // ShootFactory defaults payment_status to 'paid', so create unpaid shoots explicitly.
        return Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => Carbon::parse($anchor),
        ]);
    }

    /**
     * (a) For an unpaid shoot, repeated sweep runs at advancing "now" times keep the next two
     *     weekly reminders scheduled — the cadence does NOT stop at the old 6-month horizon.
     */
    public function test_repeated_sweeps_keep_weekly_reminders_scheduled_past_six_months(): void
    {
        $anchor = '2026-01-01 10:00:00';
        $shoot = $this->unpaidAnchoredShoot($anchor);

        // Initial scheduling at anchor time.
        Carbon::setTestNow($anchor);
        $this->service()->schedulePaymentReminders($shoot->fresh());

        // Find the first weekly date after now, measured from the original delivery anchor.
        $assertFutureWeeklyExists = function (string $whenContext) use ($anchor, $shoot): void {
            $now = Carbon::now();
            $nextAt = Carbon::parse($anchor)->addDays(7);
            while ($nextAt->lessThanOrEqualTo($now)) {
                $nextAt->addDays(7);
            }

            foreach ([$nextAt, $nextAt->copy()->addDays(7)] as $expected) {
                $row = PaymentReminder::where('shoot_id', $shoot->id)
                    ->where('scheduled_date', $expected->toDateString())
                    ->first();
                $this->assertNotNull($row, "expected a weekly reminder at {$expected} {$whenContext}");
                $this->assertSame(PaymentReminder::STATUS_PENDING, $row->status);
                $this->assertTrue($row->scheduled_at->equalTo($expected));
                $this->assertTrue($row->scheduled_at->greaterThan($now));
            }
        };

        // Advance well past the old 6-month cap (anchor + 5 months) and sweep.
        Carbon::setTestNow('2026-06-01 00:00:00');
        Artisan::call('messaging:payment-reminders-sweep');
        $assertFutureWeeklyExists('at 2026-06-01 (past the legacy 6-month horizon edge)');

        // Advance even further (anchor + 11 months) — beyond any 6-month stop — and sweep again.
        Carbon::setTestNow('2026-12-01 00:00:00');
        Artisan::call('messaging:payment-reminders-sweep');
        $assertFutureWeeklyExists('at 2026-12-01 (nearly a year after the anchor)');

        // And a full year+ later, still rolling forward.
        Carbon::setTestNow('2027-03-01 00:00:00');
        Artisan::call('messaging:payment-reminders-sweep');
        $assertFutureWeeklyExists('at 2027-03-01 (over a year after the anchor)');
    }

    /**
     * (b) A late re-run for an old anchor only creates future-dated rows — it never back-dates the
     *     Day 1/3/7 (or any past) reminders.
     */
    public function test_late_run_only_creates_future_dated_rows(): void
    {
        $anchor = '2026-01-01 10:00:00';
        $shoot = $this->unpaidAnchoredShoot($anchor);

        // Simulate a sweep first encountering this old anchor months later, with no prior rows.
        $now = '2026-06-01 00:00:00';
        Carbon::setTestNow($now);
        $this->service()->schedulePaymentReminders($shoot->fresh());

        $rows = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertNotEmpty($rows, 'a late run should still materialize upcoming weekly reminders');

        foreach ($rows as $row) {
            $this->assertTrue(
                $row->scheduled_at->greaterThanOrEqualTo(Carbon::parse($now)),
                "no back-dated row may be created; got {$row->scheduled_at} which is before now {$now}"
            );
        }

        // Specifically, none of the Phase 1/2 January dates were back-dated.
        $january = PaymentReminder::where('shoot_id', $shoot->id)
            ->whereBetween('scheduled_date', ['2026-01-01', '2026-01-31'])
            ->count();
        $this->assertSame(0, $january, 'no January (Day 1/3/7/weekly) rows should exist for an old anchor');
    }

    /**
     * (c) Repeated sweeps never create duplicate (shoot_id, scheduled_date) rows.
     */
    public function test_repeated_sweeps_create_no_duplicate_rows(): void
    {
        $shoot = $this->unpaidAnchoredShoot('2026-01-01 10:00:00');

        Carbon::setTestNow('2026-06-01 00:00:00');
        Artisan::call('messaging:payment-reminders-sweep');
        $countAfterFirst = PaymentReminder::where('shoot_id', $shoot->id)->count();

        // Re-run at the same "now": no new rows.
        Artisan::call('messaging:payment-reminders-sweep');
        $countAfterSecond = PaymentReminder::where('shoot_id', $shoot->id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'sweep re-run must not create duplicate rows');

        // Every persisted row is unique by scheduled_date.
        $rows = PaymentReminder::where('shoot_id', $shoot->id)->get();
        $this->assertSame(
            $rows->count(),
            $rows->pluck('scheduled_date')->map(fn ($d) => $d?->format('Y-m-d'))->unique()->count(),
            'persisted rows must be unique by (shoot_id, scheduled_date)'
        );
    }

    /**
     * (d) The sweep command schedules for unpaid, anchored shoots and skips paid / anchorless ones.
     */
    public function test_sweep_targets_only_unpaid_anchored_shoots(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');

        $unpaidAnchored = $this->unpaidAnchoredShoot('2026-01-01 10:00:00');

        $paidAnchored = Shoot::factory()->create([
            'payment_status' => 'paid',
            'shoot_ready_notified_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $unpaidNoAnchor = Shoot::factory()->create([
            'payment_status' => 'unpaid',
            'shoot_ready_notified_at' => null,
        ]);

        Artisan::call('messaging:payment-reminders-sweep');

        $this->assertGreaterThan(
            0,
            PaymentReminder::where('shoot_id', $unpaidAnchored->id)->count(),
            'unpaid anchored shoot should get reminders scheduled'
        );
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $paidAnchored->id)->count(),
            'paid shoot must not get reminders scheduled'
        );
        $this->assertSame(
            0,
            PaymentReminder::where('shoot_id', $unpaidNoAnchor->id)->count(),
            'shoot without a ready-notified anchor must not get reminders scheduled'
        );
    }
}
