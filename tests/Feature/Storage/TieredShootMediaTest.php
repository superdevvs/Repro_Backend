<?php

namespace Tests\Feature\Storage;

use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\FloorplanPreviewService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class TieredShootMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['local', 'public', 'media_originals', 'media'] as $disk) {
            Storage::fake($disk);
        }
        Queue::fake();
        config()->set([
            'media.local_disk' => 'local',
            'media.originals_disk' => 'media_originals',
            'media.legacy_public_disk' => 'public',
            'media.remote_disk' => 'media',
            'media.tiered_storage_enabled' => true,
            'media.dual_write' => false,
            'media.read_from_r2' => false,
            'media.r2_only' => false,
        ]);
        $guard = Mockery::mock(OriginalsStorageGuard::class);
        $guard->shouldReceive('assertAvailable')->andReturnNull();
        $guard->shouldReceive('isAvailable')->andReturnTrue();
        $this->app->instance(OriginalsStorageGuard::class, $guard);
    }

    public function test_upload_scan_processing_and_signed_reads_work_with_only_the_hdd_original(): void
    {
        $owner = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create(['payment_status' => 'paid']);
        $file = app(ShootMediaStorageService::class)->uploadToTodo(
            $shoot, UploadedFile::fake()->image('photo.jpg', 800, 600), $owner->id
        );

        Storage::disk('media_originals')->assertExists($file->path);
        Storage::disk('local')->assertMissing($file->path);
        Storage::disk('public')->assertMissing($file->path);
        $this->assertNotEmpty($file->thumbnail_path);
        Storage::disk('local')->assertExists($file->thumbnail_path);
        Storage::disk('media_originals')->assertMissing($file->thumbnail_path);

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldReceive('scan')->once()
            ->with(Storage::disk('media_originals')->path($file->path))
            ->andReturn(ClamAvScanResult::clean());
        (new ScanShootFileJob($file->id))->handle($clamAv, app(FileScanService::class));
        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);

        $file->update(['grid_path' => null]);
        (new ProcessImageJob($file->fresh()))->handle(
            app(ImageProcessingService::class), app(ShootMediaStorageService::class), app(MediaStorage::class)
        );
        $file->refresh();
        $this->assertNotEmpty($file->grid_path);
        Storage::disk('local')->assertExists($file->grid_path);
        Storage::disk('media_originals')->assertMissing($file->grid_path);

        $response = $this->get(app(MediaStorage::class)->publicUrl($file->path))->assertOk();
        $this->assertSame(Storage::disk('media_originals')->get($file->path), $response->streamedContent());
    }

    public function test_archive_and_share_zip_read_hdd_masters_and_publish_to_hdd(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $owner->id, 'payment_status' => 'paid']);
        $path = "shoots/{$shoot->id}/todo/raw.jpg";
        Storage::disk('media_originals')->put($path, 'hdd-original-bytes');
        $file = $this->createFile($shoot, $owner, $path, ['media_type' => 'raw', 'workflow_stage' => ShootFile::STAGE_TODO]);

        $archives = app(ShootMediaArchiveService::class);
        $archives->generateArchive($shoot, 'raw', 'original');
        $archiveKey = $archives->getArchivePath($shoot, 'raw', 'original');
        Storage::disk('media_originals')->assertExists($archiveKey);
        Storage::disk('local')->assertMissing($archiveKey);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('media_originals')->path($archiveKey)));
        $this->assertSame('hdd-original-bytes', $zip->getFromIndex(0));
        $zip->close();

        $created = app(ShootShareLinkService::class)->createShootShareLink($shoot, $owner, [$file->id]);
        $link = ShootShareLink::findOrFail($created['share_link_id']);
        Storage::disk('media_originals')->assertExists($link->dropbox_path);
        Storage::disk('local')->assertMissing($link->dropbox_path);
        $response = $this->get("/api/public/share-links/{$link->public_token}/download")->assertOk();
        $this->assertSame(Storage::disk('media_originals')->get($link->dropbox_path), $response->streamedContent());
        $link->revoke($owner->id);
        $this->get("/api/public/share-links/{$link->public_token}/download")->assertStatus(410);
    }

    public function test_floorplan_image_previews_are_generated_on_nvme_separately_from_the_master(): void
    {
        $owner = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $path = "shoots/{$shoot->id}/floorplans/main-floor.jpg";
        $image = UploadedFile::fake()->image('main-floor.jpg', 1600, 1200);
        Storage::disk('media_originals')->put($path, file_get_contents($image->getRealPath()));
        $file = $this->createFile($shoot, $owner, $path, [
            'path' => 'storage/'.$path, 'storage_path' => 'storage/'.$path,
            'media_type' => 'floorplan', 'thumbnail_path' => $path, 'web_path' => $path,
        ]);

        $result = app(FloorplanPreviewService::class)->ensurePreview($file);
        $this->assertSame('generated', $result['status']);
        $file->refresh();
        $this->assertNotSame($path, $file->thumbnail_path);
        $this->assertNotSame($path, $file->web_path);
        foreach ([$file->thumbnail_path, $file->web_path, $file->grid_path, $file->placeholder_path] as $preview) {
            $this->assertNotEmpty($preview);
            Storage::disk('local')->assertExists($preview);
            Storage::disk('media_originals')->assertMissing($preview);
        }
        Storage::disk('media_originals')->assertExists($path);
    }

    public function test_r2_only_archive_is_published_remotely_without_hdd_or_nvme_archive(): void
    {
        config()->set(['media.r2_only' => true, 'media.read_from_r2' => true]);
        $owner = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create(['payment_status' => 'paid']);
        $path = "shoots/{$shoot->id}/completed/front.jpg";
        Storage::disk('media')->put($path, 'r2-original-bytes');
        $this->createFile($shoot, $owner, $path);

        $archives = app(ShootMediaArchiveService::class);
        $archives->generateArchive($shoot, 'edited', 'original');
        $archiveKey = $archives->getArchivePath($shoot, 'edited', 'original');
        Storage::disk('media')->assertExists($archiveKey);
        Storage::disk('local')->assertMissing($archiveKey);
        Storage::disk('media_originals')->assertMissing($archiveKey);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('media')->path($archiveKey)));
        $this->assertSame('r2-original-bytes', $zip->getFromIndex(0));
        $zip->close();
    }

    private function createFile(Shoot $shoot, User $owner, string $path, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id, 'filename' => basename($path), 'stored_filename' => basename($path),
            'path' => $path, 'storage_path' => $path, 'file_type' => 'image/jpeg', 'file_size' => 18,
            'media_type' => 'edited', 'uploaded_by' => $owner->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ], $overrides));
    }
}
