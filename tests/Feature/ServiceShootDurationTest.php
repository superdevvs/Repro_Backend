<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootUnit;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\Shoots\ShootDurationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceShootDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['availability.default_shoot_duration_minutes' => 60]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    private function catalogPayload(array $extra = []): array
    {
        return array_merge(['name' => '10 Exterior HDR Photos', 'price' => 100, 'delivery_time' => 48,
            'category_id' => Category::factory()->create()->id, 'photographer_required' => true], $extra);
    }

    public function test_catalog_duration_persists_round_trips_and_omitted_update_preserves_it(): void
    {
        $id = $this->postJson('/api/admin/services', $this->catalogPayload(['shoot_duration_minutes' => 90]))
            ->assertOk()->assertJsonPath('service.shoot_duration_minutes', 90)->json('service.id');
        $this->assertDatabaseHas('services', ['id' => $id, 'shoot_duration_minutes' => 90, 'delivery_time' => 48]);
        $this->getJson('/api/services/'.$id)->assertOk()->assertJsonPath('service.shoot_duration_minutes', 90);
        $row = collect($this->getJson('/api/services')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(90, $row['shoot_duration_minutes']);
        $this->getJson('/api/services/'.$id.'/calculate-price')->assertOk()
            ->assertJsonPath('shoot_duration_minutes', 90)->assertJsonPath('duration', 48);
        $this->putJson('/api/admin/services/'.$id, ['shoot_duration_minutes' => 30])
            ->assertOk()->assertJsonPath('data.shoot_duration_minutes', 30);
        $this->putJson('/api/admin/services/'.$id, ['description' => 'A catalog description'])
            ->assertOk()->assertJsonPath('data.shoot_duration_minutes', 30);
        $this->assertSame(30, Service::findOrFail($id)->getShootDurationMinutes());
    }

    public function test_missing_create_duration_persists_one_hour_but_legacy_null_serializes_without_backfill(): void
    {
        $id = $this->postJson('/api/admin/services', $this->catalogPayload())
            ->assertOk()->assertJsonPath('service.shoot_duration_minutes', 60)->json('service.id');
        $this->assertDatabaseHas('services', ['id' => $id, 'shoot_duration_minutes' => 60]);
        $legacy = Service::factory()->create(['shoot_duration_minutes' => null, 'delivery_time' => 168]);
        $this->getJson('/api/services/'.$legacy->id)->assertOk()->assertJsonPath('service.shoot_duration_minutes', 60);
        $this->assertNull(DB::table('services')->where('id', $legacy->id)->value('shoot_duration_minutes'));
        $this->assertSame(60, $legacy->getShootDurationMinutes());
    }

    public static function invalidDurations(): array
    {
        return ['too short' => [29], 'too long' => [241], 'zero' => [0], 'fraction' => [90.5], 'text' => ['later'], 'null' => [null]];
    }

    #[DataProvider('invalidDurations')]
    public function test_catalog_rejects_invalid_durations_on_create_and_update($duration): void
    {
        $this->postJson('/api/admin/services', $this->catalogPayload(['shoot_duration_minutes' => $duration]))
            ->assertUnprocessable()->assertJsonValidationErrors('shoot_duration_minutes');
        $service = Service::factory()->create(['shoot_duration_minutes' => 75]);
        $this->putJson('/api/admin/services/'.$service->id, ['shoot_duration_minutes' => $duration])
            ->assertUnprocessable()->assertJsonValidationErrors('shoot_duration_minutes');
        $this->assertSame(75, $service->fresh()->shoot_duration_minutes);
    }

    public function test_matching_sqft_duration_overrides_catalog_fallback_for_new_bookings(): void
    {
        $service = Service::factory()->create(['pricing_type' => 'variable', 'shoot_duration_minutes' => 30]);
        $service->sqftRanges()->create(['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 100, 'duration' => 90]);
        $this->assertSame(90, $service->getShootDurationMinutes(1500));
        $this->assertSame(30, $service->getShootDurationMinutes(2500));
        $this->assertSame(30, $service->getShootDurationMinutes());
        $this->getJson('/api/services/'.$service->id.'/calculate-price?sqft=1500')->assertOk()->assertJsonPath('shoot_duration_minutes', 90);
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);
        $id = $this->postJson('/api/shoots', ['address' => '100 Main Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'property_details' => ['sqft' => 1500], 'services' => [['id' => $service->id]]])->assertCreated()->json('data.id');
        $this->assertSame(90, Shoot::findOrFail($id)->serviceItems()->sole()->duration_minutes);
    }

    public function test_catalog_edit_does_not_move_legacy_or_explicit_bookings_and_existing_edit_retains_duration(): void
    {
        $service = Service::factory()->create(['shoot_duration_minutes' => null]);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => '2026-10-20 16:00:00', 'scheduled_date' => '2026-10-20', 'time' => '12:00', 'timezone' => 'America/New_York']);
        $shoot->services()->attach($service->id, ['duration_minutes' => null, 'photographer_id' => $photographer->id]);
        $resolver = app(ShootDurationResolver::class);
        $builder = app(GoogleCalendarEventPayloadBuilder::class);
        $before = $builder->build($shoot->fresh(), $photographer);
        $this->putJson('/api/admin/services/'.$service->id, ['shoot_duration_minutes' => 120])->assertOk();
        $this->assertNull($shoot->serviceItems()->sole()->duration_minutes);
        $this->assertSame(60, $resolver->forShoot($shoot->fresh()));
        $this->assertSame($before['end'], $builder->build($shoot->fresh(), $photographer)['end']);
        $this->assertSame(120, $resolver->forService($service->fresh()));
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.services.0.duration_minutes', 60)
            ->assertJsonPath('data.service_items.0.duration_minutes', 60)->assertJsonPath('data.services.0.shoot_duration_minutes', 120);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $service->id]]])->assertOk();
        $this->assertSame(60, $shoot->serviceItems()->sole()->duration_minutes);
        $shoot->serviceItems()->update(['duration_minutes' => 75]);
        $this->putJson('/api/admin/services/'.$service->id, ['shoot_duration_minutes' => 30])->assertOk();
        $this->assertSame(75, $resolver->forShoot($shoot->fresh()));
        $this->assertSame(75, $shoot->serviceItems()->sole()->duration_minutes);
    }

    public function test_legacy_unit_row_uses_its_tier_and_never_a_later_catalog_default(): void
    {
        $service = Service::factory()->create(['pricing_type' => 'variable', 'shoot_duration_minutes' => 240]);
        $service->sqftRanges()->create(['sqft_from' => 1, 'sqft_to' => 2000, 'price' => 100, 'duration' => 75]);
        $shoot = Shoot::factory()->create();
        $unit = ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => 'unit-one', 'label' => 'Unit 1', 'kind' => 'unit', 'sqft' => 1500]);
        $shoot->services()->attach($service->id, ['shoot_unit_id' => $unit->id, 'client_key' => 'line-one', 'duration_minutes' => null]);
        $item = $shoot->serviceItems()->sole();
        $this->assertSame(75, app(ShootDurationResolver::class)->forServiceItem($item));
        $data = $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->json('data');
        $this->assertSame(75, $data['service_items'][0]['duration_minutes']);
        $this->assertSame(75, $data['services'][0]['duration_minutes']);
        $this->assertNull($item->fresh()->duration_minutes);
    }
}
