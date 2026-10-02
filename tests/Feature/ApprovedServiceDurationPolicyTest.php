<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Services\Shoots\ShootDurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovedServiceDurationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_180000_apply_approved_service_duration_policy.php');
    }

    private function service(int $id, string $name, array $extra = []): Service
    {
        return Service::factory()->create(array_merge([
            'id' => $id, 'name' => $name, 'shoot_duration_minutes' => null,
            'photographer_required' => true, 'price' => 185, 'photographer_pay' => 47,
        ], $extra));
    }

    private function ranges(Service $service, array $durations, array $counts = []): void
    {
        $service->update(['pricing_type' => 'variable']);
        foreach ($durations as $index => $duration) {
            $service->sqftRanges()->create([
                'sqft_from' => $index === 0 ? 1 : $index * 1500 + 1,
                'sqft_to' => ($index + 1) * 1500,
                'price' => 200 + $index * 50, 'photographer_pay' => 50 + $index * 10,
                'duration' => $duration, 'photo_count' => $counts[$index] ?? null,
            ]);
        }
    }

    public function test_approved_photo_and_bundle_tiers_preserve_bookings_prices_and_other_catalogue_fields(): void
    {
        $hdr = $this->service(2, 'HDR Photos');
        $this->ranges($hdr, [60, 60, 90, 120, 150], [25, 35, 45, 55, 65]);
        $iguideBundle = $this->service(91, 'HDR Photos + Video + iGuide*');
        $this->ranges($iguideBundle, [90, 90, 120, 150, 180], [25, 35, 45, 55, 65]);
        $matterportBundle = $this->service(93, 'HDR Photos + Video + Matterport*');
        $this->ranges($matterportBundle, [60, 90, 120, 180, 210], [35, 35, 45, 55, 65]);
        $inconsistent = $this->service(92, 'HDR Photos + iGuide*');
        $this->ranges($inconsistent, [60, 60, 90, 60, 60]);
        $shoot = Shoot::factory()->create(['base_quote' => 900, 'total_quote' => 954]);
        $shoot->services()->attach($hdr->id, ['quantity' => 2, 'price' => 450, 'duration_minutes' => 180]);
        $shootBefore = (array) DB::table('shoots')->where('id', $shoot->id)->first();
        $pivotBefore = (array) DB::table('shoot_service')->where('shoot_id', $shoot->id)->first();
        $servicesBefore = DB::table('services')->get()->map(fn ($row) => (array) $row)->all();
        $rangesBefore = DB::table('service_sqft_ranges')->get()->map(fn ($row) => (array) $row)->all();

        $this->migration()->up();

        $this->assertSame([30, 45, 60, 75, 90], $hdr->sqftRanges()->pluck('duration')->all());
        $this->assertSame([105, 120, 135, 165, 195], $iguideBundle->sqftRanges()->pluck('duration')->all());
        $this->assertSame([120, 150, 180, 240, 270], $matterportBundle->sqftRanges()->pluck('duration')->all());
        $this->assertSame([60, 75, 90, 105, 120], $inconsistent->sqftRanges()->pluck('duration')->all());
        $this->assertSame(30, $hdr->fresh()->getShootDurationMinutes());
        $this->assertSame(270, $matterportBundle->fresh()->getShootDurationMinutes(7000));
        $this->assertSame($shootBefore, (array) DB::table('shoots')->where('id', $shoot->id)->first());
        $this->assertSame($pivotBefore, (array) DB::table('shoot_service')->where('shoot_id', $shoot->id)->first());
        foreach ($servicesBefore as $row) {
            $current = (array) DB::table('services')->where('id', $row['id'])->first();
            unset($row['shoot_duration_minutes'], $current['shoot_duration_minutes']);
            $this->assertSame($row, $current);
        }
        foreach ($rangesBefore as $row) {
            $current = (array) DB::table('service_sqft_ranges')->where('id', $row['id'])->first();
            unset($row['duration'], $current['duration']);
            $this->assertSame($row, $current);
        }
    }

    public function test_zero_onsite_fees_and_small_addons_are_explicit_but_comped_historical_and_test_services_are_untouched(): void
    {
        $exterior = $this->service(6, '10 Exterior HDR Photos', ['shoot_duration_minutes' => 30]);
        $fee = $this->service(61, 'Travel fee - 60 miles', ['shoot_duration_minutes' => 75]);
        $digital = $this->service(238, 'Comp Green Grass', ['photographer_required' => false, 'shoot_duration_minutes' => 30]);
        $small = $this->service(77, '10 HDR Photos');
        $camera = $this->service(174, 'Agent on camera');
        $comped = $this->service(88, 'Comped Reshoot', ['shoot_duration_minutes' => 90]);
        $test = $this->service(175, 'laudantium at dolorem', ['shoot_duration_minutes' => 120]);
        $historical = $this->service(71, '10 Interior Photos Reshoot', ['is_migration_only' => true, 'shoot_duration_minutes' => 60]);
        $renamed = $this->service(3, 'Custom Operator Photography', ['shoot_duration_minutes' => 80]);

        $this->migration()->up();

        $this->assertSame(15, $exterior->fresh()->getShootDurationMinutes());
        $this->assertFalse($fee->fresh()->requiresPhotographer());
        $this->assertSame(0, $fee->fresh()->getShootDurationMinutes());
        $this->assertSame(0, $digital->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame(15, $small->fresh()->getShootDurationMinutes());
        $this->assertSame(15, $camera->fresh()->getShootDurationMinutes());
        $this->assertSame(90, $comped->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame(120, $test->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame(60, $historical->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame(80, $renamed->fresh()->getRawOriginal('shoot_duration_minutes'));
    }

    public function test_policy_is_idempotent_and_rollback_restores_only_its_own_still_current_changes(): void
    {
        $service = $this->service(19, 'Premium iGuide with Floor plans');
        $this->ranges($service, [60, 90]);
        $fee = $this->service(90, 'Onsite Cancellation/hold fee', ['shoot_duration_minutes' => 60]);
        $migration = $this->migration();
        $migration->up();
        $snapshotCount = DB::table('service_duration_policy_snapshots')->count();
        $service->update(['shoot_duration_minutes' => 47]);
        $secondTier = $service->sqftRanges()->orderBy('sqft_from')->get()->last();
        $secondTier->update(['duration' => 83]);
        $migration->up();
        $this->assertSame($snapshotCount, DB::table('service_duration_policy_snapshots')->count());
        $this->assertSame(47, $service->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame(83, $secondTier->fresh()->duration);
        $migration->down();
        $this->assertSame(47, $service->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertSame([60, 83], $service->sqftRanges()->pluck('duration')->all());
        $this->assertSame(60, $fee->fresh()->getRawOriginal('shoot_duration_minutes'));
        $this->assertTrue($fee->fresh()->requiresPhotographer());
    }

    public function test_large_interiors_gain_time_when_old_tiers_had_no_increment(): void
    {
        $floor = $this->service(17, '2D Floor plans');
        $this->ranges($floor, [30, 30, 30, 30, 30]);
        $luxury = $this->service(36, 'Luxury Highlight Video');
        $this->ranges($luxury, [null, null, null, null, null]);
        $showcase = $this->service(85, 'Zillow SHOWCASE Premium');
        $this->ranges($showcase, [60, 60, 60, 60, 60]);
        $this->migration()->up();
        $this->assertSame([15, 30, 45, 60, 75], $floor->sqftRanges()->pluck('duration')->all());
        $this->assertSame([75, 90, 105, 120, 135], $luxury->sqftRanges()->pluck('duration')->all());
        $this->assertSame([90, 105, 120, 135, 150], $showcase->sqftRanges()->pluck('duration')->all());
    }

    public function test_old_null_booking_durations_use_prior_tiers_and_fee_requirement_without_rewriting_rows(): void
    {
        $service = $this->service(19, 'Premium iGuide with Floor plans');
        $this->ranges($service, [60, 90]);
        $fee = $this->service(61, 'Travel fee - 60 miles', ['shoot_duration_minutes' => 90]);
        $digital = $this->service(238, 'Comp Green Grass', ['shoot_duration_minutes' => 30, 'photographer_required' => false]);
        $shoot = Shoot::factory()->create(['property_details' => ['sqft' => 1500]]);
        $shoot->services()->attach($service->id, ['duration_minutes' => null]);
        $item = $shoot->serviceItems()->sole();
        $before = app(ShootDurationResolver::class)->forServiceItem($item);
        $this->assertSame(60, $before);
        $beforeMigration = now()->subDay();
        $afterMigration = now()->addDay();

        $this->migration()->up();

        $this->assertSame(30, $service->fresh()->getShootDurationMinutes(1500));
        $this->assertSame($before, app(ShootDurationResolver::class)->forServiceItem($item->fresh()));
        $this->assertSame(60, $service->fresh()->getShootDurationMinutes(1500, false));
        $this->assertSame(90, $service->fresh()->getShootDurationMinutes(2500, false));
        $this->assertNull($item->fresh()->duration_minutes);
        $this->assertFalse($fee->fresh()->requiresPhotographer());
        $this->assertTrue($fee->fresh()->requiresPhotographerForBooking($beforeMigration));
        $this->assertFalse($fee->fresh()->requiresPhotographerForBooking($afterMigration));
        $this->assertFalse($digital->fresh()->requiresPhotographerForBooking($beforeMigration));
        $this->assertSame(60, $fee->fresh()->getShootDurationMinutes(null, false));
        $this->assertSame(0, $fee->fresh()->getShootDurationMinutes());
    }
}
