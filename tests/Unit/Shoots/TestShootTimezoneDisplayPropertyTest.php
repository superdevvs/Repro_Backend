<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Services\ServiceAreaMatcher;
use App\Services\Shoots\ShootDateService;
use App\Services\Shoots\TestShoot\TestShootService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 20: Test_Shoot displays on its
 * scheduled calendar day in the region timezone.
 *
 * Validates: Requirements 10.11
 *
 * For any Test_Shoot scheduled for a calendar day in a region's timezone, the
 * date shown in the photographer's schedule is rendered in that region's
 * timezone, so the displayed calendar day equals the scheduled calendar day and
 * never shifts to the UTC day.
 *
 * The Test_Shoot reuses Req 9 logic: it carries an explicit IANA `timezone`
 * (the region's zone) and {@see ShootDateService::localCalendarDate()} /
 * {@see ShootDateService::toApi()} compute the local calendar day. This test
 * drives the real production path end to end — it creates the Test_Shoot via
 * {@see TestShootService::create()} (shoot_type = internal_test), persists it,
 * reads it back from the database, and asserts the displayed day via
 * ShootDateService.
 *
 * No property-based testing library is configured for the backend, so this test
 * follows the same deterministic-generator + seeded-PRNG approach used by
 * {@see ShootDatePreservationPropertyTest} and
 * {@see \Tests\Unit\Properties\ServiceAreaMatcherFilterPropertyTest}: a fixed
 * table of near-midnight / DST edge cases plus a seeded PRNG that produces 30+
 * randomized {region timezone, scheduled instant} cases, biased toward the
 * near-midnight window where the local day and the UTC day diverge. Each case
 * asserts the universal property.
 */
class TestShootTimezoneDisplayPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases; we generate more for coverage. */
    private const RANDOM_ITERATIONS = 40;

    /**
     * Realistic region timezones spanning the UTC offset range (west of UTC,
     * UTC, and east of UTC), including DST-observing zones in both hemispheres.
     *
     * @var list<string>
     */
    private array $regionTimezones = [
        'America/Los_Angeles', // UTC-8 / -7
        'America/Denver',      // UTC-7 / -6
        'America/Chicago',     // UTC-6 / -5
        'America/New_York',    // UTC-5 / -4
        'UTC',                 // UTC+0
        'Europe/London',       // UTC+0 / +1
        'Australia/Sydney',    // UTC+10 / +11
        'Asia/Tokyo',          // UTC+9 (no DST)
        'Pacific/Auckland',    // UTC+12 / +13
    ];

    private TestShootService $service;
    private ShootDateService $dates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dates = new ShootDateService();
        $this->service = new TestShootService(new ServiceAreaMatcher(), $this->dates);
    }

    /**
     * Property 20: a persisted Test_Shoot displays on its scheduled calendar day
     * in the region timezone, never the UTC day.
     */
    #[Test]
    public function test_shoot_displays_on_its_scheduled_day_in_the_region_timezone(): void
    {
        $cases = $this->generateCases();

        $this->assertGreaterThanOrEqual(
            25,
            count($cases),
            'Property 20 must exercise at least 25 timezone/instant cases.'
        );

        $sawDivergence = false;

        foreach ($cases as $label => [$timezone, $localDateTime]) {
            // The intended scheduled calendar day is the wall-clock day in the
            // region timezone (what an admin picks and a photographer sees).
            $localWhen = CarbonImmutable::parse($localDateTime, $timezone);
            $expectedRegionDay = $localWhen->format('Y-m-d');

            // The absolute instant the simulator stores, and the day it would
            // land on if naively rendered in UTC instead of the region timezone.
            // The production controller normalizes scheduled_at to UTC before
            // calling create() (see Admin\TestShootController::createTestShoot),
            // so the service receives a UTC instant — we mirror that contract.
            $utcInstant = $localWhen->setTimezone('UTC');
            $utcDay = $utcInstant->format('Y-m-d');

            // Create and persist the Test_Shoot through the production service.
            $shoot = $this->service->create(
                ['kind' => 'state', 'value' => 'NY'],
                $utcInstant,
                $timezone,
            );

            // Read it back from the database to prove the displayed day survives
            // the UTC round-trip Eloquent performs on datetime columns.
            $shoot->refresh();

            $this->assertSame(
                Shoot::SHOOT_TYPE_INTERNAL_TEST,
                $shoot->shoot_type,
                "[{$label}] the simulator must create an internal_test shoot"
            );

            // Core property: the displayed local calendar day equals the
            // scheduled calendar day in the region timezone.
            $this->assertSame(
                $expectedRegionDay,
                $this->dates->localCalendarDate($shoot),
                "[{$label}] localCalendarDate must equal the scheduled day {$expectedRegionDay} "
                ."in {$timezone} (instant {$utcInstant->toIso8601String()})"
            );

            // The serialized payload the schedule renders must mirror it and
            // echo the region timezone so the client renders in the same zone.
            $api = $this->dates->toApi($shoot);
            $this->assertSame(
                $expectedRegionDay,
                $api['scheduled_date'],
                "[{$label}] toApi()['scheduled_date'] must equal the region day {$expectedRegionDay}"
            );
            $this->assertSame(
                $timezone,
                $api['timezone'],
                "[{$label}] toApi() must echo the region timezone {$timezone}"
            );

            // When the region day and UTC day differ, the displayed day must be
            // the region day — proving it never shifts to the UTC day.
            if ($expectedRegionDay !== $utcDay) {
                $sawDivergence = true;
                $this->assertNotSame(
                    $utcDay,
                    $api['scheduled_date'],
                    "[{$label}] displayed day must not shift to the UTC day {$utcDay}"
                );
            }

            // The absolute instant is preserved unchanged (only the calendar-day
            // projection is timezone-aware).
            $this->assertSame(
                $utcInstant->toIso8601String(),
                Carbon::parse($api['scheduled_at'])->setTimezone('UTC')->toIso8601String(),
                "[{$label}] toApi() must preserve the absolute scheduled instant"
            );
        }

        $this->assertTrue(
            $sawDivergence,
            'Generator must include cases where the region day and UTC day differ, '
            .'otherwise the no-UTC-shift guarantee is untested.'
        );
    }

    /**
     * Fixed near-midnight / DST edge cases plus a seeded PRNG producing
     * RANDOM_ITERATIONS randomized {timezone, local wall-clock instant} cases.
     *
     * Each entry is [string $timezone, string $localDateTime] where
     * $localDateTime is a wall-clock time interpreted in $timezone.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function generateCases(): array
    {
        $cases = [];

        // 1) Deterministic edge cases: near-midnight crossings where the region
        //    day and UTC day diverge, plus DST transition days and a leap day.
        $fixed = [
            // 23:30 local in NY (EDT, UTC-4) => 03:30 next day UTC.
            ['America/New_York',    '2026-03-15 23:30:00'],
            // 23:59 local in LA (PDT, UTC-7) => next day UTC.
            ['America/Los_Angeles', '2026-07-03 23:59:00'],
            // 00:30 local just after midnight in Sydney (AEDT, UTC+11) => prior UTC day.
            ['Australia/Sydney',    '2027-01-01 00:30:00'],
            // 00:30 local in London (BST, UTC+1) => prior UTC day.
            ['Europe/London',       '2026-07-16 00:30:00'],
            // 01:00 local in Tokyo (UTC+9) => prior UTC day.
            ['Asia/Tokyo',          '2026-05-10 01:00:00'],
            // 00:15 local in Auckland (NZDT, UTC+13) => prior UTC day.
            ['Pacific/Auckland',    '2026-01-20 00:15:00'],
            // DST spring-forward day in NY (clocks jump 02:00 -> 03:00).
            ['America/New_York',    '2026-03-08 03:30:00'],
            // DST fall-back day in NY (clocks fall 02:00 -> 01:00).
            ['America/New_York',    '2026-11-01 01:30:00'],
            // Leap day, region day == UTC day baseline.
            ['UTC',                 '2024-02-29 12:00:00'],
            // Midday baseline (no divergence) — property must still hold.
            ['America/Chicago',     '2026-06-15 12:00:00'],
        ];
        foreach ($fixed as $i => $row) {
            $cases["fixed_{$i}: {$row[0]} @ {$row[1]}"] = $row;
        }

        // 2) Seeded PRNG so the generator is reproducible across runs; any
        //    failing iteration can be reproduced from the seed + case index.
        mt_srand(20260620);

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $timezone = $this->regionTimezones[mt_rand(0, count($this->regionTimezones) - 1)];

            // Random calendar day across a two-year window.
            $year = mt_rand(2025, 2027);
            $month = mt_rand(1, 12);
            $day = mt_rand(1, 28); // 28 keeps every month valid without overflow.

            // Bias toward the near-midnight window where the region day and the
            // UTC day are most likely to diverge, while still covering midday.
            $window = mt_rand(0, 3);
            [$hour, $minute] = match ($window) {
                0 => [mt_rand(0, 1), mt_rand(0, 59)],   // just after local midnight
                1 => [mt_rand(22, 23), mt_rand(0, 59)], // just before local midnight
                default => [mt_rand(0, 23), mt_rand(0, 59)], // anywhere in the day
            };

            $localDateTime = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
            $cases["random_{$i}: {$timezone} @ {$localDateTime}"] = [$timezone, $localDateTime];
        }

        return $cases;
    }
}
