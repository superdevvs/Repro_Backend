<?php

namespace Tests\Feature;

use App\Models\{Category, Service, Shoot, ShootService, User};
use App\Services\Shoots\UploadIntakeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceInclusionsTest extends TestCase
{
    use RefreshDatabase;

    private function inclusions(): array
    {
        return [
            ['name' => 'HDR Photos', 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => true],
            ['name' => 'Basic Social Video', 'upload_intake_type' => 'video', 'uses_hdr_brackets' => false],
            ['name' => 'Zillow 3D with Floor Plans', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false],
        ];
    }

    public function test_admin_can_create_a_package_with_explicit_camera_permissions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/admin/services', [
            'name' => 'Premium bundle', 'price' => 450, 'delivery_time' => 24,
            'category_id' => Category::factory()->create()->id, 'included_components' => $this->inclusions(),
            // A caller cannot contradict the explicit components with a second capability field.
            'upload_intake_type' => 'none', 'uses_hdr_brackets' => false,
        ])->assertOk()->assertJsonPath('service.upload_intake_type', 'photo_video')
            ->assertJsonPath('service.uses_hdr_brackets', true)->assertJsonPath('service.included_components', $this->inclusions());
        $this->getJson('/api/admin/services')->assertOk()->assertJsonFragment(['name' => 'Basic Social Video']);
        $service = Service::findOrFail($response->json('service.id'));
        $this->assertSame($this->inclusions(), $service->included_components);
    }

    public function test_marking_an_existing_service_preserves_price_counts_tiers_and_assignments(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $service = Service::factory()->create(['upload_intake_type' => 'photo', 'price' => 450, 'photo_count' => 35]);
        $tier = $service->sqftRanges()->create(['sqft_from' => 1, 'sqft_to' => 2500, 'price' => 450, 'photo_count' => 35]);
        $item = ShootService::create(['shoot_id' => Shoot::factory()->create()->id, 'service_id' => $service->id,
            'price' => 400, 'quantity' => 1, 'photographer_id' => User::factory()->create(['role' => 'photographer'])->id]);
        $before = $service->fresh()->getAttributes();
        $itemBefore = $item->fresh()->getAttributes();
        $tierBefore = $tier->fresh()->getAttributes();
        $this->putJson('/api/admin/services/'.$service->id, ['included_components' => $this->inclusions()])
            ->assertOk()->assertJsonPath('data.upload_intake_type', 'photo_video');
        $after = $service->fresh()->getAttributes();
        foreach (['included_components', 'upload_intake_type', 'uses_hdr_brackets', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($itemBefore, $item->fresh()->getAttributes());
        $this->assertSame($tierBefore, $tier->fresh()->getAttributes());
        $resolver = app(UploadIntakeResolver::class);
        $this->assertTrue($resolver->supportsPhotoIntake($item->fresh('service')));
        $this->assertTrue($resolver->supportsVideoIntake($item->fresh('service')));
    }

    public function test_components_update_real_upload_batch_permissions_without_granting_another_photographer_access(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $service = Service::factory()->create(['included_components' => $this->inclusions()]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 400,
            'quantity' => 1, 'photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);
        $payload = ['type' => 'raw', 'batch_id' => 'included-video', 'total_files' => 1, 'service_id' => $item->id, 'upload_lane' => 'video'];
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', $payload)->assertOk();
        $service->update(['included_components' => [$this->inclusions()[0], $this->inclusions()[2]]]);
        $payload['batch_id'] = 'removed-video';
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', $payload)->assertStatus(422);
        $service->update(['included_components' => $this->inclusions()]);
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer']));
        $payload['batch_id'] = 'unassigned-video';
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', $payload)->assertForbidden();
    }

    public function test_unrelated_edits_preserve_legacy_unmarked_capabilities_and_marked_components(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([null, $this->inclusions()] as $components) {
            $service = Service::factory()->create(['included_components' => $components, 'upload_intake_type' => 'photo_video', 'uses_hdr_brackets' => true]);
            $this->putJson('/api/admin/services/'.$service->id, ['description' => 'Revised copy'])->assertOk();
            $this->assertSame($components, $service->fresh()->included_components);
            $this->assertSame('photo_video', $service->fresh()->upload_intake_type);
            $this->assertTrue($service->fresh()->uses_hdr_brackets);
        }
    }

    public function test_non_camera_components_and_explicit_empty_list_do_not_open_upload_lanes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $service = Service::factory()->create(['included_components' => $this->inclusions()]);
        foreach ([[$this->inclusions()[2]], []] as $components) {
            $this->putJson('/api/admin/services/'.$service->id, ['included_components' => $components])->assertOk()
                ->assertJsonPath('data.upload_intake_type', 'none')->assertJsonPath('data.uses_hdr_brackets', false);
        }
    }

    public static function invalidComponents(): array
    {
        $row = ['name' => 'Video', 'upload_intake_type' => 'video', 'uses_hdr_brackets' => false];
        return [
            'unknown lane' => [[array_replace($row, ['upload_intake_type' => 'anything'])]],
            'hdr without photos' => [[array_replace($row, ['uses_hdr_brackets' => true])]],
            'empty name' => [[array_replace($row, ['name' => ' '])]],
            'duplicate normalized name' => [[$row, array_replace($row, ['name' => ' VIDEO '])]],
            'unexpected permissions' => [[array_replace($row, ['role' => 'admin'])]],
            'null is not clearing' => [null],
            'too many rows' => [array_fill(0, 21, $row)],
        ];
    }

    #[DataProvider('invalidComponents')]
    public function test_invalid_components_are_rejected_without_mutation(?array $components): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $service = Service::factory()->create(['included_components' => $this->inclusions()]);
        $before = $service->fresh()->getAttributes();
        $this->putJson('/api/admin/services/'.$service->id, ['included_components' => $components])->assertUnprocessable();
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public static function nonManagers(): array
    {
        return [['client'], ['photographer'], ['editor'], ['salesRep']];
    }

    #[DataProvider('nonManagers')]
    public function test_non_managers_cannot_change_package_permissions(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $service = Service::factory()->create();
        $this->putJson('/api/admin/services/'.$service->id, ['included_components' => $this->inclusions()])->assertForbidden();
        $this->assertNull($service->fresh()->included_components);
    }
}
