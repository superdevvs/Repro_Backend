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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootContractedPhotoCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UploadIntakeResolver::flushColumnCache();
        BracketModeResolver::flushColumnCache();
    }

    public function test_single_property_booked_target_reaches_every_staff_role_in_the_detail_api(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'photographer_id' => $photographer->id,
            'property_details' => ['sqft' => 3943],
        ]);
        $hdr = $this->service(0, true);
        $hdr->update(['pricing_type' => 'variable']);
        $hdr->sqftRanges()->create(['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 275, 'photo_count' => 45]);
        $item = $this->item($shoot, $hdr, 45);
        $item->update(['photographer_id' => $photographer->id]);
        $tour = $this->service(null, false, Service::INTAKE_NONE);
        $tour->update(['name' => 'Zillow 3D w/Floor plans']);
        $this->item($shoot, $tour, null)->update(['photographer_id' => $photographer->id]);

        // This is the production failure shape: a valid booking snapshot with no unit.
        $this->assertNull($item->shoot_unit_id);
        $this->assertSame(45, app(UploadIntakeResolver::class)->contractedPhotoCount($item->fresh()));
        $this->assertSame(225, app(BracketModeResolver::class)->expectedRawForService($item->fresh()));
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep', 'photographer'] as $role) {
            $actor = $role === 'photographer' ? $photographer : User::factory()->create(['role' => $role]);
            Sanctum::actingAs($actor);
            $response = $this->getJson('/api/shoots/'.$shoot->id)->assertOk();
            foreach (['services', 'service_items'] as $key) {
                $services = collect($response->json('data.'.$key))->keyBy('name');
                $this->assertSame(45, $services['HDR Photos']['photo_count'], $role.' '.$key);
                $this->assertSame(225, $services['HDR Photos']['expected_raw_count'], $role.' '.$key);
                $this->assertFalse($services['HDR Photos']['expected_raw_unspecified']);
                $this->assertNull($services['Zillow 3D w/Floor plans']['photo_count']);
                $this->assertSame(0, $services['Zillow 3D w/Floor plans']['expected_raw_count']);
            }
        }
    }

    public function test_booked_snapshot_stays_fixed_when_catalogue_count_changes_and_quantity_scales_it(): void
    {
        $shoot = Shoot::factory()->create();
        $service = $this->service(25, true);
        $item = $this->item($shoot, $service, 45);
        $item->update(['quantity' => 2]);
        $service->update(['photo_count' => 65]);

        $this->assertSame(90, app(UploadIntakeResolver::class)->contractedPhotoCount($item->fresh()));
        $this->assertSame(450, app(BracketModeResolver::class)->expectedRawForService($item->fresh()));
    }

    public function test_legacy_catalogue_fallback_and_truly_unspecified_target_remain_distinct(): void
    {
        $shoot = Shoot::factory()->create();
        $known = $this->item($shoot, $this->service(25, true), null);
        $unknown = $this->item($shoot, $this->service(null, true), null);

        $this->assertSame(125, app(BracketModeResolver::class)->expectedRawForService($known));
        $this->assertNull(app(BracketModeResolver::class)->expectedRawForService($unknown));
        $this->assertTrue(app(BracketModeResolver::class)->expectedRawUnspecified($unknown));
    }

    public function test_legacy_variable_booking_uses_its_property_tier_without_inventing_a_target(): void
    {
        $shoot = Shoot::factory()->create(['property_details' => ['sqft' => 3943]]);
        $service = $this->service(0, true);
        $service->update(['pricing_type' => 'variable']);
        $service->sqftRanges()->create(['sqft_from' => 1, 'sqft_to' => 3000, 'price' => 199, 'photo_count' => 35]);
        $service->sqftRanges()->create(['sqft_from' => 3001, 'sqft_to' => 5000, 'price' => 275, 'photo_count' => 45]);
        $item = $this->item($shoot, $service, null);
        $resolver = app(BracketModeResolver::class);

        $this->assertSame(225, $resolver->expectedRawForService($item->fresh()));
        $this->assertSame(45, app(UploadIntakeResolver::class)->contractedPhotoCount($item->fresh()));
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/shoots/'.$shoot->id)->assertOk();
        $this->assertSame(45, $response->json('data.service_items.0.photo_count'));
        $this->assertSame(225, $response->json('data.service_items.0.expected_raw_count'));
        $this->assertNull($item->fresh()->contracted_photo_count, 'Reading a legacy target must not rewrite the booking');

        $shoot->update(['property_details' => ['sqft' => 9000]]);
        $this->assertNull($resolver->expectedRawForService($item->fresh()));
        $shoot->update(['property_details' => []]);
        $this->assertNull($resolver->expectedRawForService($item->fresh()));
    }

    public function test_a_unit_without_a_snapshot_does_not_borrow_the_main_property_target(): void
    {
        $shoot = Shoot::factory()->create(['property_details' => ['sqft' => 3943]]);
        $unit = $shoot->units()->create(['client_key' => 'unit-1', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 1200]);
        $item = $this->item($shoot, $this->service(45, true), null);
        $item->update(['shoot_unit_id' => $unit->id]);

        $this->assertNull(app(BracketModeResolver::class)->expectedRawForService($item->fresh()));
        $item->update(['contracted_photo_count' => 25]);
        $this->assertSame(125, app(BracketModeResolver::class)->expectedRawForService($item->fresh()));
    }

    private function service(?int $count, bool $hdr, string $intake = Service::INTAKE_PHOTO): Service
    {
        return Service::create([
            'name' => 'HDR Photos', 'description' => 'Count regression fixture', 'price' => 275,
            'delivery_time' => 24, 'category_id' => Category::firstOrCreate(['name' => 'Photography'])->id,
            'photo_count' => $count, 'uses_hdr_brackets' => $hdr, 'upload_intake_type' => $intake,
            'pricing_type' => 'fixed',
        ]);
    }

    private function item(Shoot $shoot, Service $service, ?int $count): ShootService
    {
        return ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 275,
            'quantity' => 1, 'bracket_mode' => $service->uses_hdr_brackets ? 5 : null,
            'contracted_photo_count' => $count,
        ]);
    }
}
