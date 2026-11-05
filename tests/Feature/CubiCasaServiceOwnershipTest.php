<?php

namespace Tests\Feature;

use App\Jobs\{IngestCubiCasaAssetsJob, ScanShootFileJob};
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\CubiCasaService;
use App\Services\Shoots\{CubiCasaServiceOwnership, ProviderFloorplanRecovery};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class CubiCasaServiceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Notification::fake();
        Storage::fake('local'); Storage::fake('public');
        config(['media.tiered_storage_enabled' => false]);
        Http::fake(['https://provider.test/plan.jpg' => Http::response('JPGDATA', 200, ['Content-Type' => 'image/jpeg'])]);
        User::factory()->create(['role' => 'admin']);
    }

    public function test_legacy_shoot_order_imports_into_its_unique_booked_floorplan_service(): void
    {
        [$shoot, $line] = $this->fixture();
        $other = $shoot->serviceItems()->create(['service_id' => Service::factory()->unbracketedPhoto()->create(['name' => '35 HDR Photos'])->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        (new IngestCubiCasaAssetsJob($shoot->id, $this->assets()))->handle(app(ShootActivityLogger::class));
        $file = ShootFile::where('shoot_id', $shoot->id)->sole();
        $this->assertSame($line->id, $file->shoot_service_id);
        $this->assertSame('completed', $file->workflow_stage);
        $this->assertSame('quarantined', $file->scan_status);
        $this->assertStringContainsString('/services/'.$line->id.'/floorplans/', $file->path);
        Queue::assertPushed(ScanShootFileJob::class, fn ($job) => $job->shootFileId === $file->id);
        $this->assertSame('scheduled', $line->fresh()->workflow_status);
        $this->assertSame('delivered', $other->fresh()->delivery_status);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_existing_unscoped_provider_files_keep_their_ids_bytes_and_scan_state_without_redownloading(): void
    {
        [$shoot, $line] = $this->fixture();
        $file = $this->existing($shoot);
        $before = $file->getRawOriginal();
        (new IngestCubiCasaAssetsJob($shoot->id, $this->assets()))->handle(app(ShootActivityLogger::class));
        (new IngestCubiCasaAssetsJob($shoot->id, $this->assets()))->handle(app(ShootActivityLogger::class));
        $this->assertSame(1, $shoot->files()->count());
        $after = $file->fresh()->getRawOriginal();
        $this->assertSame($line->id, $after['shoot_service_id']);
        unset($after['shoot_service_id'], $before['shoot_service_id']);
        $this->assertSame($before, $after);
        Http::assertNothingSent();
        Queue::assertNotPushed(ScanShootFileJob::class);
    }

    public function test_recovery_recognizes_already_scoped_complete_assets(): void
    {
        [$shoot, $line] = $this->fixture();
        $this->existing($shoot, ['shoot_service_id' => $line->id]);
        $result = app(ProviderFloorplanRecovery::class)->recover('cubicasa', 10, $shoot->id, true);
        $this->assertSame(['shoots' => 0, 'imports' => 0, 'scans' => 0, 'previews' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_revision_refresh_cannot_replace_an_owned_asset_with_a_duplicate_unscoped_copy(): void
    {
        [$shoot, $line] = $this->fixture();
        $owned = $this->existing($shoot, ['shoot_service_id' => $line->id, 'workflow_stage' => 'verified']);
        $duplicate = $this->existing($shoot, ['is_hidden' => true]);
        Storage::disk('local')->put($owned->path, 'JPGDATA');
        $this->mock(CubiCasaService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->once()->with('ownership-order')->andReturn(['id' => 'ownership-order']);
            $mock->shouldReceive('parseOrderData')->once()->andReturn(['status' => 'Ready', 'floorplans' => $this->assets()]);
            $mock->shouldReceive('applyShootData')->once()->andReturnUsing(fn ($shoot) => $shoot);
        });
        (new IngestCubiCasaAssetsJob($shoot->id, $this->assets(), null, true))->handle(app(ShootActivityLogger::class));
        $this->assertSame($line->id, $owned->fresh()->shoot_service_id);
        $this->assertNull($duplicate->fresh()->shoot_service_id);
        $this->assertTrue($duplicate->fresh()->is_hidden);
        $this->assertSame(2, $shoot->files()->count());
        Http::assertSentCount(1);
    }

    public function test_ambiguous_provider_lines_require_an_explicit_service_instead_of_guessing(): void
    {
        [$shoot] = $this->fixture();
        $shoot->serviceItems()->create(['service_id' => Service::factory()->create(['name' => 'CubiCasa 3D Floorplan'])->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true]);
        (new IngestCubiCasaAssetsJob($shoot->id, $this->assets()))->handle(app(ShootActivityLogger::class));
        $this->assertSame(0, $shoot->files()->count());
        Http::assertNothingSent();
    }

    public function test_a_new_service_cannot_claim_provider_output_that_predates_its_booking(): void
    {
        [$shoot, $line] = $this->fixture();
        $file = $this->existing($shoot, ['created_at' => now()->subDay()]);
        $this->assertSame([], app(CubiCasaServiceOwnership::class)->adoptUnscoped($shoot, $line->id, ['jpg_listing_dim_0']));
        $this->assertNull($file->fresh()->shoot_service_id);
    }

    private function assets(): array
    {
        return [['asset_key' => 'jpg_listing_dim_0', 'url' => 'https://provider.test/plan.jpg', 'filename' => 'plan.jpg', 'type' => 'jpg']];
    }

    private function fixture(): array
    {
        $shoot = Shoot::factory()->create(['cubicasa_order_id' => 'ownership-order', 'cubicasa_floorplans' => $this->assets(), 'cubicasa_status' => 'Ready']);
        $service = Service::factory()->create(['name' => '2D Floor plans', 'requires_editing' => false, 'upload_intake_type' => 'none']);
        $line = $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 125, 'quantity' => 1,
            'is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        return [$shoot->fresh(), $line];
    }

    private function existing(Shoot $shoot, array $attributes = []): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill($attributes + ['shoot_id' => $shoot->id, 'shoot_service_id' => null,
            'filename' => 'plan.jpg', 'stored_filename' => 'plan.jpg', 'path' => 'shoots/'.$shoot->id.'/floorplans/plan.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 7, 'uploaded_by' => $shoot->client_id,
            'media_type' => 'floorplan', 'workflow_stage' => 'completed', 'scan_status' => 'clean',
            'web_path' => 'shoots/'.$shoot->id.'/web/plan.jpg',
            'metadata' => ['source' => 'cubicasa', 'cubicasa_asset_key' => 'jpg_listing_dim_0']])->saveQuietly();
        return $file->fresh();
    }
}
