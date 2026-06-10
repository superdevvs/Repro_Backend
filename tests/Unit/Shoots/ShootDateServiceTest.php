<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Services\Shoots\ShootDateService;
use Carbon\Carbon;
use Tests\TestCase;

class ShootDateServiceTest extends TestCase
{
    private ShootDateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShootDateService();
    }

    public function test_local_calendar_date_uses_shoot_timezone_not_utc_day(): void
    {
        // 2026-03-16 03:30 UTC == 2026-03-15 23:30 America/New_York (EDT, UTC-4).
        // The local calendar day is the 15th, even though the UTC day is the 16th.
        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse('2026-03-16 03:30:00', 'UTC');
        $shoot->timezone = 'America/New_York';

        $this->assertSame('2026-03-15', $this->service->localCalendarDate($shoot));
    }

    public function test_to_api_preserves_local_calendar_day_across_timezones(): void
    {
        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse('2026-03-16 03:30:00', 'UTC');
        $shoot->timezone = 'America/New_York';

        $api = $this->service->toApi($shoot);

        $this->assertSame('2026-03-15', $api['scheduled_date']);
        $this->assertSame('America/New_York', $api['timezone']);
        // scheduled_at is the absolute instant serialized as ISO8601 (still 03:30 UTC).
        $this->assertNotNull($api['scheduled_at']);
        $this->assertSame(
            Carbon::parse('2026-03-16 03:30:00', 'UTC')->toIso8601String(),
            $api['scheduled_at']
        );
    }

    public function test_local_calendar_date_falls_back_to_scheduled_date_when_no_instant(): void
    {
        $shoot = new Shoot();
        $shoot->scheduled_date = '2026-07-04';
        $shoot->timezone = 'America/Los_Angeles';

        $this->assertSame('2026-07-04', $this->service->localCalendarDate($shoot));
    }

    public function test_timezone_defaults_to_app_timezone_when_absent(): void
    {
        config(['app.timezone' => 'UTC']);

        $shoot = new Shoot();
        $shoot->scheduled_at = Carbon::parse('2026-03-16 03:30:00', 'UTC');
        $shoot->timezone = null;

        // With UTC as the configured zone, the local day equals the UTC day.
        $this->assertSame('2026-03-16', $this->service->localCalendarDate($shoot));
        $this->assertSame('UTC', $this->service->toApi($shoot)['timezone']);
    }

    public function test_to_api_returns_null_scheduled_at_when_absent(): void
    {
        $shoot = new Shoot();
        $shoot->scheduled_date = '2026-01-15';
        $shoot->timezone = 'America/Chicago';

        $api = $this->service->toApi($shoot);

        $this->assertNull($api['scheduled_at']);
        $this->assertSame('2026-01-15', $api['scheduled_date']);
        $this->assertSame('America/Chicago', $api['timezone']);
    }
}
