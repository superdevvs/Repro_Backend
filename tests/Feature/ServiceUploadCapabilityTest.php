<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\UploadIntakeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Upload capability is catalogue data, and it decides which lane may select a service.
 *
 * A commercial catalogue entry is not automatically an upload target. Fees, travel,
 * digital enhancements, floor plans and dedicated 3D-tour products stay bookable and
 * stay assignable to a photographer; they simply stop appearing as raw upload targets
 * and stop inventing raw expectations.
 */
class ServiceUploadCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name): Category
    {
        return Category::query()->firstOrCreate(['name' => $name]);
    }

    private function service(array $attributes): Service
    {
        return Service::query()->create(array_merge([
            'description' => 'Fixture',
            'price' => 100,
            'delivery_time' => 24,
            'pricing_type' => 'fixed',
        ], $attributes));
    }

    private function item(Shoot $shoot, Service $service, ?int $bracketMode = null): ShootService
    {
        return ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 100,
            'quantity' => 1,
            'bracket_mode' => $bracketMode,
        ]);
    }

    private function shoot(): Shoot
    {
        return Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'bracket_mode' => 5,
        ]);
    }

    public function test_the_capability_column_exists_and_defaults_to_not_selectable(): void
    {
        $this->assertTrue(Schema::hasColumn('services', 'upload_intake_type'));

        // Inserted without naming the column, as older code would. The default has to
        // be "not selectable" so an unclassified row is never silently uploadable.
        $id = DB::table('services')->insertGetId([
            'name' => 'Legacy Service',
            'description' => 'Legacy Service',
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $this->category('Photos')->id,
            'pricing_type' => 'fixed',
            'photographer_required' => 1,
            'allow_multiple' => 0,
            'exclude_from_sales_commission' => 0,
            'requires_editing' => 1,
            'photographer_pay_type' => 'fixed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            Service::INTAKE_NONE,
            DB::table('services')->where('id', $id)->value('upload_intake_type')
        );
        $this->assertSame(Service::INTAKE_NONE, Service::find($id)->uploadIntakeType());
    }

    public function test_an_unrecognised_capability_value_is_treated_as_not_selectable(): void
    {
        $service = $this->service([
            'name' => 'Corrupt Capability',
            'category_id' => $this->category('Photos')->id,
            'upload_intake_type' => 'somethingelse',
        ]);

        // Unknown must never mean "probably photo".
        $this->assertSame(Service::INTAKE_NONE, $service->uploadIntakeType());
        $this->assertFalse($service->supportsPhotoIntake());
        $this->assertFalse($service->supportsVideoIntake());
        $this->assertFalse($service->supportsAnyIntake());
    }

    public function test_each_capability_maps_to_the_lanes_it_declares(): void
    {
        $cases = [
            Service::INTAKE_PHOTO => [true, false],
            Service::INTAKE_VIDEO => [false, true],
            Service::INTAKE_PHOTO_VIDEO => [true, true],
            Service::INTAKE_NONE => [false, false],
        ];

        foreach ($cases as $intake => [$photo, $video]) {
            $service = $this->service([
                'name' => 'Capability '.$intake,
                'category_id' => $this->category('Photos')->id,
                'upload_intake_type' => $intake,
            ]);

            $this->assertSame($photo, $service->supportsIntakeLane(Service::LANE_PHOTO), $intake);
            $this->assertSame($video, $service->supportsIntakeLane(Service::LANE_VIDEO), $intake);
        }
    }

    public function test_the_backfill_only_applies_to_services_whose_identity_matches(): void
    {
        // Two rows share the id the backfill expects. One has the recorded identity,
        // the other has drifted. The predecessor of this migration shipped local-dev
        // identities, every check failed, and the whole catalogue silently stayed
        // unclassified in production — so this guard has to be exercised directly.
        $photos = $this->category('Photos')->id;

        DB::table('services')->insert([
            'id' => 1,
            'name' => '25 HDR Photos',
            'description' => '25 HDR Photos',
            'price' => 100,
            'delivery_time' => 24,
            'category_id' => $photos,
            'pricing_type' => 'fixed',
            'photo_count' => 25,
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
            'photographer_required' => 1,
            'allow_multiple' => 0,
            'exclude_from_sales_commission' => 0,
            'requires_editing' => 1,
            'photographer_pay_type' => 'fixed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('services')->insert([
            'id' => 17,
            // Drifted name: the catalogue no longer matches what was recorded.
            'name' => 'Renamed Floor Plans',
            'description' => 'Renamed Floor Plans',
            'price' => 125,
            'delivery_time' => 24,
            'category_id' => $this->category('Floor Plans')->id,
            'pricing_type' => 'variable',
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => false,
            'photographer_required' => 1,
            'allow_multiple' => 0,
            'exclude_from_sales_commission' => 0,
            'requires_editing' => 1,
            'photographer_pay_type' => 'fixed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_24_000002_backfill_service_upload_capabilities.php');
        $migration->up();

        // Matching identity: classified.
        $this->assertSame(Service::INTAKE_PHOTO, DB::table('services')->where('id', 1)->value('upload_intake_type'));
        $this->assertTrue((bool) DB::table('services')->where('id', 1)->value('uses_hdr_brackets'));

        // Drifted identity: left exactly as it was rather than mislabelled.
        $this->assertSame(Service::INTAKE_PHOTO, DB::table('services')->where('id', 17)->value('upload_intake_type'));
    }

    public function test_the_backfill_sets_the_counts_that_were_stranded_in_quantity(): void
    {
        // Drone counts lived in services.quantity, which never reaches the payload as a
        // count, so photo_count was null and the client fell back to booking quantity.
        DB::table('services')->insert([
            'id' => 15,
            'name' => '10-12 Drone Photos Package',
            'description' => '10-12 Drone Photos Package',
            'price' => 199,
            'delivery_time' => 24,
            'category_id' => $this->category('Drone')->id,
            'pricing_type' => 'fixed',
            'quantity' => 10,
            'photo_count' => null,
            'upload_intake_type' => Service::INTAKE_NONE,
            'uses_hdr_brackets' => false,
            'photographer_required' => 1,
            'allow_multiple' => 0,
            'exclude_from_sales_commission' => 0,
            'requires_editing' => 0,
            'photographer_pay_type' => 'fixed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_24_000002_backfill_service_upload_capabilities.php');
        $migration->up();

        $drone = Service::find(15);
        $this->assertSame(Service::INTAKE_PHOTO, $drone->uploadIntakeType());
        $this->assertFalse((bool) $drone->uses_hdr_brackets, 'drone capture is not exposure-stacked');
        $this->assertSame(10, $drone->contractedPhotoCount());
    }

    public function test_a_dedicated_tour_service_is_never_an_upload_target_or_a_raw_expectation(): void
    {
        $shoot = $this->shoot();
        $tour = $this->service([
            'name' => '3D Matterport w/ 2D Floor plans',
            'category_id' => $this->category('3D/360 Tours')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'photo_count' => 30,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($shoot, $tour);

        $intake = app(UploadIntakeResolver::class);
        $brackets = app(BracketModeResolver::class);

        $this->assertFalse($intake->supportsPhotoIntake($item));
        $this->assertFalse($intake->supportsVideoIntake($item));
        $this->assertSame([], $intake->eligibleItemsForLane($shoot->fresh(), Service::LANE_PHOTO)->all());

        // Even with a stray count and a stray bracket flag on the row, a non-intake
        // service owes nothing and has no bracket size.
        $this->assertSame(0, $brackets->expectedRawForService($item->fresh()->load('service')));
        $this->assertFalse($brackets->serviceUsesBrackets($item->fresh()->load('service')));
        $this->assertNull($brackets->effectiveBracketMode($item->fresh()->load('service')));
    }

    public function test_an_unspecified_count_is_reported_as_unknown_rather_than_guessed(): void
    {
        $shoot = $this->shoot();
        $variable = $this->service([
            'name' => 'HDR Photos',
            'category_id' => $this->category('Photos')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'photo_count' => 0,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($shoot, $variable)->fresh()->load('service');

        $brackets = app(BracketModeResolver::class);

        // Null, not 1 x 5 from the booked quantity, and not a silent 0 either.
        $this->assertNull($brackets->expectedRawForService($item));
        $this->assertTrue($brackets->expectedRawUnspecified($item));
        $this->assertFalse($brackets->expectedRawIsExactForShoot($shoot->fresh()));
    }

    /**
     * The shoot-77 arithmetic, end to end through the API payload.
     *
     * Production showed 65: the HDR service contributed a correct 10 x 5 = 50, then the
     * floor plan, virtual staging and drone rows each contributed 5, because a null
     * photo_count fell through to the pivot quantity of 1 and was multiplied by the
     * default bracket size. The correct total is 60.
     */
    public function test_shoot_77_regression_sixty_five_becomes_sixty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shoot();

        $hdr = $this->service([
            'name' => '10 Exterior HDR Photos',
            'category_id' => $this->category('Photos')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'photo_count' => 10,
            'uses_hdr_brackets' => true,
        ]);
        $floorPlan = $this->service([
            'name' => '2D Floor plans',
            'category_id' => $this->category('Floor Plans')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'photo_count' => null,
            'quantity' => null,
        ]);
        $staging = $this->service([
            'name' => 'Virtual Staging (per image)',
            'category_id' => $this->category('Virtual Staging')->id,
            'upload_intake_type' => Service::INTAKE_NONE,
            'photo_count' => null,
            'quantity' => 1,
        ]);
        $drone = $this->service([
            'name' => '10-12 Drone Photos Package',
            'category_id' => $this->category('Drone')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO,
            'uses_hdr_brackets' => false,
            'photo_count' => 10,
            'quantity' => 10,
        ]);

        $this->item($shoot, $hdr, 5);
        $this->item($shoot, $floorPlan);
        $this->item($shoot, $staging);
        $this->item($shoot, $drone);

        $brackets = app(BracketModeResolver::class);
        $fresh = $shoot->fresh();

        $this->assertSame(60, $brackets->expectedRawForShoot($fresh));
        $this->assertNotSame(65, $brackets->expectedRawForShoot($fresh));
        $this->assertTrue($brackets->expectedRawIsExactForShoot($fresh));

        $response = $this->actingAs($admin)->getJson('/api/shoots/'.$shoot->id);
        $response->assertOk();
        $this->assertSame(60, (int) ($response->json('data.expected_raw_count') ?? $response->json('expected_raw_count')));

        $services = collect($response->json('data.services') ?? $response->json('services') ?? [])->keyBy('name');

        // HDR: 10 finals at 5x.
        $this->assertSame(50, (int) $services['10 Exterior HDR Photos']['expected_raw_count']);

        // Drone: its real count, unmultiplied. Not zero, and not 1 x 5.
        $this->assertSame(10, (int) $services['10-12 Drone Photos Package']['expected_raw_count']);
        $this->assertSame(10, (int) $services['10-12 Drone Photos Package']['photo_count']);
        $this->assertFalse((bool) $services['10-12 Drone Photos Package']['uses_hdr_brackets']);

        // The two non-intake rows owe nothing and are not photo-capable.
        foreach (['2D Floor plans', 'Virtual Staging (per image)'] as $name) {
            $this->assertSame(Service::INTAKE_NONE, $services[$name]['upload_intake_type'], $name);
            $this->assertFalse((bool) $services[$name]['supports_photo_intake'], $name);
            $this->assertSame(0, (int) $services[$name]['expected_raw_count'], $name);
            $this->assertNull($services[$name]['photo_count'], $name);
        }
    }

    public function test_a_photo_video_bundle_serves_both_lanes_from_one_execution_row(): void
    {
        $shoot = $this->shoot();
        $bundle = $this->service([
            'name' => 'HDR Photos, Video & Premium iGuide',
            'category_id' => $this->category('Packages')->id,
            'upload_intake_type' => Service::INTAKE_PHOTO_VIDEO,
            'photo_count' => 30,
            'uses_hdr_brackets' => true,
        ]);
        $item = $this->item($shoot, $bundle, 5)->fresh()->load('service');

        $intake = app(UploadIntakeResolver::class);

        $this->assertTrue($intake->supportsPhotoIntake($item));
        $this->assertTrue($intake->supportsVideoIntake($item));
        $this->assertSame([], $intake->unsupportedLanes($item, [Service::LANE_PHOTO, Service::LANE_VIDEO]));

        // Its photo half still brackets at its own size; the iGuide half is delivered
        // by the dedicated provider workflow and is not an intake lane at all.
        $this->assertSame(150, app(BracketModeResolver::class)->expectedRawForService($item));
    }

    public function test_required_lanes_union_the_declared_lane_with_the_actual_files(): void
    {
        $intake = app(UploadIntakeResolver::class);

        $this->assertSame([Service::LANE_PHOTO], $intake->requiredLanes(null, []));
        $this->assertSame([Service::LANE_VIDEO], $intake->requiredLanes('video', []));
        // An unrecognised declaration is ignored rather than trusted.
        $this->assertSame([Service::LANE_PHOTO], $intake->requiredLanes('nonsense', []));
    }
}
