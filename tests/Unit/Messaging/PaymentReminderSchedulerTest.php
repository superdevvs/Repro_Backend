<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\PaymentReminderScheduler;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Tests\TestCase;

class PaymentReminderSchedulerTest extends TestCase
{
    private function format(array $timestamps): array
    {
        return array_map(fn (CarbonImmutable $t) => $t->toDateTimeString(), $timestamps);
    }

    public function test_phase_1_fixed_reminders_at_day_1_3_7(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        // Short horizon: only Phase 1 reminders.
        $result = (new PaymentReminderScheduler())->schedule($start, $start->addDays(7));

        $this->assertSame([
            '2026-01-02 10:00:00', // day +1
            '2026-01-04 10:00:00', // day +3
            '2026-01-08 10:00:00', // day +7
        ], $this->format($result));
    }

    public function test_phase_2_weekly_reminders_within_first_month(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        // Horizon at day 30 — Phase 1 + Phase 2 (day 14/21/28); no monthly yet.
        $result = (new PaymentReminderScheduler())->schedule($start, $start->addDays(30));

        $this->assertSame([
            '2026-01-02 10:00:00', // +1
            '2026-01-04 10:00:00', // +3
            '2026-01-08 10:00:00', // +7
            '2026-01-15 10:00:00', // +14
            '2026-01-22 10:00:00', // +21
            '2026-01-29 10:00:00', // +28
        ], $this->format($result));
    }

    public function test_phase_3_monthly_reminders_on_last_sunday_at_9am(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        // Horizon ~3 months out to capture monthly reminders.
        $result = (new PaymentReminderScheduler())->schedule($start, CarbonImmutable::parse('2026-04-30 23:59:59'));

        $formatted = $this->format($result);

        // Phase 3 begins the month AFTER the anchor month (Feb), one per month on last Sunday at 09:00.
        $this->assertContains('2026-02-22 09:00:00', $formatted); // last Sunday of Feb 2026
        $this->assertContains('2026-03-29 09:00:00', $formatted); // last Sunday of Mar 2026
        $this->assertContains('2026-04-26 09:00:00', $formatted); // last Sunday of Apr 2026

        // Each Phase 3 entry must actually be a Sunday at 09:00.
        foreach ($result as $t) {
            if ($t->format('H:i') === '09:00') {
                $this->assertSame(CarbonInterface::SUNDAY, $t->dayOfWeek, "Monthly reminder {$t->toDateTimeString()} is not a Sunday");
            }
        }
    }

    public function test_results_are_ascending_and_within_horizon(): void
    {
        $start = CarbonImmutable::parse('2026-01-15 08:30:00');
        $horizon = CarbonImmutable::parse('2026-06-30 23:59:59');
        $result = (new PaymentReminderScheduler())->schedule($start, $horizon);

        $this->assertNotEmpty($result);

        $previous = null;
        foreach ($result as $t) {
            $this->assertTrue($t->lessThanOrEqualTo($horizon), "Reminder {$t->toDateTimeString()} exceeds horizon");
            if ($previous !== null) {
                $this->assertTrue($t->greaterThanOrEqualTo($previous), 'Reminders are not ascending');
            }
            $previous = $t;
        }
    }

    public function test_empty_when_horizon_before_first_reminder(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        // Horizon before day +1 — nothing scheduled.
        $result = (new PaymentReminderScheduler())->schedule($start, $start->addHours(12));

        $this->assertSame([], $result);
    }
}
