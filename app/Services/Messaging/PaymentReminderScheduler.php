<?php

namespace App\Services\Messaging;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure cadence calculator for automated Payment_Reminder messages (Req 12.11-12.13).
 *
 * The cadence anchor is the Shoot's `shoot_ready_notified_at` timestamp, passed as `$start`.
 * This class performs no I/O so it is directly unit- and property-testable. Persistence and
 * dispatch are handled by AutomationService and the DispatchScheduledMessages job.
 *
 * Cadence (measured from `shoot_ready_notified_at`):
 * - Phase 1 (fixed): day +1, +3, +7 from the start (day 1 = start + 1 day).
 * - Phase 2 (weekly, rest of first month): every 7 days after day 7 while within 30 days
 *   of the start (i.e. day 14, 21, 28).
 * - Phase 3 (monthly): after the first month, one reminder per month on the last Sunday
 *   of each month, at 09:00.
 */
class PaymentReminderScheduler
{
    /**
     * Compute the ascending list of Payment_Reminder timestamps for a shoot.
     *
     * @param  CarbonImmutable  $start  the shoot's shoot_ready_notified_at timestamp
     * @param  CarbonImmutable  $horizonEnd  the inclusive upper bound for scheduled reminders
     * @return list<CarbonImmutable> reminder timestamps, ascending, all <= $horizonEnd
     */
    public function schedule(CarbonImmutable $start, CarbonImmutable $horizonEnd, array $config = []): array
    {
        $out = [];

        // Defaults preserve the established first-month cadence. Saved rule
        // settings replace the list without changing the shoot-ready anchor.
        $days = array_values(array_unique(array_map('intval', array_filter(
            (array) ($config['reminder_days'] ?? [1, 3, 7, 14, 21, 28]),
            fn ($day) => is_numeric($day) && (int) $day >= 1 && (int) $day <= 30,
        ))));
        foreach ($days as $day) {
            $out[] = $start->addDays($day);
        }

        // Phase 3 (monthly): last Sunday of each month after the first month.
        $month = $start->addMonth()->startOfMonth();
        while ($month->lessThanOrEqualTo($horizonEnd)) {
            $out[] = $this->lastWeekdayOf($month, $config);
            $month = $month->addMonth()->startOfMonth();
        }

        $out = array_values(array_filter(
            $out,
            fn (CarbonImmutable $t) => $t->lessThanOrEqualTo($horizonEnd)
        ));

        // Keep results ascending regardless of phase generation order.
        usort($out, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $out;
    }

    /**
     * The last Sunday of the given month, at 09:00.
     */
    private function lastWeekdayOf(CarbonImmutable $month, array $config): CarbonImmutable
    {
        $end = $month->endOfMonth();
        $weekday = (int) ($config['monthly_day_of_week'] ?? CarbonInterface::SUNDAY);
        if ($weekday < CarbonInterface::SUNDAY || $weekday > CarbonInterface::SATURDAY) {
            $weekday = CarbonInterface::SUNDAY;
        }
        $time = (string) ($config['time'] ?? '09:00');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = '09:00';
        }
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $end
            ->subDays(($end->dayOfWeek - $weekday + 7) % 7)
            ->setTime($hour, $minute);
    }
}
