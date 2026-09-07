<?php

namespace Tests\Feature;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use App\Services\ShootActivityLogger;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaReadService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DropboxRetirementMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        Mail::fake();
        Http::fake();
        // Even an obsolete cached flag/token cannot restore remote behavior.
        config(['services.dropbox.enabled' => true, 'services.dropbox.access_token' => 'retired-canary',
            'media.dual_write' => false, 'media.read_from_r2' => false, 'media.r2_only' => false]);
    }

    public function test_local_upload_generates_real_previews_and_watermarks_with_stale_remote_metadata(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['payment_status' => 'paid']);
        $storage = app(ShootMediaStorageService::class);
        $file = $storage->uploadToTodo($shoot, UploadedFile::fake()->image('photo.jpg', 1600, 900), $owner->id);
        Storage::disk('public')->assertExists($file->path);
        $this->assertNull($file->dropbox_path);
        Queue::assertPushed(ScanShootFileJob::class);
        Queue::assertNotPushed(SyncShootFileToDropboxJob::class);

        $file->forceFill(['scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'dropbox_path' => '/retired/photo.jpg'])->save();
        (new ProcessImageJob($file))->handle(app(ImageProcessingService::class), $storage, app(MediaStorage::class));
        $file->refresh();
        foreach (['thumbnail_path', 'grid_path', 'web_path', 'placeholder_path'] as $attribute) {
            $this->assertNotEmpty($file->{$attribute}, $attribute);
            Storage::disk('public')->assertExists($file->{$attribute});
            $this->assertNotFalse(getimagesize(Storage::disk('public')->path($file->{$attribute})));
        }

        (new GenerateWatermarkedImageJob($file))->handle($storage);
        $file->refresh();
        foreach (['watermarked_storage_path', 'watermarked_thumbnail_path', 'watermarked_web_path', 'watermarked_placeholder_path'] as $attribute) {
            $this->assertNotEmpty($file->{$attribute}, $attribute);
            Storage::disk('public')->assertExists($file->{$attribute});
            $this->assertNotFalse(getimagesize(Storage::disk('public')->path($file->{$attribute})));
        }
        $originalHash = hash_file('sha256', Storage::disk('public')->path($file->path));
        $storage->moveToCompleted($file, $owner->id);
        $storage->moveToFinal($file, $owner->id);
        $file->refresh();
        $this->assertSame(ShootFile::STAGE_VERIFIED, $file->workflow_stage);
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('public')->path($file->path)));
        Http::assertNothingSent();
    }

    public function test_old_serialized_album_job_uses_local_intake_and_keeps_scanning(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $owner->id]);
        $album = ShootMediaAlbum::create(['shoot_id' => $shoot->id, 'folder_path' => 'shoots/legacy/raw', 'source' => 'dropbox', 'photographer_id' => $owner->id]);
        $upload = UploadedFile::fake()->image('queued.jpg', 600, 400);
        Storage::disk('local')->put('temp/uploads/queued.jpg', file_get_contents($upload->getRealPath()));
        $job = unserialize(serialize(new UploadShootMediaToDropboxJob($shoot, $album, 'temp/uploads/queued.jpg', 'queued.jpg', 'raw', $owner->id)));
        $job->handle(app(ShootMediaStorageService::class), app(ShootActivityLogger::class));
        $file = $shoot->files()->firstOrFail();
        $this->assertSame($album->id, $file->album_id);
        $this->assertNull($file->dropbox_path);
        $this->assertSame('local', $album->refresh()->source);
        Storage::disk('public')->assertExists($file->path);
        Storage::disk('local')->assertMissing('temp/uploads/queued.jpg');
        Queue::assertPushed(ScanShootFileJob::class);
        Http::assertNothingSent();
    }

    public function test_admin_and_assigned_sales_media_listing_returns_database_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sales = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create(['rep_id' => $sales->id, 'payment_status' => 'paid']);
        $file = app(ShootMediaStorageService::class)->uploadToCompleted($shoot, UploadedFile::fake()->image('edited.jpg'), $admin->id);
        $file->forceFill(['scan_status' => ShootFile::SCAN_STATUS_CLEAN])->save();
        $extra = app(ShootMediaStorageService::class)->uploadToExtra($shoot, UploadedFile::fake()->image('extra.jpg'), $admin->id);
        $extra->forceFill(['scan_status' => ShootFile::SCAN_STATUS_CLEAN])->save();
        foreach ([$admin, $sales] as $viewer) {
            $payload = app(ShootMediaReadService::class)->listMediaPayload($shoot, 'edited', $viewer);
            $this->assertContains($file->id, array_column($payload['data'], 'id'));
            $this->assertSame('edited.jpg', $payload['data'][0]['name']);
            $this->assertArrayHasKey('modified', $payload['data'][0]);
            $extraPayload = app(ShootMediaReadService::class)->listMediaPayload($shoot, 'extra', $viewer);
            $this->assertSame([$extra->id], array_column($extraPayload['data'], 'id'));
        }
        Http::assertNothingSent();
    }

    public function test_local_share_zip_and_historical_source_column_survive_retirement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $file = app(ShootMediaStorageService::class)->uploadToTodo($shoot, UploadedFile::fake()->image('share.jpg'), $admin->id);
        $file->forceFill(['scan_status' => ShootFile::SCAN_STATUS_CLEAN])->save();
        $result = app(ShootShareLinkService::class)->createShootShareLink($shoot, $admin, [$file->id]);
        $record = \App\Models\ShootShareLink::findOrFail($result['share_link_id']);
        $this->assertStringStartsWith('share-links/', $record->dropbox_path);
        Storage::disk('public')->assertExists($record->dropbox_path);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('public')->path($record->dropbox_path)));
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame(Storage::disk('public')->get($file->path), $zip->getFromIndex(0));
        $zip->close();
        Http::assertNothingSent();
    }

    public function test_old_serialized_mirror_job_finishes_without_a_provider(): void
    {
        $job = unserialize(serialize(new SyncShootFileToDropboxJob(999999)));
        $job->handle();
        Http::assertNothingSent();
        $this->assertInstanceOf(SyncShootFileToDropboxJob::class, $job);
    }
}
