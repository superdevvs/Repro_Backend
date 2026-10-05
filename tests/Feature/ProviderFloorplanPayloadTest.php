<?php

namespace Tests\Feature;

use App\Jobs\{IngestCubiCasaAssetsJob, IngestIguideAssetsJob, ScanShootFileJob};
use App\Models\{Service, Shoot, ShootFile, User};
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ProviderFloorplanRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http, Queue, Storage};
use Tests\TestCase;

class ProviderFloorplanPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public'); Storage::fake('local'); Queue::fake(); Cache::flush();
    }

    private function fixture(string $provider): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $assets = [['asset_key' => 'pdf_metric', 'url' => 'https://assets.test/plan.pdf', 'filename' => 'plan.pdf', 'type' => 'pdf']];
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create(['shoot_type' => 'standard', 'status' => 'delivered', 'created_by' => $user->id, $provider.'_floorplans' => $assets]));
        $service = Service::factory()->create(['name' => 'CubiCasa Floor Plan']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1]);
        return [$shoot, $assets, $user];
    }

    public function test_both_providers_retry_html_wait_pages_without_publishing_them(): void
    {
        foreach (['cubicasa' => IngestCubiCasaAssetsJob::class, 'iguide' => IngestIguideAssetsJob::class] as $provider => $class) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            [$shoot, $assets] = $this->fixture($provider);
            Http::fake(['*' => Http::sequence()->push('<!doctype html><title>Wait for iGUIDE View Document</title>', 200, ['Content-Type' => 'text/html'])->push('PDFDATA', 200, ['Content-Type' => 'application/pdf'])]);
            $job = (new $class($shoot->id, $assets))->withFakeQueueInteractions();
            $job->handle(app(\App\Services\ShootActivityLogger::class));
            $job->assertReleased(30);
            $this->assertSame(0, $shoot->files()->count());
            (new $class($shoot->id, $assets))->handle(app(\App\Services\ShootActivityLogger::class));
            $file = $shoot->files()->sole();
            $this->assertSame('application/pdf', $file->file_type);
            $this->assertSame('quarantined', $file->scan_status);
            Queue::assertPushed(ScanShootFileJob::class, fn ($job) => $job->shootFileId === $file->id);
        }
    }

    public function test_html_body_is_rejected_even_when_response_claims_pdf(): void
    {
        [$shoot, $assets] = $this->fixture('iguide');
        Http::fake(['*' => Http::response('<html>Preparing document</html>', 200, ['Content-Type' => 'application/pdf'])]);
        $job = (new IngestIguideAssetsJob($shoot->id, $assets))->withFakeQueueInteractions();
        $job->handle(app(\App\Services\ShootActivityLogger::class));
        $job->assertReleased(30); $this->assertSame(0, $shoot->files()->count());
    }

    public function test_invalid_existing_import_is_repaired_in_place_and_rescanned_with_old_payload_retained(): void
    {
        foreach (['cubicasa' => IngestCubiCasaAssetsJob::class, 'iguide' => IngestIguideAssetsJob::class] as $provider => $class) {
            [$shoot, $assets, $user] = $this->fixture($provider);
            app(MediaStorage::class)->put($oldPath = 'shoots/'.$shoot->id.'/floorplans/invalid.pdf', '<html>waiting</html>');
            $file = ShootFile::withoutEvents(fn () => ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'plan.pdf', 'stored_filename' => 'invalid.pdf', 'path' => $oldPath, 'storage_path' => $oldPath, 'uploaded_by' => $user->id, 'file_type' => 'text/html', 'mime_type' => 'text/html', 'media_type' => 'floorplan', 'file_size' => 20, 'scan_status' => 'clean', 'scan_result' => 'stream: OK', 'workflow_stage' => 'completed', 'metadata' => ['source' => $provider, $provider.'_asset_key' => 'pdf_metric']]));
            $this->assertTrue($file->isBlockedFromDelivery());
            $file->forceFill(['is_hidden' => true, 'metadata' => array_merge($file->metadata, ['provider_recovery_original_hidden' => false])])->save();
            $this->assertSame('file_provider_pending', $file->deliveryScanError()['error_type']);
            $tour = app(\App\Services\Shoots\ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot, 'branded', false);
            $this->assertSame([], $tour['floorplans'], 'Invalid local imports must not fall back to unscanned provider links.');
            $recovery = app(ProviderFloorplanRecovery::class)->recover($provider);
            $this->assertSame(1, $recovery['imports']); $this->assertSame(0, $recovery['scans']);
            Http::fake(['*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf'])]);
            (new $class($shoot->id, $assets))->handle(app(\App\Services\ShootActivityLogger::class));
            $file->refresh();
            $this->assertSame(1, $shoot->files()->count());
            $this->assertSame('quarantined', $file->scan_status);
            $this->assertNotSame($oldPath, $file->path);
            $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
            $this->assertSame($oldPath, data_get($file->metadata, 'recovered_invalid_payload_path'));
            $this->assertNull($file->scan_result);
            $this->assertSame('delivered', $shoot->fresh()->status);
            $this->assertFalse($file->is_hidden);
            Queue::assertPushed(ScanShootFileJob::class, fn ($job) => $job->shootFileId === $file->id);
        }
    }

    public function test_infected_import_is_not_overwritten_or_refetched(): void
    {
        [$shoot, $assets, $user] = $this->fixture('iguide');
        $file = ShootFile::withoutEvents(fn () => ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'plan.pdf', 'stored_filename' => 'bad.pdf', 'path' => 'bad.pdf', 'uploaded_by' => $user->id, 'file_type' => 'text/html', 'media_type' => 'floorplan', 'file_size' => 20, 'scan_status' => 'infected', 'metadata' => ['source' => 'iguide', 'iguide_asset_key' => 'pdf_metric']]));
        Http::fake();
        (new IngestIguideAssetsJob($shoot->id, $assets))->handle(app(\App\Services\ShootActivityLogger::class));
        Http::assertNothingSent(); $this->assertSame('infected', $file->fresh()->scan_status);
        $this->assertSame('file_infected', $file->deliveryScanError()['error_type']);
    }

    public function test_wait_page_retries_are_bounded_and_never_publish_html(): void
    {
        [$shoot, $assets] = $this->fixture('iguide');
        Http::fake(['*' => Http::response('<html>waiting</html>', 200, ['Content-Type' => 'text/html'])]);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $job = (new IngestIguideAssetsJob($shoot->id, $assets))->setJob($queueJob);
        try {
            $job->handle(app(\App\Services\ShootActivityLogger::class));
            $this->fail('A persistently unready document must not report successful ingestion.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('not ready', $error->getMessage());
        }
        $this->assertSame(0, $shoot->files()->count());
    }
}
