<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Services\Shoots\ShootDurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StandaloneFloorPlanDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_standalone_catalogue_timing_changes_and_booked_snapshots_are_preserved(): void
    {
        $service = Service::factory()->create(['id' => 17, 'name' => '2D Floor plans', 'pricing_type' => 'variable', 'price' => 125, 'photographer_pay' => 40, 'shoot_duration_minutes' => 30, 'photographer_required' => true]);
        foreach ([30, 45, 60, 75, 90] as $index => $duration) {
            $service->sqftRanges()->create(['sqft_from' => $index * 2000 + 1, 'sqft_to' => ($index + 1) * 2000, 'price' => 125 + $index * 25, 'photographer_pay' => 40 + $index, 'duration' => $duration]);
        }
        $bundle = Service::factory()->create(['name' => '30 HDR Photos + 2D Floor plans', 'shoot_duration_minutes' => 60]);
        $shoot = Shoot::factory()->create(['property_details' => ['sqft' => 2500]]);
        $shoot->services()->attach(17, ['price' => 199, 'duration_minutes' => 60, 'quantity' => 1]);
        $legacy = Shoot::factory()->create(['property_details' => ['sqft' => 2500]]);
        $legacy->services()->attach(17, ['price' => 199, 'duration_minutes' => null, 'quantity' => 1]);
        $before = DB::table('shoot_service')->get()->map(fn ($row) => (array) $row)->all();
        $prices = $service->sqftRanges()->get()->map(fn ($row) => [$row->price, $row->photographer_pay])->all();
        $migration = require database_path('migrations/2026_10_07_110000_set_standalone_floor_plan_duration.php');
        $migration->up();
        $migration->up();
        $this->assertSame(5, $service->fresh()->getShootDurationMinutes(2500));
        $this->assertSame([5, 5, 5, 5, 5], $service->sqftRanges()->pluck('duration')->all());
        $this->assertSame($prices, $service->sqftRanges()->get()->map(fn ($row) => [$row->price, $row->photographer_pay])->all());
        $this->assertSame($before, DB::table('shoot_service')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(60, app(ShootDurationResolver::class)->forServiceItem($shoot->serviceItems()->sole()));
        $this->assertSame(45, app(ShootDurationResolver::class)->forServiceItem($legacy->serviceItems()->sole()));
        $this->assertSame(60, $bundle->fresh()->shoot_duration_minutes);
        $migration->down();
        $this->assertSame([30, 45, 60, 75, 90], $service->sqftRanges()->pluck('duration')->all());
    }
}
