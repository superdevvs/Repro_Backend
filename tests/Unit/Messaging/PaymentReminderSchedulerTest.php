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
        $result = (new PaymentReminderScheduler)->schedule($start, $start->addDays(7));

        $this->assertSame([
            '2026-01-02 10:00:00', // day +1
            '2026-01-04 10:00:00', // day +3
            '2026-01-08 10:00:00', // day +7
        ], $this->format($result));
    }

    public function test_weekly_reminders_continue_past_the_first_month(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        $result = (new PaymentReminderScheduler)->schedule($start, $start->addDays(56));

        $this->assertSame([
            '2026-01-02 10:00:00', // +1
            '2026-01-04 10:00:00', // +3
            '2026-01-08 10:00:00', // +7
            '2026-01-15 10:00:00', // +14
            '2026-01-22 10:00:00', // +21
            '2026-01-29 10:00:00', // +28
            '2026-02-05 10:00:00', // +35
            '2026-02-12 10:00:00', // +42
            '2026-02-19 10:00:00', // +49
            '2026-02-26 10:00:00', // +56
        ], $this->format($result));
    }

    public function test_default_never_switches_to_calendar_monthly_reminders(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:00:00');
        $result = (new PaymentReminderScheduler)->schedule($start, $start->addDays(120));
        $offsets = array_map(fn (CarbonImmutable $at) => (int) $start->diffInDays($at), $result);

        $this->assertSame([1, 3, 7], array_slice($offsets, 0, 3));
        foreach (array_slice($offsets, 3) as $index => $offset) {
            $this->assertSame(14 + $index * 7, $offset);
        }
        $this->assertNotContains('2026-02-22 09:00:00', $this->format($result));
    }

    public function test_results_are_ascending_and_within_horizon(): void
    {
        $start = CarbonImmutable::parse('2026-01-15 08:30:00');
        $horizon = CarbonImmutable::parse('2026-06-30 23:59:59');
        $result = (new PaymentReminderScheduler)->schedule($start, $horizon);

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
        $result = (new PaymentReminderScheduler)->schedule($start, $start->addHours(12));

        $this->assertSame([], $result);
    }

    public function test_saved_cadence_replaces_first_month_days_and_last_weekday_delivery_time(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 10:15:00', 'America/New_York');
        $result = (new PaymentReminderScheduler)->schedule($start, $start->addMonths(3), [
            'reminder_days' => [2, 10, 25],
            'monthly_day_of_week' => CarbonInterface::FRIDAY,
            'time' => '14:35',
        ]);

        $this->assertSame([
            '2026-01-03 10:15:00',
            '2026-01-11 10:15:00',
            '2026-01-26 10:15:00',
            '2026-02-27 14:35:00',
            '2026-03-27 14:35:00',
        ], $this->format($result));
        foreach ($result as $timestamp) {
            $this->assertSame('America/New_York', $timestamp->timezoneName);
        }
    }

    public function test_explicit_weekly_settings_match_new_default(): void
    {
        $scheduler = new PaymentReminderScheduler;
        $start = CarbonImmutable::parse('2026-07-15 11:45:00');
        $horizon = $start->addMonths(4);

        $this->assertSame($this->format($scheduler->schedule($start, $horizon)), $this->format($scheduler->schedule($start, $horizon, [
            'reminder_days' => [1, 3, 7],
            'repeat_after_day' => 7,
            'repeat_every_days' => 7,
        ])));
    }
}
