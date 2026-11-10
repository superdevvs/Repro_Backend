<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\DeliveryComponentRequirements;
use App\Services\Shoots\DeliveryPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IguideBundledFloorplanDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Storage::fake('public');
    }

    private function requirements(string $name, array $extra = []): array
    {
        $service = new Service;
        $service->forceFill(['name' => 'Booked package', 'included_components' => array_merge([
            ['name' => 'HDR Photos', 'upload_intake_type' => 'photo'],
            ['name' => $name, 'upload_intake_type' => 'none'],
        ], $extra)]);
        $item = new ShootService;
        $item->forceFill(['is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        $item->setRelation('service', $service);

        return app(DeliveryComponentRequirements::class)->forItem($item);
    }

    public static function providers(): array
    {
        return [
            'premium iGuide bundle' => ['Premium iGuide Tour', ['photo', 'floorplan', 'tour']],
            'iGuide bundle' => ['iGuide Tour', ['photo', 'floorplan', 'tour']],
            'Matterport tour only' => ['Matterport Tour', ['photo', 'tour']],
            'Zillow tour only' => ['Zillow 3D Tour', ['photo', 'tour']],
        ];
    }

    #[DataProvider('providers')]
    public function test_booked_provider_inclusions_have_their_own_requirements(string $name, array $kinds): void
    {
        $requirements = $this->requirements($name);
        $this->assertSame($kinds, array_column($requirements, 'kind'));
        if (str_contains($name, 'iGuide')) {
            $this->assertSame('iguide', collect($requirements)->firstWhere('kind', 'floorplan')['provider']);
        }
    }

    public function test_an_explicit_floorplan_inclusion_does_not_duplicate_the_iguide_requirement(): void
    {
        $requirements = $this->requirements('Premium iGuide Tour', [['name' => 'Floor Plans', 'upload_intake_type' => 'none']]);
        $this->assertEqualsCanonicalizing(['photo', 'floorplan', 'tour'], array_column($requirements, 'kind'));
        $this->assertSame('iguide', collect($requirements)->firstWhere('kind', 'floorplan')['provider']);
    }

    public function test_existing_snapshots_and_historical_deliveries_are_not_rewritten_by_catalog_inference(): void
    {
        $item = new ShootService;
        $snapshot = [['key' => 'tour', 'kind' => 'tour', 'label' => 'Tour', 'provider' => 'iguide', 'execution_key' => 'original']];
        $item->forceFill(['is_deliverable' => true, 'delivery_requirements' => $snapshot]);
        $this->assertSame($snapshot, app(DeliveryComponentRequirements::class)->forItem($item));
        $item->forceFill(['delivery_requirements' => null, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $this->assertSame(['legacy'], array_column(app(DeliveryComponentRequirements::class)->forItem($item), 'kind'));
    }

    public function test_paid_client_gets_floorplans_only_after_the_floorplan_component_is_approved(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'ready', 'workflow_status' => 'ready',
            'created_at' => now()->subDays(2), 'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(),
            'admin_verified_at' => null, 'completed_at' => null, 'total_quote' => 250, 'payment_status' => 'paid']);
        $catalog = Service::factory()->create(['name' => 'HDR Photos & Premium iGuide', 'upload_intake_type' => 'photo',
            'included_components' => [['name' => 'HDR Photos', 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => true],
                ['name' => 'Premium iGuide Tour', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false]]]);
        $item = $shoot->serviceItems()->create(['service_id' => $catalog->id, 'price' => 250, 'quantity' => 1,
            'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $this->assertContains('floorplan', array_column($item->delivery_requirements, 'kind'));
        $files = [];
        foreach (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg'] as $extension => $mime) {
            $path = 'shoots/'.$shoot->id.'/floorplan.'.$extension;
            Storage::disk('public')->put($path, $extension === 'pdf' ? '%PDF-1.7 test floorplan' : "\xff\xd8\xff\xe0test floorplan");
            $file = new ShootFile;
            $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => basename($path), 'stored_filename' => basename($path),
                'path' => $path, 'storage_path' => $path, 'file_size' => 24, 'file_type' => $mime, 'mime_type' => $mime,
                'media_type' => 'floorplan', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id])->save();
            $files[] = $file;
            $this->actingAs($client)->getJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/download')->assertForbidden();
        }
        $planner = app(DeliveryPlanService::class);
        $intent = $planner->validateSelection($shoot->fresh(), $actor, ['component_ids' => [$item->id.':floorplan'], 'mode' => 'auto']);
        $job = new FinalizeShootJob($shoot->id, $actor->id, deliveryIntent: $intent);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
        $this->assertDatabaseHas('shoot_delivery_receipts', ['shoot_service_id' => $item->id, 'component_key' => 'floorplan', 'method' => 'media']);
        $this->assertSame(1, DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->count());
        foreach ($files as $file) {
            $this->assertSame('verified', $file->fresh()->workflow_stage);
            $this->actingAs($client)->getJson('/api/shoots/'.$shoot->id.'/media/'.$file->id.'/download')->assertOk()->assertJsonStructure(['url']);
        }
        $this->assertNotSame('delivered', $shoot->fresh()->workflow_status); // Photos and the tour still need their own release.
        $shoot->forceFill(['payment_status' => 'unpaid'])->save();
        $this->actingAs($client)->getJson('/api/shoots/'.$shoot->id.'/media/'.$files[0]->id.'/download')->assertForbidden()->assertJsonPath('code', 'payment_required');
        $outsider = User::factory()->create(['role' => 'client']);
        $this->actingAs($outsider)->getJson('/api/shoots/'.$shoot->id.'/media/'.$files[0]->id.'/download')->assertForbidden();
        Mail::assertNothingSent();
    }
}
