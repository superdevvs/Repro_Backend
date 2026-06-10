<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_summary_counts_shoots_by_shoot_local_date_and_invalidates_old_bucket(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-06-08 12:00:00', 'UTC'));

        try {
            $admin = User::factory()->create(['role' => 'admin']);
            Sanctum::actingAs($admin);

            $shoot = Shoot::factory()->create([
                'status' => Shoot::STATUS_SCHEDULED,
                'workflow_status' => Shoot::STATUS_SCHEDULED,
                'scheduled_at' => Carbon::parse('2026-06-09 03:30:00', 'UTC'),
                'scheduled_date' => '2026-06-09',
                'time' => '03:30',
                'timezone' => 'America/Los_Angeles',
            ]);

            $this->getJson('/api/dashboard/schedule-summary')
                ->assertOk()
                ->assertJsonPath('data.reference_date', '2026-06-08')
                ->assertJsonPath('data.scheduled_today', 1)
                ->assertJsonPath('data.scheduled_tomorrow', 0);

            $this->getJson('/api/dashboard/overview')
                ->assertOk()
                ->assertJsonPath('data.stats.scheduled_today', 1);

            $shoot->forceFill([
                'timezone' => 'UTC',
                'scheduled_date' => '2026-06-09',
                'time' => '03:30',
            ])->save();

            $this->getJson('/api/dashboard/schedule-summary')
                ->assertOk()
                ->assertJsonPath('data.scheduled_today', 0)
                ->assertJsonPath('data.scheduled_tomorrow', 1);

            $this->getJson('/api/dashboard/overview')
                ->assertOk()
                ->assertJsonPath('data.stats.scheduled_today', 0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
