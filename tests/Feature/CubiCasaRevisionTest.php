<?php

namespace Tests\Feature;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\CubiCasaAssetRevision;
use App\Services\Shoots\FloorplanPreviewService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CubiCasaRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;

    private ShootFile $file;

    private string $old;

    private string $new;

    private array $asset = ['asset_key' => 'jpg_listing_dim_0', 'url' => 'https://assets.test/plan.jpg', 'type' => 'jpg'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false, 'services.cubicasa.api_key' => 'test-key',
            'services.cubicasa.base_url' => 'https://cubicasa.test']);
        Http::preventStrayRequests();
        $service = Service::factory()->create(['name' => '2D Floor plans']);
        $this->shoot = Shoot::factory()->create(['service_id' => $service->id, 'status' => 'delivered', 'delivery_status' => 'delivered',
            'cubicasa_order_id' => 'revision-order', 'cubicasa_status' => 'Ready', 'cubicasa_floorplans' => [$this->asset]]);
        $oldUpload = UploadedFile::fake()->image('old.jpg', 48, 32);
        $newUpload = UploadedFile::fake()->image('new.jpg', 64, 48);
        $this->old = file_get_contents($oldUpload->getRealPath());
        $this->new = file_get_contents($newUpload->getRealPath());
        Storage::disk('local')->put('shoots/'.$this->shoot->id.'/floorplans/old.jpg', $this->old);
        Storage::disk('local')->put('old-preview.jpg', 'old-preview');
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'plan.jpg', 'stored_filename' => 'old.jpg',
            'path' => 'shoots/'.$this->shoot->id.'/floorplans/old.jpg', 'storage_path' => 'shoots/'.$this->shoot->id.'/floorplans/old.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => strlen($this->old), 'uploaded_by' => $this->shoot->client_id,
            'media_type' => 'floorplan', 'workflow_stage' => 'verified', 'scan_status' => 'clean', 'web_path' => 'old-preview.jpg',
            'thumbnail_path' => 'old-preview.jpg', 'sort_order' => 7, 'is_favorite' => true,
            'metadata' => ['source' => 'cubicasa', 'cubicasa_asset_key' => $this->asset['asset_key'], 'original_url' => $this->asset['url'],
                'preview_images' => ['old-preview.jpg'], 'preview_page_count' => 5, 'curation_note' => 'keep']])->fresh();
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
    }

    private function provider(string|\Illuminate\Http\Client\ResponseSequence $binary): void
    {
        Http::fake(['https://cubicasa.test/orders/revision-order' => Http::response(['id' => 'revision-order', 'info' => ['status' => 'Ready'],
            'delivery_assets' => ['listing_floorplans' => ['jpg_urls_dim' => [$this->asset['url']]]]], 200),
            $this->asset['url'] => is_string($binary) ? Http::response($binary, 200, ['Content-Type' => 'image/jpeg']) : $binary]);
    }

    private function runRefresh(array $assets = []): void
    {
        (new IngestCubiCasaAssetsJob($this->shoot->id, $assets ?: [$this->asset], null, true))->handle(app(ShootActivityLogger::class));
    }

    public function test_same_url_changed_bytes_replace_in_place_and_replays_leave_everything_unchanged(): void
    {
        $this->provider($this->new);
        $this->runRefresh();
        $fresh = $this->file->fresh();
        $this->assertSame($this->file->id, $fresh->id);
        $this->assertSame(2, $fresh->content_version);
        $this->assertSame($this->new, Storage::disk('local')->get($fresh->path));
        $this->assertSame($this->old, Storage::disk('local')->get($this->file->path));
        $this->assertNotSame($this->file->web_path, $fresh->web_path);
        Storage::disk('local')->assertExists($fresh->web_path);
        $this->assertSame('clean', $fresh->scan_status);
        $this->assertSame('verified', $fresh->workflow_stage);
        $this->assertSame(7, $fresh->sort_order);
        $this->assertTrue($fresh->is_favorite);
        $this->assertSame('keep', $fresh->metadata['curation_note']);
        $this->assertArrayNotHasKey('preview_images', $fresh->metadata);
        $this->assertSame('delivered', $this->shoot->fresh()->status);
        $this->assertSame(2, ShootFileVersion::count());
        $this->assertGreaterThan(0, app(ShootMediaMutationSupportService::class)->currentMediaRevision($this->shoot));
        $this->runRefresh();
        $this->assertSame($fresh->getAttributes(), $this->file->fresh()->getAttributes());
        $this->assertSame(1, $this->shoot->files()->count());
        $this->assertSame(2, ShootFileVersion::count());
    }

    public function test_stale_queued_urls_are_not_downloaded_and_latest_provider_bytes_win(): void
    {
        $this->provider($this->new);
        $this->runRefresh([array_replace($this->asset, ['url' => 'https://assets.test/stale.jpg'])]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stale.jpg'));
        $this->assertSame($this->new, Storage::disk('local')->get($this->file->fresh()->path));
    }

    public function test_unchanged_bytes_even_with_a_rotated_url_do_not_create_a_revision(): void
    {
        $this->asset['url'] = 'https://assets.test/plan.jpg?new-signature=yes';
        $this->provider($this->old);
        $this->runRefresh();
        $this->assertSame($this->file->getAttributes(), $this->file->fresh()->getAttributes());
        $this->assertSame(0, ShootFileVersion::count());
    }

    public function test_hidden_files_remain_hidden_after_revision(): void
    {
        $this->file->update(['is_hidden' => true]);
        $this->provider($this->new);
        $this->runRefresh();
        $this->assertTrue($this->file->fresh()->is_hidden);
        $this->assertSame(2, $this->file->fresh()->content_version);
    }

    public function test_scan_failure_keeps_the_original_and_can_retry_safely(): void
    {
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(ClamAvScanResult::infected('test')));
        $this->provider($this->new);
        try {
            $this->runRefresh();
            $this->fail('Unsafe content must not publish.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('safety scan', $error->getPrevious()->getMessage());
        }
        $this->assertSame($this->file->getAttributes(), $this->file->fresh()->getAttributes());
        $this->assertSame(0, ShootFileVersion::count());
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
        app()->forgetInstance(CubiCasaAssetRevision::class);
        $this->runRefresh();
        $this->assertSame(2, $this->file->fresh()->content_version);
    }

    public function test_failed_preview_does_not_change_the_current_row_or_publish_a_version(): void
    {
        $this->mock(FloorplanPreviewService::class, fn ($mock) => $mock->shouldReceive('ensurePreview')->once()->andReturn(['status' => 'pdf_failed']));
        $this->provider($this->new);
        try {
            $this->runRefresh();
            $this->fail('Incomplete previews must not publish.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('preview is not ready', $error->getPrevious()->getMessage());
        }
        $this->assertSame($this->file->getAttributes(), $this->file->fresh()->getAttributes());
        $this->assertSame(0, ShootFileVersion::count());
    }

    public function test_network_failure_and_html_waiting_pages_preserve_the_current_delivery(): void
    {
        $this->provider(Http::sequence()->push('unavailable', 503)->push('<html>Generating</html>', 200, ['Content-Type' => 'text/html']));
        try {
            $this->runRefresh();
            $this->fail('The download must retry.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('HTTP 503', $error->getPrevious()->getMessage());
        }

        $this->runRefresh();
        $this->assertSame($this->file->getAttributes(), $this->file->fresh()->getAttributes());
    }

    public function test_infected_current_files_are_not_silently_replaced(): void
    {
        $this->file->update(['scan_status' => 'infected']);
        $this->provider($this->new);
        $this->runRefresh();
        Http::assertSentCount(1);
        $this->assertSame('infected', $this->file->fresh()->scan_status);
        $this->assertSame(1, $this->file->fresh()->content_version);
    }

    public function test_overlapping_imports_release_without_fetching_or_creating_duplicates(): void
    {
        $lock = Cache::lock('cubicasa:ingest:'.$this->shoot->id.':0', 660);
        $lock->get();
        try {
            $this->runRefresh();
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        $this->assertSame(1, $this->shoot->files()->count());
    }

    public function test_completed_deliveries_are_polled_and_coalesced_but_explicit_refresh_is_available(): void
    {
        $this->artisan('cubicasa:refresh-deliveries')->assertSuccessful();
        $this->artisan('cubicasa:refresh-deliveries')->assertSuccessful();
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, 1);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $this->shoot->id && $job->refreshExisting);
        $this->artisan('cubicasa:refresh-deliveries', ['--shoot' => $this->shoot->id])->assertSuccessful();
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, 2);
        Http::assertNothingSent();
    }

    public function test_service_scopes_with_the_same_asset_key_do_not_replace_each_other(): void
    {
        $unit = $this->shoot->units()->create(['client_key' => 'unit-a', 'label' => '101', 'kind' => 'unit']);
        $line = \App\Models\ShootService::create(['shoot_id' => $this->shoot->id, 'shoot_unit_id' => $unit->id,
            'service_id' => $this->shoot->service_id, 'quantity' => 1, 'price' => 100]);
        $unit->update(['provider_data' => ['cubicasa_service_line_id' => $line->id, 'cubicasa_order_id' => 'revision-order',
            'cubicasa_status' => 'Ready', 'cubicasa_floorplans' => [$this->asset]]]);
        $scoped = $this->file->replicate();
        $scoped->shoot_service_id = $line->id;
        $scoped->save();
        $this->provider($this->new);
        $this->artisan('cubicasa:refresh-deliveries')->assertSuccessful();
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootServiceId === $line->id);
        (new IngestCubiCasaAssetsJob($this->shoot->id, [$this->asset], $line->id, true))->handle(app(ShootActivityLogger::class));
        $this->assertSame(2, $scoped->fresh()->content_version);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertSame($line->id, $scoped->fresh()->shoot_service_id);
        $this->assertSame(2, $this->shoot->files()->count());
    }

    public function test_concurrent_content_change_is_not_overwritten_by_the_candidate(): void
    {
        $real = app(FloorplanPreviewService::class);
        $this->mock(FloorplanPreviewService::class, fn ($mock) => $mock->shouldReceive('ensurePreview')->once()->andReturnUsing(function ($candidate, $force, $persist) use ($real) {
            $result = $real->ensurePreview($candidate, $force, $persist);
            $this->file->update(['content_version' => 9]);

            return $result;
        }));
        $this->provider($this->new);
        try {
            $this->runRefresh();
            $this->fail('Newer content must win.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('changed during revision', $error->getPrevious()->getMessage());
        }
        $this->assertSame(9, $this->file->fresh()->content_version);
        $this->assertSame($this->file->path, $this->file->fresh()->path);
        $this->assertSame(0, ShootFileVersion::count());
    }

    public function test_manual_replacements_and_cancelled_shoots_are_not_polled(): void
    {
        $this->shoot->update(['cubicasa_floorplans' => []]);
        $this->artisan('cubicasa:refresh-deliveries')->assertSuccessful();
        $this->shoot->update(['cubicasa_floorplans' => [$this->asset], 'status' => 'cancelled']);
        $this->artisan('cubicasa:refresh-deliveries')->assertSuccessful();
        Queue::assertNotPushed(IngestCubiCasaAssetsJob::class);
    }
}
