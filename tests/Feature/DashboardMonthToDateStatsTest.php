<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardMonthToDateStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shoot(string $status, ?string $scheduledDate, ?string $scheduledAtUtc, ?string $completedAtUtc = null, ?string $timezone = 'America/New_York'): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'status' => $status,
            'workflow_status' => $status,
            'scheduled_date' => $scheduledDate,
            'scheduled_at' => $scheduledAtUtc,
            'timezone' => $timezone,
            'completed_at' => $completedAtUtc,
        ]));
    }

    public function test_overview_stats_count_the_current_new_york_month_only(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        // 2026-10-15 12:00 ET.
        Carbon::setTestNow(Carbon::parse('2026-10-15 16:00:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin']);

        // 00:30 ET on the 1st counts even though it is 04:30 UTC.
        $this->shoot('scheduled', '2026-10-01', '2026-10-01 04:30:00');
        // 23:30 ET on the last day of the previous month is already Oct 1 in UTC but must not count.
        $this->shoot('scheduled', '2026-09-30', '2026-10-01 03:30:00');
        // Declined and import drafts never count.
        $this->shoot(Shoot::STATUS_DECLINED, '2026-10-10', '2026-10-10 14:00:00');
        $this->shoot(Shoot::STATUS_IMPORT_DRAFT, '2026-10-10', '2026-10-10 14:00:00');
        // Cancelled and on-hold shoots count toward the monthly appointment total.
        $this->shoot(Shoot::STATUS_CANCELLED, '2026-10-05', '2026-10-05 14:00:00');
        $this->shoot(Shoot::STATUS_ON_HOLD, '2026-10-31', '2026-11-01 03:30:00');
        // A cancellation from the previous month is excluded.
        $this->shoot(Shoot::STATUS_CANCELLED, '2026-09-29', '2026-09-29 14:00:00');
        // Legacy row without scheduled_date falls back to scheduled_at.
        $this->shoot('scheduled', null, '2026-10-20 10:00:00', null, null);
        // Next month is excluded.
        $this->shoot('scheduled', '2026-11-01', '2026-11-01 14:00:00');
        // Delivered with completed_at in the previous ET month (23:59:59 ET Sep 30) is excluded.
        $this->shoot(Shoot::STATUS_DELIVERED, '2026-09-25', '2026-09-25 14:00:00', '2026-10-01 03:59:59');
        // Delivered exactly at 00:00 ET Oct 1, appointment in October: counts for both.
        $this->shoot(Shoot::STATUS_DELIVERED, '2026-10-02', '2026-10-02 14:00:00', '2026-10-01 04:00:00');
        // Delivered this month for an appointment last month: delivery only.
        $this->shoot(Shoot::STATUS_DELIVERED, '2026-09-28', '2026-09-28 14:00:00', '2026-10-05 12:00:00');
        // Not delivered, completed_at set: not a delivery.
        $this->shoot('editing', '2026-09-28', '2026-09-28 14:00:00', '2026-10-06 12:00:00');

        $stats = $this->actingAs($admin)
            ->getJson('/api/dashboard/overview')
            ->assertOk()
            ->assertJsonStructure(['data' => ['stats' => [
                'total_shoots', 'scheduled_today', 'flagged_shoots',
                'shoots_this_month', 'deliveries_this_month', 'cancelled_this_month',
                'month_start', 'month_end', 'timezone',
            ]]])
            ->json('data.stats');

        $this->assertSame(5, $stats['shoots_this_month']);
        $this->assertSame(2, $stats['deliveries_this_month']);
        $this->assertSame(1, $stats['cancelled_this_month']);
        $this->assertSame(13, $stats['total_shoots']);
        $this->assertSame('2026-10-01T00:00:00-04:00', $stats['month_start']);
        $this->assertSame('2026-10-31T23:59:59-04:00', $stats['month_end']);
        $this->assertSame('America/New_York', $stats['timezone']);
    }

    public function test_month_boundary_uses_new_york_time_not_utc(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        // 2026-11-01 02:00 UTC is still 22:00 ET on Oct 31.
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:00:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->shoot('scheduled', '2026-10-31', '2026-10-31 14:00:00');
        $this->shoot('scheduled', '2026-11-01', '2026-11-01 14:00:00');

        $stats = $this->actingAs($admin)->getJson('/api/dashboard/overview')->assertOk()->json('data.stats');

        $this->assertSame(1, $stats['shoots_this_month']);
        $this->assertSame('2026-10-01T00:00:00-04:00', $stats['month_start']);
    }
}
