<?php

namespace Tests\Feature;

use App\Jobs\NotifyShootDeliveryUpdate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\DeliveryPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShootZillowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ZILLOW_URL = 'https://www.zillow.com/view-imx/zillow-tour?setAttribution=mls&wl=true';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_owner_can_hide_each_public_variant_without_changing_the_saved_link_or_delivery_receipt(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $staff = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'scheduled_date' => now()->subDay()->toDateString(),
            'tour_links' => [
                'zillow_3d' => self::ZILLOW_URL, 'tour_style' => 'homeify',
                'embeds' => [
                    ['id' => 'zillow', 'mls' => self::ZILLOW_URL, 'branded' => '<iframe src="'.e(self::ZILLOW_URL).'"></iframe>'],
                    ['id' => 'other', 'mls' => 'https://example.test/tour', 'branded' => 'https://example.test/tour'],
                ],
            ],
        ]);
        $floorplanUrl = 'https://example.test/floorplan.png';
        ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'floorplan.png', 'stored_filename' => 'floorplan.png',
            'path' => $floorplanUrl, 'web_path' => $floorplanUrl, 'file_type' => 'image/png', 'file_size' => 10,
            'uploaded_by' => $staff->id, 'media_type' => 'floorplan', 'workflow_stage' => 'verified',
            'scan_status' => 'clean', 'metadata' => ['label' => 'Floor 1'],
        ]);
        $service = Service::factory()->noIntake()->create(['name' => 'Zillow 3D Home Tour']);
        $line = $shoot->serviceItems()->create([
            'service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready',
        ]);
        $planner = app(DeliveryPlanService::class);
        $selection = $planner->validateSelection($shoot, $staff, [
            'component_ids' => [$line->id.':tour'], 'mode' => 'external', 'confirmed' => true,
        ]);
        DB::transaction(fn () => $planner->commitSelection($shoot, $staff, $selection));
        $receipt = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole();
        Queue::fake();

        $public = '/api/public/shoots/'.$shoot->id.'/';
        $settings = '/api/shoots/'.$shoot->id.'/zillow-visibility';
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $this->getJson($public.$variant)->assertOk()->assertJsonPath('zillow_viewer.open_url', self::ZILLOW_URL);
        }

        $this->actingAs($client)->patchJson($settings, ['show_zillow_on_branded' => false])
            ->assertOk()->assertJsonPath('data.show_zillow_on_branded', false)->assertJsonPath('data.show_zillow_on_mls', true);
        $this->assertHiddenViewer($public.'branded');
        $this->getJson($public.'mls')->assertOk()->assertJsonPath('zillow_viewer.open_url', self::ZILLOW_URL);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_manage_zillow_visibility', true)
            ->assertJsonPath('data.tour_links.zillow_3d', self::ZILLOW_URL)->assertJsonPath('data.tour_links.show_zillow_on_branded', false);

        $this->patchJson($settings, ['show_zillow_on_mls' => false])->assertOk()
            ->assertJsonPath('data.show_zillow_on_branded', false)->assertJsonPath('data.show_zillow_on_mls', false);
        foreach (['branded', 'mls', 'g-mls'] as $variant) {
            $this->assertHiddenViewer($public.$variant);
        }

        $this->assertSame(self::ZILLOW_URL, $shoot->fresh()->tour_links['zillow_3d']);
        $this->assertEquals($receipt, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole());
        $this->assertSame('delivered', $planner->plan($shoot->fresh(), $staff)['components'][0]['status']);
        $this->assertSame('homeify', $shoot->fresh()->tour_links['tour_style']);
        Queue::assertNotPushed(NotifyShootDeliveryUpdate::class);

        $this->patchJson($settings, ['show_zillow_on_branded' => true])->assertOk()
            ->assertJsonPath('data.show_zillow_on_branded', true)->assertJsonPath('data.show_zillow_on_mls', false);
        $this->getJson($public.'branded')->assertOk()->assertJsonPath('zillow_viewer.open_url', self::ZILLOW_URL);
        $this->assertHiddenViewer($public.'mls');

        $this->actingAs($staff)->patchJson('/api/shoots/'.$shoot->id, [
            'tour_links' => ['tour_style' => 'neo', 'show_zillow_on_mls' => true],
        ])->assertOk();
        $this->assertSame('neo', $shoot->fresh()->tour_links['tour_style']);
        $this->assertFalse($shoot->fresh()->tour_links['show_zillow_on_mls']);
    }

    public function test_visibility_endpoint_rejects_nonowners_extra_fields_and_foreign_units(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $guest = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        $shoot->ghostUsers()->attach($guest->id);
        $foreignUnit = Shoot::factory()->create()->units()->create(['client_key' => 'foreign', 'label' => 'Foreign unit', 'kind' => 'unit']);
        $settings = '/api/shoots/'.$shoot->id.'/zillow-visibility';

        $this->patchJson($settings, ['show_zillow_on_branded' => false])->assertUnauthorized();
        $this->actingAs($guest)->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_manage_zillow_visibility', false);
        $this->patchJson($settings, ['show_zillow_on_branded' => false])->assertForbidden();
        $this->actingAs($client)->patchJson($settings, ['show_zillow_on_branded' => false, 'zillow_3d' => self::ZILLOW_URL])
            ->assertUnprocessable();
        $this->patchJson($settings, [])->assertUnprocessable();
        $this->patchJson($settings, ['show_zillow_on_branded' => null])->assertUnprocessable();
        $this->patchJson($settings, ['show_zillow_on_branded' => false, 'unit_id' => $foreignUnit->id])->assertNotFound();
        $this->patchJson($settings, ['show_zillow_on_branded' => false])->assertOk()
            ->assertJsonPath('data.show_zillow_on_branded', false)->assertJsonPath('data.show_zillow_on_mls', true);
        $this->assertArrayNotHasKey('zillow_3d', $shoot->fresh()->tour_links);
    }

    public function test_unit_visibility_is_independent_and_retains_the_unit_url(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'total_quote' => 0, 'payment_status' => 'paid', 'bypass_paywall' => true,
            'tour_links' => ['zillow_3d' => self::ZILLOW_URL],
        ]);
        $unit = $shoot->units()->create([
            'client_key' => 'unit-a', 'label' => 'Unit A', 'kind' => 'unit', 'tour_links' => ['zillow_3d' => self::ZILLOW_URL],
        ]);
        $shoot->serviceItems()->create([
            'service_id' => Service::factory()->create(['name' => 'Floor Plan'])->id, 'shoot_unit_id' => $unit->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'delivery_status' => 'ready',
        ]);

        $this->actingAs($client)->patchJson('/api/shoots/'.$shoot->id.'/zillow-visibility', [
            'unit_id' => $unit->id, 'show_zillow_on_branded' => false,
        ])->assertOk()->assertJsonPath('data.show_zillow_on_branded', false);
        $this->getJson('/api/public/shoots/'.$shoot->id.'/branded?unitId='.$unit->id)
            ->assertOk()->assertJsonPath('zillow_viewer', null)->assertJsonMissingPath('tour_links.zillow_3d');
        $this->getJson('/api/public/shoots/'.$shoot->id.'/mls?unitId='.$unit->id)
            ->assertOk()->assertJsonPath('zillow_viewer.open_url', self::ZILLOW_URL);
        $this->assertSame(self::ZILLOW_URL, $unit->fresh()->tour_links['zillow_3d']);
        $this->assertArrayNotHasKey('show_zillow_on_branded', $shoot->fresh()->tour_links);
    }

    private function assertHiddenViewer(string $path): void
    {
        $response = $this->getJson($path)->assertOk()->assertJsonPath('zillow_viewer', null)
            ->assertJsonMissingPath('tour_links.zillow_3d')->assertJsonMissingPath('tour_links.show_zillow_on_branded')
            ->assertJsonPath('floorplans.0.label', 'Floor 1')
            ->assertJsonPath('embeds.0.id', 'other')->assertJsonCount(1, 'embeds');
        $this->assertStringNotContainsString('zillow-tour', $response->getContent());
    }
}

