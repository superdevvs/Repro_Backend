<?php

namespace Tests\Feature;

use App\Http\Controllers\API\DashboardController;
use App\Models\Shoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class DashboardLatestDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private function createShoot(array $attributes): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create(array_merge([
            'status' => 'delivered', 'workflow_status' => 'delivered',
            'scheduled_date' => '2026-09-01', 'completed_at' => '2026-09-20 10:00:00',
        ], $attributes)));
    }

    private function latest()
    {
        $method = new ReflectionMethod(DashboardController::class, 'latestDeliveredShoots');
        $method->setAccessible(true);
        return $method->invoke(new DashboardController());
    }

    public function test_latest_deliveries_are_selected_before_limiting_and_ignore_recent_import_updates(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $expected = [];
        for ($day = 22; $day <= 29; $day++) {
            $expected[] = $this->createShoot(['completed_at' => "2026-09-$day 10:00:00", 'updated_at' => '2026-09-29'])->id;
        }
        for ($i = 0; $i < 20; $i++) {
            $this->createShoot(['completed_at' => '2026-08-29', 'scheduled_date' => '2026-08-28', 'updated_at' => '2026-10-01']);
            $this->createShoot(['status' => 'ready', 'workflow_status' => 'ready', 'completed_at' => '2026-10-01']);
        }
        $this->assertSame(array_slice(array_reverse($expected), 0, 6), $this->latest()->pluck('id')->all());
        $this->assertSame(48, Shoot::count());
    }

    public function test_completion_wins_over_appointment_date_and_missing_dates_do_not_use_import_time(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $later = $this->createShoot(['scheduled_date' => '2026-08-01', 'completed_at' => '2026-09-30'])->id;
        $earlier = $this->createShoot(['scheduled_date' => '2026-09-29', 'completed_at' => '2026-09-29'])->id;
        $fallback = $this->createShoot(['completed_at' => null, 'scheduled_date' => '2026-08-01', 'updated_at' => '2026-10-01'])->id;
        $this->createShoot(['status' => 'cancelled', 'completed_at' => '2026-10-02']);
        $this->assertSame([$later, $earlier, $fallback], $this->latest()->pluck('id')->all());
    }
}
