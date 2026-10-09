<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\User;
use App\Services\PayoutReportService;
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

    public function test_exact_payout_range_includes_both_calendar_boundaries_and_excludes_adjacent_earnings(): void
    {
        $this->createBoundaryCompensations();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/payout-report?role=photographer&start=2026-08-31&end=2026-09-02&exact_range=true')
            ->assertOk()
            ->assertJsonPath('period.start', '2026-08-31')
            ->assertJsonPath('period.end', '2026-09-02')
            ->assertJsonPath('totals.photographer_total', 30)
            ->assertJsonPath('photographers.0.shoot_count', 2);

        // The same dates retain the original weekly report behavior without opting in.
        $this->getJson('/api/admin/payout-report?role=photographer&start=2026-08-31&end=2026-09-02')
            ->assertOk()
            ->assertJsonPath('totals.photographer_total', 3030);
    }

    public function test_exact_payout_csv_matches_selected_dates_and_earnings(): void
    {
        $this->createBoundaryCompensations();
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->get('/api/admin/payout-report/download?role=photographer&start=2026-08-31&end=2026-09-02&exact_range=1')
            ->assertOk();
        $this->assertStringContainsString('payout-report-2026-08-31-to-2026-09-02.csv', $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('2026-08-31 - 2026-09-02', $csv);
        $this->assertStringContainsString('30.00', $csv);
        $this->assertStringNotContainsString('3030.00', $csv);
    }

    public function test_exact_payout_send_uses_selected_dates_without_expanding_week(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->mock(PayoutReportService::class, function ($mock) {
            $mock->shouldReceive('buildPhotographerSummaries')->once()->withArgs(fn (Carbon $start, Carbon $end) => $start->toDateTimeString() === '2026-08-31 00:00:00'
                && $end->toDateTimeString() === '2026-09-02 23:59:59'
            )->andReturn(collect());
        });

        $this->postJson('/api/admin/payout-report/send', ['role' => 'photographer', 'start' => '2026-08-31', 'end' => '2026-09-02', 'exact_range' => true])
            ->assertOk()->assertJsonPath('sent_count', 0);
    }

    public function test_payout_ranges_reject_reversed_and_overlong_dates_across_all_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        foreach ([['2026-09-02', '2026-08-31'], ['2024-01-01', '2025-01-01']] as [$start, $end]) {
            $query = "role=photographer&start={$start}&end={$end}&exact_range=true";
            $this->getJson('/api/admin/payout-report?'.$query)->assertUnprocessable()->assertJsonValidationErrors('end');
            $this->getJson('/api/admin/payout-report/download?'.$query)->assertUnprocessable()->assertJsonValidationErrors('end');
            $this->postJson('/api/admin/payout-report/send', ['role' => 'photographer', 'start' => $start, 'end' => $end, 'exact_range' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('end');
        }
    }

    public function test_exact_payout_range_requires_dates_and_accepts_a_full_leap_year(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/payout-report?role=photographer&exact_range=true')
            ->assertUnprocessable()->assertJsonValidationErrors(['start', 'end']);
        $this->getJson('/api/admin/payout-report?role=photographer&start=2024-01-01&end=2024-12-31&exact_range=true')
            ->assertOk()->assertJsonPath('period.start', '2024-01-01')->assertJsonPath('period.end', '2024-12-31');
    }

    private function createBoundaryCompensations(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        foreach (['2026-08-30 23:59:59' => 1000, '2026-08-31 00:00:00' => 10, '2026-09-02 23:59:59' => 20, '2026-09-03 00:00:00' => 2000] as $earnedAt => $amount) {
            $shoot = Shoot::factory()->create(['shoot_type' => Shoot::SHOOT_TYPE_STANDARD]);
            ShootCompensation::create([
                'shoot_id' => $shoot->id, 'scope_key' => 'shoot', 'recipient_type' => ShootCompensation::RECIPIENT_PHOTOGRAPHER,
                'recipient_user_id' => $photographer->id, 'mode' => ShootCompensation::MODE_CUSTOM, 'amount' => $amount,
                'earned_at' => $earnedAt, 'reason_code' => 'test_fixture', 'policy_version' => '1',
            ]);
        }
    }
}
