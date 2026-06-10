<?php

namespace Tests\Feature;

use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 10: Completed payment stops reminders
 * and no duplicates are sent
 *
 * Validates: Requirements 12.14, 12.15
 *
 * For any cadence state — i.e. any anchor instant, any number of pre-runs of
 * the scheduler, and any timing of a payment completion — the following
 * universal invariants must hold:
 *
 *   (S1) **Stop-on-paid (Req 12.14).** When a shoot's `payment_status` is
 *        recorded as 'paid', `AutomationService::schedulePaymentReminders($shoot)`
 *        returns an empty list AND every previously-pending PaymentReminder row
 *        for the shoot transitions to 'cancelled'.
 *
 *   (S2) **No duplicates (Req 12.15).** Re-running schedulePaymentReminders
 *        with the same anchor produces no duplicate (shoot_id, scheduled_date)
 *        rows. The number of persisted rows after N >= 1 runs equals the number
 *        of unique scheduled dates produced by the cadence and is constant
 *        across re-runs.
 *
 *   (S3) **Sent rows are preserved on re-run (Req 12.15 corollary).**
 *        Re-running the scheduler after some rows have been marked 'sent' must
 *        not flip those rows back to 'pending'.
 *
 *   (S4) **Cancel respects status (Req 12.14 corollary).**
 *        `AutomationService::cancelPaymentReminders` only transitions 'pending'
 *        rows to 'cancelled'; rows already 'sent' remain 'sent'.
 *
 * Because no PHP property-based-testing library is configured for the backend,
 * the test follows the spec's "strong randomization plus deterministic edge
 * cases" approach: 25 randomized {anchor, pre-runs, sent-fraction, payment
 * timing} cases plus four deterministic edge cases:
 *
 *   E1 — run, then pay (cancel cascades on the next scheduler call).
 *   E2 — pay without ever running the scheduler (no rows to cancel; the
 *        scheduler still returns []).
 *   E3 — run, mark some rows sent, run again (no duplicate rows; sent rows
 *        preserved).
 *   E4 — run three times without paying (idempotent re-run).
 *
 * The same universal property must hold for every generated input.
 */
class PaymentReminderStopOnPaidNoDuplicatePropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 25;

    private function service(): AutomationService
    {
        return app(AutomationService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Seeded RNG so test runs are deterministic and any failure can be
        // reproduced exactly without losing the random-coverage benefit.
        mt_srand(20260619);
    }

    /**
     * Generator: 25 random + 4 deterministic edge cases.
     *
     * Each entry is [
     *   CarbonImmutable $anchor,
     *   int $preRuns,             // initial scheduler runs before any sent/paid mutation
     *   float $sentFraction,      // 0..1 — fraction of pending rows marked sent before re-run
     *   bool $payDuringSequence,  // whether to mark the shoot paid mid-sequence
     *   string $label,
     * ].
     *
     * Anchors span 2026 and 2027 so cadence schedules cover Phase 1 (day +1/3/7),
     * Phase 2 (weekly to day 28), and Phase 3 (monthly last-Sunday) without any
     * iteration falling entirely off-cadence. The internal horizon is a fixed
     * 6 months from the anchor (AutomationService::PAYMENT_REMINDER_HORIZON_MONTHS),
     * so any anchor in the 2-year window produces a non-trivial reminder set.
     *
     * @return array<string, array{0:CarbonImmutable,1:int,2:float,3:bool,4:string}>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        $base = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
        // Anchor offset window covers all of 2026 and 2027 (~2 years of minutes).
        $maxAnchorMinutes = 2 * 365 * 24 * 60 - 1;

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $anchor = $base->addMinutes(mt_rand(0, $maxAnchorMinutes));
            $preRuns = mt_rand(1, 3);                 // 1..3 initial scheduler runs
            $sentFraction = mt_rand(0, 100) / 100.0;  // 0..1 fraction marked sent
            $payDuring = (bool) mt_rand(0, 1);

            $cases["random_{$i}"] = [
                $anchor,
                $preRuns,
                $sentFraction,
                $payDuring,
                sprintf(
                    'random iter %d (preRuns=%d, sentFraction=%.2f, pay=%s)',
                    $i,
                    $preRuns,
                    $sentFraction,
                    $payDuring ? 'yes' : 'no'
                ),
            ];
        }

        // E1 — run, then pay.
        $cases['edge_run_then_pay'] = [
            CarbonImmutable::parse('2026-04-01 09:00:00', 'UTC'),
            1, 0.0, true,
            'E1: run, then pay (full cancel cascade)',
        ];

        // E2 — pay without ever running the scheduler.
        $cases['edge_pay_without_running'] = [
            CarbonImmutable::parse('2026-07-15 09:00:00', 'UTC'),
            0, 0.0, true,
            'E2: pay without ever running scheduler',
        ];

        // E3 — run, mark half sent, run again.
        $cases['edge_run_sent_rerun'] = [
            CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'),
            1, 0.5, false,
            'E3: run, mark half sent, re-run (no duplicates, sent preserved)',
        ];

        // E4 — run three times without paying.
        $cases['edge_run_rerun_no_pay'] = [
            CarbonImmutable::parse('2026-02-20 09:00:00', 'UTC'),
            3, 0.0, false,
            'E4: run three times without paying (idempotent)',
        ];

        return $cases;
    }

    /**
     * Property 10 — universal stop-on-paid + no-duplicate invariants.
     *
     * Validates: Requirements 12.14, 12.15
     */
    #[Test]
    public function stop_on_paid_and_no_duplicates_hold_for_all_cadence_states(): void
    {
        foreach ($this->casesGenerator() as $key => [$anchor, $preRuns, $sentFraction, $payDuring, $label]) {
            $context = sprintf(
                'case %s (anchor=%s UTC, label=%s)',
                $key,
                $anchor->toDateTimeString(),
                $label
            );

            // ----------------------------------------------------------------
            // Setup: a fresh unpaid shoot anchored to the random instant.
            // ----------------------------------------------------------------
            $shoot = Shoot::factory()->create([
                'payment_status' => 'unpaid',
                'shoot_ready_notified_at' => $anchor,
            ]);

            $rowsFor = fn () => PaymentReminder::where('shoot_id', $shoot->id)
                ->orderBy('scheduled_date')
                ->get();

            // ----------------------------------------------------------------
            // (S2) Pre-run the scheduler $preRuns times. The persisted row
            //      count must equal the number of unique scheduled dates and
            //      must stay constant across re-runs.
            // ----------------------------------------------------------------
            $firstRunCount = null;
            for ($r = 0; $r < $preRuns; $r++) {
                $reminders = $this->service()->schedulePaymentReminders($shoot->fresh());
                $persisted = $rowsFor();

                $uniqueDates = $persisted->pluck('scheduled_date')
                    ->map(fn ($d) => $d?->format('Y-m-d'))
                    ->unique()
                    ->values();

                $this->assertSame(
                    $persisted->count(),
                    $uniqueDates->count(),
                    "[S2] run #{$r}: persisted rows must be unique by (shoot_id, scheduled_date) for {$context}"
                );
                $this->assertSame(
                    count($reminders),
                    $persisted->count(),
                    "[S2] run #{$r}: scheduler return count must equal persisted row count for {$context}"
                );

                if ($firstRunCount === null) {
                    $firstRunCount = $persisted->count();
                } else {
                    $this->assertSame(
                        $firstRunCount,
                        $persisted->count(),
                        "[S2] run #{$r}: re-run must not add or drop any rows for {$context}"
                    );
                }
            }

            // Snapshot the persisted set after all pre-runs (may be empty when preRuns == 0).
            $afterPreRuns = $rowsFor();
            $pendingIds = $afterPreRuns
                ->where('status', PaymentReminder::STATUS_PENDING)
                ->pluck('id')
                ->all();

            // ----------------------------------------------------------------
            // (S3) Mark a fraction of pending rows as sent and re-run the
            //      scheduler. Sent rows must remain sent and the row count
            //      must stay the same (no duplicates, no resurrection).
            // ----------------------------------------------------------------
            $sentIds = [];
            if (!empty($pendingIds) && $sentFraction > 0) {
                $sentCount = (int) max(1, floor($sentFraction * count($pendingIds)));
                $sentIds = array_slice($pendingIds, 0, $sentCount);
                PaymentReminder::whereIn('id', $sentIds)->update([
                    'status' => PaymentReminder::STATUS_SENT,
                    'sent_at' => now(),
                ]);
            }

            if ($preRuns > 0) {
                $this->service()->schedulePaymentReminders($shoot->fresh());

                // (S2) row count stays the same on re-run.
                $this->assertSame(
                    $firstRunCount,
                    $rowsFor()->count(),
                    "[S2] re-run after marking some rows sent must not change the row count for {$context}"
                );

                // (S3) every row we marked sent is still sent.
                foreach ($sentIds as $id) {
                    $row = PaymentReminder::find($id);
                    $this->assertNotNull($row, "[S3] sent row id={$id} must still exist for {$context}");
                    $this->assertSame(
                        PaymentReminder::STATUS_SENT,
                        $row->status,
                        "[S3] sent row id={$id} must remain SENT after re-run for {$context}"
                    );
                }
            }

            // ----------------------------------------------------------------
            // (S1) When configured to pay, mark the shoot paid and run the
            //      scheduler again. It must return [] and cancel every
            //      previously-pending row. Already-sent rows remain sent.
            //
            // (S4) For cases that do not pay during the sequence, exercise
            //      cancelPaymentReminders directly so the cancel-respects-
            //      status invariant is independently verified.
            // ----------------------------------------------------------------
            if ($payDuring) {
                $shoot->update(['payment_status' => 'paid']);
                $afterPay = $this->service()->schedulePaymentReminders($shoot->fresh());

                $this->assertSame(
                    [],
                    $afterPay,
                    "[S1] schedulePaymentReminders on a paid shoot must return [] for {$context}"
                );

                $stillPending = PaymentReminder::where('shoot_id', $shoot->id)
                    ->where('status', PaymentReminder::STATUS_PENDING)
                    ->count();
                $this->assertSame(
                    0,
                    $stillPending,
                    "[S1] no PaymentReminder may remain PENDING after the shoot is paid for {$context}"
                );

                // Sent rows must stay sent through the cancel cascade.
                foreach ($sentIds as $id) {
                    $row = PaymentReminder::find($id);
                    $this->assertNotNull($row, "[S4] sent row id={$id} must still exist after pay for {$context}");
                    $this->assertSame(
                        PaymentReminder::STATUS_SENT,
                        $row->status,
                        "[S4] sent row id={$id} must remain SENT after stop-on-paid cancel for {$context}"
                    );
                }

                // Every row that was pending before payment must now be cancelled.
                foreach ($pendingIds as $id) {
                    if (in_array($id, $sentIds, true)) {
                        continue; // sent rows are not cancelled
                    }
                    $row = PaymentReminder::find($id);
                    $this->assertNotNull($row, "[S1] previously-pending row id={$id} must still exist after pay for {$context}");
                    $this->assertSame(
                        PaymentReminder::STATUS_CANCELLED,
                        $row->status,
                        "[S1] previously-pending row id={$id} must be CANCELLED after stop-on-paid for {$context}"
                    );
                }

                // Total row count is preserved by the cancel cascade (cancel
                // changes status, it does not delete rows).
                if ($preRuns > 0) {
                    $this->assertSame(
                        $firstRunCount,
                        PaymentReminder::where('shoot_id', $shoot->id)->count(),
                        "[S1] cancel cascade must preserve the persisted row count for {$context}"
                    );
                }
            } elseif ($preRuns > 0) {
                // (S4) cancelPaymentReminders applies only to pending rows.
                $beforeCancel = $rowsFor();
                $expectedCancelled = $beforeCancel
                    ->where('status', PaymentReminder::STATUS_PENDING)
                    ->count();

                $cancelled = $this->service()->cancelPaymentReminders($shoot->fresh());

                $this->assertSame(
                    $expectedCancelled,
                    $cancelled,
                    "[S4] cancelPaymentReminders must cancel exactly the pending row count ({$expectedCancelled}) for {$context}"
                );

                foreach ($sentIds as $id) {
                    $row = PaymentReminder::find($id);
                    $this->assertSame(
                        PaymentReminder::STATUS_SENT,
                        $row->status,
                        "[S4] sent row id={$id} must remain SENT after cancelPaymentReminders for {$context}"
                    );
                }

                $this->assertSame(
                    0,
                    PaymentReminder::where('shoot_id', $shoot->id)
                        ->where('status', PaymentReminder::STATUS_PENDING)
                        ->count(),
                    "[S4] no PaymentReminder may remain PENDING after cancelPaymentReminders for {$context}"
                );
            }

            // Detach from subsequent iterations. The (shoot_id, scheduled_date)
            // unique key is per-shoot, so a fresh shoot per case is sufficient
            // and the FK cascadeOnDelete drops the reminder rows for us.
            $shoot->delete();
        }
    }
}
