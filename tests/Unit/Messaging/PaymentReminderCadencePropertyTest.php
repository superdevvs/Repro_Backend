<?php

namespace Tests\Unit\Messaging;

use App\Services\Messaging\PaymentReminderScheduler;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Randomized boundaries for day 1, 3, 7 and the perpetual weekly cadence. */
class PaymentReminderCadencePropertyTest extends TestCase
{
    #[Test]
    public function cadence_is_day_one_three_seven_then_every_seven_days_for_random_anchors_and_horizons(): void
    {
        mt_srand(20260930);
        $scheduler = new PaymentReminderScheduler;
        $base = CarbonImmutable::parse('2026-01-01 00:00:00', 'America/New_York');
        $cases = [
            [$base, $base->addHours(12)],
            [$base, $base->addDay()],
            [$base, $base->addDays(7)],
            [$base, $base->addDays(28)],
            [CarbonImmutable::parse('2024-02-28 10:00:00', 'America/New_York'), CarbonImmutable::parse('2025-02-28 10:00:00', 'America/New_York')],
            [CarbonImmutable::parse('2026-12-31 23:00:00', 'America/New_York'), CarbonImmutable::parse('2027-06-30 23:00:00', 'America/New_York')],
        ];

        for ($i = 0; $i < 30; $i++) {
            $anchor = $base->addMinutes(mt_rand(0, 2 * 365 * 24 * 60));
            $horizon = $anchor->addDays(mt_rand(0, 540))->setTime(mt_rand(0, 23), mt_rand(0, 59));
            $cases[] = [$anchor, $horizon];
        }

        foreach ($cases as [$anchor, $horizon]) {
            $actual = $scheduler->schedule($anchor, $horizon);
            $expected = [];
            for ($day = 1; $anchor->addDays($day)->lessThanOrEqualTo($horizon); $day++) {
                if ($day === 1 || $day === 3 || ($day >= 7 && ($day - 7) % 7 === 0)) {
                    $expected[] = $anchor->addDays($day)->toIso8601String();
                }
            }

            $actualIso = array_map(fn (CarbonImmutable $at) => $at->toIso8601String(), $actual);
            $this->assertSame(
                $expected,
                $actualIso,
                'Cadence drift for '.$anchor->toIso8601String().' through '.$horizon->toIso8601String()
            );
            for ($i = 1; $i < count($actual); $i++) {
                $this->assertTrue($actual[$i]->greaterThan($actual[$i - 1]));
            }
        }
    }
}
