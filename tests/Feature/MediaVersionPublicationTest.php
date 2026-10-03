<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaVersion;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Shoots\MediaVersionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaVersionPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Shoot $shoot;
    private ShootFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false, 'media.r2_only' => false, 'media.read_from_r2' => false]);
        $this->manager = User::factory()->create(['role' => 'editing_manager']);
        $this->shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $old = UploadedFile::fake()->image('old.jpg', 32, 24);
        $path = "shoots/{$this->shoot->id}/completed/original.jpg";
        app(MediaStorage::class)->put($path, file_get_contents($old->getRealPath()));
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => 'Kitchen.jpg', 'stored_filename' => 'original.jpg',
            'path' => $path, 'storage_path' => $path, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => $old->getSize(),
            'media_type' => 'edited', 'workflow_stage' => 'verified', 'uploaded_by' => $this->manager->id, 'scan_status' => 'clean', 'sort_order' => 9])->fresh();
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
    }

    private function stage(?ShootFile $source = null, ?string $key = null): ShootFileVersion
    {
        $upload = UploadedFile::fake()->image('saved.png', 48, 32);
        return app(MediaVersionPublisher::class)->stage($source ?? $this->file, $upload->getRealPath(), 'saved.png', 1, $this->manager, $key ?? (string) Str::uuid());
    }

    private function process(ShootFileVersion $version): ShootFileVersion
    {
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        return $version->fresh();
    }

    public function test_ready_replacement_keeps_identity_order_delivery_and_previous_bytes_and_deduplicates_upload(): void
    {
        $oldPath = $this->file->path;
        $upload = UploadedFile::fake()->image('saved.png', 48, 32);
        $publisher = app(MediaVersionPublisher::class);
        $version = $publisher->stage($this->file, $upload->getRealPath(), 'saved.png', 1, $this->manager, 'same-upload');
        $repeat = $publisher->stage($this->file, $upload->getRealPath(), 'saved.png', 1, $this->manager, 'same-upload');
        $this->assertSame($version->id, $repeat->id);
        Queue::assertPushed(ProcessMediaVersion::class, 1);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->assertSame('published', $this->process($version)->status);
        $current = $this->file->fresh();
        $this->assertSame(2, $current->content_version);
        $this->assertSame(9, $current->sort_order);
        $this->assertSame('Kitchen.png', $current->filename);
        $this->assertSame('verified', $current->workflow_stage);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
        $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
        $this->assertNotEmpty($current->grid_path);
        $this->assertDatabaseHas('shoot_file_versions', ['published_file_id' => $current->id, 'version' => 1, 'status' => 'archived']);
        $this->assertSame(1, ShootFile::where('shoot_id', $this->shoot->id)->count());
        $this->process($version);
        $this->assertSame(2, $current->fresh()->content_version);
    }

    public function test_concurrent_edit_waits_for_an_explicit_decision_and_save_copy_preserves_latest(): void
    {
        $first = $this->stage();
        $second = $this->stage();
        $this->process($first);
        $latestPath = $this->file->fresh()->path;
        $this->assertSame('conflict', $this->process($second)->status);
        $this->assertSame($latestPath, $this->file->fresh()->path);
        $copy = app(MediaVersionPublisher::class)->resolve($second->fresh(), 'save_copy', 2);
        $this->assertSame('published', $copy->status);
        $this->assertNotSame($this->file->id, $copy->published_file_id);
        $this->assertSame($latestPath, $this->file->fresh()->path);
        $this->assertSame($this->file->id, ShootFile::findOrFail($copy->published_file_id)->source_file_id);
    }

    public function test_replace_latest_checks_the_reviewed_version_and_restore_creates_a_new_revision(): void
    {
        $first = $this->stage();
        $second = $this->stage();
        $this->process($first);
        $this->process($second);
        $url = "/api/shoots/{$this->shoot->id}/media-versions/{$second->id}/resolve";
        $this->actingAs($this->manager)->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 1])->assertConflict();
        $this->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $baseline = ShootFileVersion::where('published_file_id', $this->file->id)->where('version', 1)->firstOrFail();
        $restored = $this->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$baseline->id}/restore", ['request_id' => (string) Str::uuid(), 'expected_version' => 3])
            ->assertAccepted()->json('data.id');
        $this->assertSame(3, $this->file->fresh()->content_version);
        $this->process(ShootFileVersion::findOrFail($restored));
        $this->assertSame(4, $this->file->fresh()->content_version);
        $this->assertSame('image/jpeg', $this->file->fresh()->mime_type);
    }

    public function test_raw_return_creates_a_linked_edited_image_without_changing_raw_bytes(): void
    {
        $this->file->update(['workflow_stage' => 'todo', 'media_type' => 'raw']);
        $rawPath = $this->file->path;
        $version = $this->process($this->stage());
        $this->assertSame('published', $version->status);
        $this->assertNotSame($this->file->id, $version->published_file_id);
        $this->assertSame($rawPath, $this->file->fresh()->path);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertSame($this->file->id, ShootFile::findOrFail($version->published_file_id)->source_file_id);
    }

    public function test_failed_scan_and_deleted_source_preserve_the_existing_gallery(): void
    {
        $version = $this->stage();
        $oldPath = $this->file->path;
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::infected('test-signature')));
        $this->assertSame('scan_failed', $this->process($version)->error_code);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
        $deleted = $this->stage();
        $this->file->delete();
        $this->assertSame('source_deleted', $this->process($deleted)->error_code);
        $this->assertSame(0, ShootFile::where('shoot_id', $this->shoot->id)->count());
        $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
    }

    public function test_history_is_staff_only_and_psd_is_not_a_gallery_deliverable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/versions")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent('master.psd', '8BPS layered master'),
            'request_id' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertUnprocessable();
        $this->assertSame(1, $this->file->fresh()->content_version);
    }

    public function test_same_filename_intake_preserves_current_until_processed_and_failed_exports_remain_recoverable(): void
    {
        $service = app(\App\Services\ShootMediaStorageService::class);
        $oldPath = $this->file->path;
        $upload = UploadedFile::fake()->image('Kitchen.jpg', 40, 30);
        $saved = $service->uploadToCompleted($this->shoot, $upload, $this->manager->id);
        $this->assertSame($this->file->id, $saved->id);
        $this->assertNotNull($saved->pendingMediaVersionId);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $version = ShootFileVersion::findOrFail($saved->pendingMediaVersionId);
        $this->process($version);
        $this->assertSame(2, $this->file->fresh()->content_version);
        $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
        $repeat = $service->uploadToCompleted($this->shoot, $upload, $this->manager->id);
        $this->assertNull($repeat->pendingMediaVersionId);
        $failedUpload = UploadedFile::fake()->image('Kitchen.jpg', 60, 40);
        $failed = $service->uploadToCompleted($this->shoot, $failedUpload, $this->manager->id);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::infected('fixture')));
        $failedVersion = $this->process(ShootFileVersion::findOrFail($failed->pendingMediaVersionId));
        $this->assertSame('failed', $failedVersion->status);
        $this->assertSame(2, $this->file->fresh()->content_version);
        $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$failedVersion->id}/dismiss")->assertOk();
        $retry = $service->uploadToCompleted($this->shoot, $failedUpload, $this->manager->id);
        $this->assertNotSame($failedVersion->id, $retry->pendingMediaVersionId);
        $this->assertTrue(app(MediaStorage::class)->exists($failedVersion->snapshot['path']));
    }

    public function test_tiff_export_keeps_full_resolution_tiff_and_builds_jpeg_previews(): void
    {
        // Generated 8x6 RGB TIFF fixture, with no customer data or layered Photoshop content.
        $bytes = base64_decode('SUkqAAgAAAAKAAABBAABAAAACAAAAAEBBAABAAAABgAAAAIBAwADAAAAhgAAAAMBAwABAAAAAQAAAAYBAwABAAAAAgAAABEBBAABAAAAjAAAABUBAwABAAAAAwAAABYBBAABAAAABgAAABcBBAABAAAAkAAAABwBAwABAAAAAQAAAAAAAAAIAAgACAAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFAeZFA=');
        $upload = UploadedFile::fake()->createWithContent('saved.tif', $bytes);
        $version = app(MediaVersionPublisher::class)->stage($this->file, $upload->getRealPath(), 'saved.tif', 1, $this->manager, 'tiff-export');
        $this->assertSame('published', $this->process($version)->status);
        $current = $this->file->fresh();
        $this->assertSame('image/tiff', $current->mime_type);
        $this->assertSame($bytes, app(MediaStorage::class)->get($current->path));
        $this->assertSame('image/jpeg', getimagesizefromstring(app(MediaStorage::class)->get($current->web_path))['mime']);
    }
}
