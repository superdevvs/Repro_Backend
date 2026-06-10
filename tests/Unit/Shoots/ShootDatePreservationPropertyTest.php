<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Services\Shoots\ShootDateService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 2: Displayed calendar day equals scheduled calendar day
 *
 * Validates: Requirements 9.1, 9.2, 9.3, 9.4
 *
 * No property-based testing library is configured for the backend, so this test
 * follows the spec's "deterministic table-based parametric" approach: a
 * generator produces 30+ {instant, IANA timezone} tuples covering realistic
 * zones (America/New_York, America/Los_Angeles, UTC, Europe/London,
 * Australia/Sydney), near-midnight crossings in either direction, and DST
 * transitions. For every tuple the test asserts the universal property:
 *
 *   For any shoot scheduled at an instant in any timezone,
 *     ShootDateService::localCalendarDate(shoot)
 *       == instant.setTimezone(tz).format('Y-m-d')
 *   AND
 *     ShootDateService::toApi(shoot)['scheduled_date']
 *       == ShootDateService::localCalendarDate(shoot)
 *
 * That is, the displayed calendar day always equals the scheduled calendar day
 * in the shoot's own timezone, never UTC-shifted.
 */
class ShootDatePreservationPropertyTest extends TestCase
{
    private ShootDateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShootDateService();
    }

    /**
     * Deterministic generator of {utc_instant, timezone} tuples covering:
     *  - five realistic IANA zones spanning the UTC range
     *  - near-midnight crossings in either direction (instant just before/after
     *    the local midnight in each zone, where the local day differs from the
     *    UTC day)
     *  - DST spring-forward and fall-back transitions in zones that observe DST
     *  - midday "stable" instants (sanity baseline; UTC day == local day)
     *
     * Each entry returns [string $utcInstant, string $timezone, string $expectedYmd].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function timezoneInstantProvider(): array
    {
        $cases = [];

        // 1) Midday baselines per zone — the local day equals the UTC day after
        //    conversion. These are sanity rows; the property must still hold.
        $midday = [
            ['2026-01-15 12:00:00', 'UTC',                 '2026-01-15'],
            ['2026-01-15 17:00:00', 'America/New_York',    '2026-01-15'], // 12:00 EST
            ['2026-01-15 20:00:00', 'America/Los_Angeles', '2026-01-15'], // 12:00 PST
            ['2026-01-15 12:00:00', 'Europe/London',       '2026-01-15'], // 12:00 GMT
            ['2026-01-15 02:00:00', 'Australia/Sydney',    '2026-01-15'], // 13:00 AEDT
        ];
        foreach ($midday as $row) {
            $cases['midday: '.$row[1].' @ '.$row[0]] = $row;
        }

        // 2) Near-midnight crossings — instants where the LOCAL calendar day
        //    differs from the UTC day. The property fails loudly if the
        //    service ever falls back to formatting UTC.
        $crossings = [
            // UTC day = 2026-03-16, local NY day = 2026-03-15 (EDT, UTC-4).
            ['2026-03-16 03:30:00', 'America/New_York',    '2026-03-15'],
            // UTC day = 2026-07-04, local LA day = 2026-07-03 (PDT, UTC-7).
            ['2026-07-04 06:59:00', 'America/Los_Angeles', '2026-07-03'],
            // UTC day = 2026-12-31, local Sydney day = 2027-01-01 (AEDT, UTC+11).
            ['2026-12-31 14:30:00', 'Australia/Sydney',    '2027-01-01'],
            // UTC day = 2026-01-01 00:30, local NY day still 2025-12-31 (EST, UTC-5).
            ['2026-01-01 00:30:00', 'America/New_York',    '2025-12-31'],
            // UTC day = 2026-01-01 00:30, local LA day still 2025-12-31 (PST, UTC-8).
            ['2026-01-01 00:30:00', 'America/Los_Angeles', '2025-12-31'],
            // UTC day = 2026-06-30 23:30, local Sydney day = 2026-07-01 (AEST, UTC+10).
            ['2026-06-30 23:30:00', 'Australia/Sydney',    '2026-07-01'],
            // UTC = local in Europe/London during winter (GMT, UTC+0).
            ['2026-01-15 23:59:00', 'Europe/London',       '2026-01-15'],
            // UTC day = 2026-07-15 23:30, local London day = 2026-07-16 (BST, UTC+1).
            ['2026-07-15 23:30:00', 'Europe/London',       '2026-07-16'],
            // Just-before-midnight UTC, NY a few hours behind — same UTC day.
            ['2026-04-10 23:59:59', 'America/New_York',    '2026-04-10'],
            // Just-after-midnight UTC, Sydney already next day.
            ['2026-04-10 00:00:01', 'Australia/Sydney',    '2026-04-10'],
        ];
        foreach ($crossings as $row) {
            $cases['crossing: '.$row[1].' @ '.$row[0]] = $row;
        }

        // 3) DST transitions — the zone changes UTC offset. The property must
        //    still preserve the local calendar day on either side of the gap.
        $dst = [
            // US spring-forward 2026: 2026-03-08 02:00 EST → 03:00 EDT.
            // 06:30 UTC = 01:30 EST (before gap), local day still 2026-03-08.
            ['2026-03-08 06:30:00', 'America/New_York',    '2026-03-08'],
            // 07:30 UTC = 03:30 EDT (after gap), local day 2026-03-08.
            ['2026-03-08 07:30:00', 'America/New_York',    '2026-03-08'],
            // US fall-back 2026: 2026-11-01 02:00 EDT → 01:00 EST.
            // 04:30 UTC = 00:30 EDT, local day 2026-11-01.
            ['2026-11-01 04:30:00', 'America/New_York',    '2026-11-01'],
            // 06:30 UTC = 01:30 EST (after fall-back), local day 2026-11-01.
            ['2026-11-01 06:30:00', 'America/New_York',    '2026-11-01'],
            // Late on fall-back day (UTC) crosses to next NY day.
            ['2026-11-02 04:30:00', 'America/New_York',    '2026-11-01'],
            // EU spring-forward 2026: 2026-03-29 01:00 GMT → 02:00 BST.
            ['2026-03-29 00:30:00', 'Europe/London',       '2026-03-29'],
            ['2026-03-29 01:30:00', 'Europe/London',       '2026-03-29'], // BST now
            // EU fall-back 2026: 2026-10-25 02:00 BST → 01:00 GMT.
            ['2026-10-25 00:30:00', 'Europe/London',       '2026-10-25'], // BST
            ['2026-10-25 01:30:00', 'Europe/London',       '2026-10-25'], // ambiguous; PHP picks one consistently
            // AU fall-back 2026: 2026-04-05 03:00 AEDT → 02:00 AEST.
            ['2026-04-04 14:30:00', 'Australia/Sydney',    '2026-04-05'], // AEDT (+11)
            ['2026-04-04 16:30:00', 'Australia/Sydney',    '2026-04-05'], // AEST (+10)
            // AU spring-forward 2026: 2026-10-04 02:00 AEST → 03:00 AEDT.
            ['2026-10-03 14:30:00', 'Australia/Sydney',    '2026-10-04'], // AEST (+10)
            ['2026-10-03 16:30:00', 'Australia/Sydney',    '2026-10-04'], // AEDT (+11)
            // LA spring-forward 2026: 2026-03-08 02:00 PST → 03:00 PDT.
            ['2026-03-08 09:30:00', 'America/Los_Angeles', '2026-03-08'], // PST
            ['2026-03-08 10:30:00', 'America/Los_Angeles', '2026-03-08'], // PDT
            // LA fall-back 2026.
            ['2026-11-01 07:30:00', 'America/Los_Angeles', '2026-11-01'], // PDT
            ['2026-11-01 09:30:00', 'America/Los_Angeles', '2026-11-01'], // PST
        ];
        foreach ($dst as $row) {
            $cases['dst: '.$row[1].' @ '.$row[0]] = $row;
        }

        // 4) UTC zone — local day is always the UTC day, both midday and
        //    near-midnight. Ensures the property doesn't introduce drift in the
        //    no-conversion case.
        $utc = [
            ['2026-02-28 00:00:01', 'UTC', '2026-02-28'],
            ['2026-02-28 23:59:59', 'UTC', '2026-02-28'],
            ['2024-02-29 12:00:00', 'UTC', '2024-02-29'], // leap day
        ];
        foreach ($utc as $row) {
            $cases['utc: @ '.$row[0]] = $row;
        }

        return $cases;
    }

    /**
     * Property 2 (forward direction):
     * For any (utc instant, timezone), localCalendarDate equals the calendar
     * day computed by converting the instant to that zone and formatting Y-m-d.
     */
    #[Test]
    #[DataProvider('timezoneInstantProvider')]
    public function local_calendar_date_equals_instant_converted_to_zone(string $utcInstant, string $timezone, string $expectedYmd): void
    {
        // Independent oracle: convert the instant directly with Carbon, the
        // exact computation a viewer in that zone would perform.
        $oracleYmd = Carbon::parse($utcInstant, 'UTC')->setTimezone($timezone)->format('Y-m-d');

        // Sanity: the table value matches the oracle (catches typos in the
        // table, not service bugs).
        $this->assertSame(
            $expectedYmd,
            $oracleYmd,
            "Test table is wrong for {$utcInstant} in {$timezone}: expected {$expectedYmd}, oracle says {$oracleYmd}"
        );

        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse($utcInstant, 'UTC');
        $shoot->timezone = $timezone;

        $this->assertSame(
            $oracleYmd,
            $this->service->localCalendarDate($shoot),
            "localCalendarDate must equal the instant converted to {$timezone} and formatted Y-m-d, but it differs (instant {$utcInstant})"
        );
    }

    /**
     * Property 2 (serialization is faithful):
     * toApi()['scheduled_date'] equals localCalendarDate output exactly — the
     * serialized day is never UTC-shifted, even if scheduled_at carries a
     * different UTC day.
     */
    #[Test]
    #[DataProvider('timezoneInstantProvider')]
    public function to_api_scheduled_date_equals_local_calendar_date(string $utcInstant, string $timezone, string $expectedYmd): void
    {
        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse($utcInstant, 'UTC');
        $shoot->timezone = $timezone;

        $local = $this->service->localCalendarDate($shoot);
        $api = $this->service->toApi($shoot);

        $this->assertSame(
            $local,
            $api['scheduled_date'],
            "toApi()['scheduled_date'] must match localCalendarDate exactly (instant {$utcInstant}, tz {$timezone})"
        );
        $this->assertSame(
            $timezone,
            $api['timezone'],
            'toApi() must echo the shoot timezone so the client can render in the same zone'
        );

        // The absolute instant in the API payload must be the same instant we
        // started with — only the calendar-day projection is timezone-aware.
        $this->assertSame(
            Carbon::parse($utcInstant, 'UTC')->toIso8601String(),
            $api['scheduled_at'],
            'toApi() must serialize scheduled_at as the absolute UTC instant'
        );
    }

    /**
     * Property 2 (stable across `app.timezone` configuration):
     * The shoot's explicit `timezone` is authoritative — the local calendar
     * day must not shift when the configured `app.timezone` changes, because
     * the design stores explicit IANA timezone per shoot (Req 9.2) precisely
     * so the displayed day is independent of the deployment's default zone.
     *
     * Note: this varies `config('app.timezone')` (the design's fallback) but
     * does NOT mutate PHP's `date_default_timezone_set`, which would force
     * Eloquent's datetime cast to re-parse stored strings under a new zone —
     * that is a serialization concern, not the property under test.
     */
    #[Test]
    #[DataProvider('timezoneInstantProvider')]
    public function local_calendar_date_is_stable_across_app_timezone_config(string $utcInstant, string $timezone, string $expectedYmd): void
    {
        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse($utcInstant, 'UTC');
        $shoot->timezone = $timezone;

        $originalAppTz = config('app.timezone');
        try {
            $first = null;
            foreach (['UTC', 'America/New_York', 'Asia/Tokyo', 'Europe/London', 'Pacific/Auckland'] as $appTz) {
                config(['app.timezone' => $appTz]);

                $local = $this->service->localCalendarDate($shoot);
                $api = $this->service->toApi($shoot);

                $first ??= $local;

                $this->assertSame(
                    $expectedYmd,
                    $local,
                    "localCalendarDate must equal {$expectedYmd} regardless of app.timezone={$appTz} (shoot tz {$timezone})"
                );
                $this->assertSame(
                    $local,
                    $api['scheduled_date'],
                    "toApi() must mirror localCalendarDate under app.timezone={$appTz} (shoot tz {$timezone})"
                );
                $this->assertSame(
                    $first,
                    $local,
                    "localCalendarDate drifted when app.timezone changed to {$appTz} (shoot tz {$timezone})"
                );
            }
        } finally {
            config(['app.timezone' => $originalAppTz]);
        }
    }
}
