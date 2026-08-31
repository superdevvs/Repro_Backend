<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyReportingPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_payout_report_defaults_to_last_completed_sunday_through_saturday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/payout-report?role=photographer');

        $response->assertOk();
        $response->assertJsonPath('period.start', '2026-08-23');
        $response->assertJsonPath('period.end', '2026-08-29');
    }

    public function test_payout_report_expands_custom_dates_to_complete_reporting_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson(
            '/api/admin/payout-report?role=photographer&start=2026-08-31&end=2026-09-02'
        );

        $response->assertOk();
        $response->assertJsonPath('period.start', '2026-08-30');
        $response->assertJsonPath('period.end', '2026-09-05');
    }
}
