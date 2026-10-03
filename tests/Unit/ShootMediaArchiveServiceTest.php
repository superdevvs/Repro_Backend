<?php

namespace Tests\Unit;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class ShootMediaArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $editor;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([GenerateShootMediaArchiveJob::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->create(['role' => 'photographer']);
        $this->service = Service::factory()->create(['name' => 'Media Service']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_small_archives_from_optimized_media_when_available(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $webPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($webPath, 'small-preview-bytes');
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot, 'edited', 'small');

        $zip = new ZipArchive();
        $archivePath = Storage::disk('local')->path($archiveService->getArchivePath($shoot, 'edited', 'small'));

        $this->assertTrue($zip->open($archivePath) === true);
        // Entry names carry their delivery position (min width 3) so the curated
        // order survives extraction; the stored master filename is unchanged.
        $this->assertSame('small-preview-bytes', $zip->getFromName('001_front.jpg'));
        $zip->close();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_full_archives_from_original_media_sources(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $webPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($webPath, 'small-preview-bytes');
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot, 'edited', 'original');

        $zip = new ZipArchive();
        $archivePath = Storage::disk('local')->path($archiveService->getArchivePath($shoot, 'edited', 'original'));

        $this->assertTrue($zip->open($archivePath) === true);
        $this->assertSame('original-photo-bytes', $zip->getFromName('001_front.jpg'));
        $zip->close();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_marks_cached_archives_as_stale_when_the_selected_source_changes(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $firstWebPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        $secondWebPath = 'shoots/' . $shoot->id . '/web/front_web_v2.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($firstWebPath, 'small-preview-bytes');
        Storage::disk('public')->put($secondWebPath, 'updated-small-preview-bytes');
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $firstWebPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot, 'edited', 'small');

        $this->assertTrue($archiveService->hasFreshArchive($shoot, 'edited', 'small'));

        $file->update([
            'web_path' => $secondWebPath,
        ]);

        $this->assertFalse($archiveService->hasFreshArchive($shoot->fresh(), 'edited', 'small'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_service_scoped_archives_for_only_the_selected_service_item(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->mockDropboxDisabled();

        $shoot = $this->createShoot();
        $firstServiceItemId = DB::table('shoot_service')->insertGetId([
            'shoot_id' => $shoot->id,
            'service_id' => $this->service->id,
            'price' => 150,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondService = Service::factory()->create(['name' => 'Video Service']);
        $secondServiceItemId = DB::table('shoot_service')->insertGetId([
            'shoot_id' => $shoot->id,
            'service_id' => $secondService->id,
            'price' => 250,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstPath = 'shoots/' . $shoot->id . '/completed/service-one.jpg';
        $secondPath = 'shoots/' . $shoot->id . '/completed/service-two.jpg';
        Storage::disk('public')->put($firstPath, 'service-one-bytes');
        Storage::disk('public')->put($secondPath, 'service-two-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'service-one.jpg',
            'stored_filename' => 'service-one.jpg',
            'path' => $firstPath,
            'storage_path' => $firstPath,
            'shoot_service_id' => $firstServiceItemId,
        ]);
        $this->createShootFile($shoot, [
            'filename' => 'service-two.jpg',
            'stored_filename' => 'service-two.jpg',
            'path' => $secondPath,
            'storage_path' => $secondPath,
            'shoot_service_id' => $secondServiceItemId,
        ]);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot, 'edited', 'original', false, $firstServiceItemId);

        $zip = new ZipArchive();
        $archivePath = Storage::disk('local')->path(
            $archiveService->getArchivePath($shoot, 'edited', 'original', $firstServiceItemId)
        );

        $this->assertTrue($zip->open($archivePath) === true);
        // Numbering restarts per service-scoped archive, so the selected item's
        // own set reads 001_… rather than inheriting shoot-wide positions.
        $this->assertSame('service-one-bytes', $zip->getFromName('001_service-one.jpg'));
        $this->assertFalse($zip->locateName('001_service-two.jpg'));
        $this->assertFalse($zip->locateName('service-two.jpg'));
        $zip->close();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_excludes_extras_from_downloads_by_default_but_includes_them_on_request(): void
    {
        $shoot = $this->createShoot();
        $this->createShootFile($shoot, [
            'filename' => 'ordered.jpg',
            'stored_filename' => 'ordered.jpg',
            'media_type' => 'photos',
            'is_extra' => false,
        ]);
        $this->createShootFile($shoot, [
            'filename' => 'bonus.jpg',
            'stored_filename' => 'bonus.jpg',
            'media_type' => 'photos',
            'is_extra' => true,
        ]);

        $service = app(ShootMediaArchiveService::class);

        $withExtras = $service->getFilesForType($shoot, $service->buildArchiveTypeToken('edited', true));
        $this->assertEqualsCanonicalizing(
            ['ordered.jpg', 'bonus.jpg'],
            $withExtras->pluck('filename')->all()
        );

        $withoutExtras = $service->getFilesForType($shoot, $service->buildArchiveTypeToken('edited', false));
        $this->assertSame(['ordered.jpg'], $withoutExtras->pluck('filename')->all());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_restricts_downloads_to_requested_media_types(): void
    {
        $shoot = $this->createShoot();
        $this->createShootFile($shoot, [
            'filename' => 'photo.jpg',
            'stored_filename' => 'photo.jpg',
            'media_type' => 'photos',
        ]);
        $this->createShootFile($shoot, [
            'filename' => 'clip.mp4',
            'stored_filename' => 'clip.mp4',
            'file_type' => 'video/mp4',
            'media_type' => 'video',
        ]);

        $service = app(ShootMediaArchiveService::class);
        $files = $service->getFilesForType($shoot, $service->buildArchiveTypeToken('edited', true, ['photos']));

        $this->assertSame(['photo.jpg'], $files->pluck('filename')->all());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function filtered_and_unfiltered_archives_use_distinct_cache_paths(): void
    {
        $shoot = $this->createShoot();
        $service = app(ShootMediaArchiveService::class);

        // The plain token is the default no-extras delivery archive; opting in
        // to extras and restricting to media types must each yield a distinct
        // cache path so a filtered archive never collides with the default.
        $plain = $service->getArchivePath($shoot, 'edited', 'original');
        $withExtras = $service->getArchivePath($shoot, $service->buildArchiveTypeToken('edited', true), 'original');
        $photosOnly = $service->getArchivePath($shoot, $service->buildArchiveTypeToken('edited', false, ['photos']), 'original');

        $this->assertNotSame($plain, $withExtras);
        $this->assertNotSame($plain, $photosOnly);
        $this->assertNotSame($withExtras, $photosOnly);
        $this->assertStringContainsString('-edited-original.zip', $plain);
        $this->assertStringContainsString('-edited-withextras-original.zip', $withExtras);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function archive_type_tokens_are_deterministic(): void
    {
        $service = app(ShootMediaArchiveService::class);

        $this->assertSame('edited', $service->buildArchiveTypeToken('edited'));
        $this->assertSame('edited', $service->buildArchiveTypeToken('edited', false));
        $this->assertSame('edited|we', $service->buildArchiveTypeToken('edited', true));
        $this->assertSame(
            'edited|we;mt=photos,video',
            $service->buildArchiveTypeToken('edited', true, ['video', 'photos', 'video'])
        );
        $this->assertSame(
            'edited|we;mt=photos,video',
            $service->canonicalizeType('edited|we;mt=video,photos')
        );
    }

    public function test_unit_archive_excludes_siblings_and_uses_distinct_cache_identity(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->mockDropboxDisabled();
        $shoot = $this->createShoot();
        $units = [];
        $lines = [];
        foreach ([1, 2] as $number) {
            $unit = $shoot->units()->create(['client_key' => 'unit-'.$number, 'label' => 'Unit '.$number, 'kind' => 'unit']);
            $line = $shoot->serviceItems()->create(['service_id' => $this->service->id, 'shoot_unit_id' => $unit->id, 'client_key' => 'line-'.$number, 'price' => 100, 'quantity' => 1]);
            $path = 'shoots/'.$shoot->id.'/completed/unit-'.$number.'.jpg';
            Storage::disk('public')->put($path, 'bytes-for-unit-'.$number);
            $this->createShootFile($shoot, ['shoot_service_id' => $line->id, 'path' => $path, 'storage_path' => $path]);
            $units[] = $unit;
            $lines[] = $line;
        }
        $service = app(ShootMediaArchiveService::class);
        $this->assertCount(1, $service->getFilesForType($shoot, 'edited', null, $units[0]->id));
        $manifest = $service->generateArchive($shoot, 'edited', 'original', false, null, $units[0]->id);
        $path = $service->getArchivePath($shoot, 'edited', 'original', null, $units[0]->id);
        $this->assertNotSame($path, $service->getArchivePath($shoot, 'edited', 'original', null, $units[1]->id));
        $this->assertNotSame($path, $service->getArchivePath($shoot, 'edited', 'original'));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('bytes-for-unit-1', $zip->getFromIndex(0));
        $zip->close();
        $units[0]->update(['label' => 'Penthouse']);
        $this->assertFalse($service->hasFreshArchive($shoot, 'edited', 'original', null, $units[0]->id));
        $this->assertSame($units[0]->id, $manifest['shoot_unit_id']);

        \Laravel\Sanctum\Sanctum::actingAs($this->admin);
        $endpoint = '/api/shoots/'.$shoot->id.'/media/download-zip?type=edited&shoot_unit_id=';
        $this->getJson($endpoint.$units[0]->id)->assertAccepted();
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->shootUnitId === $units[0]->id);
        $this->getJson($endpoint.$units[0]->id.'&shoot_service_id='.$lines[1]->id)->assertUnprocessable();
        $foreign = $this->createShoot()->units()->create(['client_key' => 'foreign', 'label' => 'Foreign', 'kind' => 'unit']);
        $this->getJson($endpoint.$foreign->id)->assertUnprocessable();
    }

    public function test_compression_policy_preserves_bytes_order_and_valid_cached_archives(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        config()->set('media.archive_store_compressed', true);
        config()->set('media.performance_shoot_ids', []);
        $this->mockDropboxDisabled();
        $shoot = $this->createShoot();
        $contents = [];
        foreach (['capture.NEF' => random_bytes(4096), 'notes.xmp' => str_repeat('<xml>metadata</xml>', 100)] as $name => $bytes) {
            $path = "shoots/{$shoot->id}/todo/{$name}";
            Storage::disk('public')->put($path, $bytes);
            $this->createShootFile($shoot, ['filename' => $name, 'path' => $path, 'storage_path' => $path, 'sort_order' => count($contents) + 1, 'workflow_stage' => ShootFile::STAGE_TODO]);
            $contents[] = $bytes;
        }
        $service = app(ShootMediaArchiveService::class);
        $first = $service->generateArchive($shoot, 'raw', 'original');
        $path = Storage::disk('local')->path($service->getArchivePath($shoot, 'raw', 'original'));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
        $this->assertSame(ZipArchive::CM_DEFLATE, $zip->statIndex(1)['comp_method']);
        foreach ($contents as $index => $bytes) {
            $this->assertSame($bytes, $zip->getFromIndex($index));
        }
        $zip->close();
        $hash = hash_file('sha256', $path);
        config()->set('media.archive_store_compressed', false);
        $this->assertSame($first, $service->generateArchive($shoot, 'raw', 'original'));
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertCount(2, Storage::disk('local')->allFiles(dirname($service->getArchivePath($shoot, 'raw', 'original'))));
    }

    public function test_scoped_archive_identity_tracks_source_versions_order_selection_and_actor(): void
    {
        $shoot = $this->createShoot();
        $one = $this->createShootFile($shoot);
        $two = $this->createShootFile($shoot, ['filename' => 'second.jpg']);
        $scoped = app(\App\Services\Shoots\EditorRawArchiveService::class);
        $files = collect([$one, $two]);
        $original = $scoped->descriptor($shoot, $this->editor, $files)['storage_path'];
        $this->assertNotSame($original, $scoped->descriptor($shoot, $this->admin, $files)['storage_path']);
        $this->assertNotSame($original, $scoped->descriptor($shoot, $this->editor, $files->reverse()->values())['storage_path']);
        $this->assertNotSame($original, $scoped->descriptor($shoot, $this->editor, collect([$one]))['storage_path']);
        $one->file_size = 2048;
        $this->assertNotSame($original, $scoped->descriptor($shoot, $this->editor, $files)['storage_path']);
    }

    public function test_archive_jobs_release_locks_when_sources_are_missing_and_use_canary_queue(): void
    {
        $shoot = $this->createShoot();
        $key = 'shoot-media-archive:'.$shoot->id.':raw:original';
        \Illuminate\Support\Facades\Cache::put($key, 1, 600);
        config()->set('media.archive_dedicated_queue', true);
        config()->set('media.performance_shoot_ids', [(int) $shoot->id]);
        $job = new GenerateShootMediaArchiveJob((int) $shoot->id, 'raw', 'original');
        $this->assertSame('media-archives', $job->queue);
        $this->assertSame('default', (new GenerateShootMediaArchiveJob((int) $shoot->id + 999, 'raw', 'original'))->queue);
        $this->assertSame(600, $job->timeout);
        $job->handle(app(ShootMediaArchiveService::class));
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($key));
        $editorKey = 'test-editor-zip-lock';
        \Illuminate\Support\Facades\Cache::put($editorKey, 1, 600);
        $editorJob = new \App\Jobs\GenerateEditorRawZipJob((int) $shoot->id, 999999999, [], 'missing.zip', $editorKey);
        app()->call([$editorJob, 'handle']);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($editorKey));
        $shareKey = 'test-share-zip-lock';
        \Illuminate\Support\Facades\Cache::put($shareKey, 1, 600);
        $shareJob = new \App\Jobs\GenerateShootShareLinkZipJob(999999999, (int) $shoot->id, [], 'raw', $shareKey);
        app()->call([$shareJob, 'handle']);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($shareKey));
    }

    public function test_scoped_job_does_not_publish_after_assignment_is_revoked(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config()->set('media.local_disk', 'local');
        $shoot = $this->createShoot();
        $path = "shoots/{$shoot->id}/todo/capture.nef";
        Storage::disk('public')->put($path, 'raw-bytes');
        $file = $this->createShootFile($shoot, ['path' => $path, 'storage_path' => $path, 'workflow_stage' => ShootFile::STAGE_TODO]);
        $scoped = app(\App\Services\Shoots\EditorRawArchiveService::class);
        $descriptor = $scoped->descriptor($shoot, $this->editor, collect([$file]));
        $shoot->updateQuietly(['editor_id' => null]);
        $job = new \App\Jobs\GenerateEditorRawZipJob((int) $shoot->id, (int) $this->editor->id, [$file->id], $descriptor['storage_path'], $descriptor['lock_key']);
        app()->call([$job, 'handle']);
        $this->assertFalse(Storage::disk('local')->exists($descriptor['storage_path']));
    }

    public function test_legacy_scoped_archive_is_reused_only_when_order_names_sizes_and_source_crc_match(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config()->set('media.local_disk', 'local');
        $shoot = $this->createShoot();
        $path = "shoots/{$shoot->id}/todo/capture.nef";
        Storage::disk('public')->put($path, 'original-raw');
        $file = $this->createShootFile($shoot, ['filename' => 'capture.nef', 'path' => $path, 'storage_path' => $path, 'file_size' => strlen('original-raw'), 'workflow_stage' => ShootFile::STAGE_TODO]);
        $files = collect([$file]);
        $archive = app(\App\Services\Shoots\ShootShareLinkService::class)->generateFilesZip($shoot, $files);
        $legacy = "editor-downloads/{$shoot->id}/".sha1((string) $file->id).'.zip';
        app(\App\Services\Media\MediaArchivePublisher::class)->publish($legacy, $archive);
        unlink($archive);
        $legacyHash = hash_file('sha256', Storage::disk('local')->path($legacy));
        $scoped = app(\App\Services\Shoots\EditorRawArchiveService::class);
        $destination = $scoped->descriptor($shoot, $this->editor, $files)['storage_path'];
        $this->assertTrue($scoped->reuseLegacyArchive($shoot, $this->editor, $files, $destination));
        $this->assertSame($legacyHash, hash_file('sha256', Storage::disk('local')->path($destination)));
        $this->assertTrue(Storage::disk('local')->exists($legacy));
        Storage::disk('public')->put($path, 'modified-raw');
        $this->assertFalse($scoped->reuseLegacyArchive($shoot, $this->editor, $files, $destination));
    }

    public function test_submission_prewarm_is_gated_and_duplicate_dispatches_are_coalesced(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $shoot = $this->createShoot(['status' => Shoot::STATUS_UPLOADED, 'workflow_status' => Shoot::STATUS_UPLOADED]);
        $path = "shoots/{$shoot->id}/todo/raw.nef";
        Storage::disk('public')->put($path, 'raw');
        $this->createShootFile($shoot, ['path' => $path, 'storage_path' => $path, 'workflow_stage' => ShootFile::STAGE_TODO]);
        $prewarm = app(\App\Services\Shoots\ShootArchivePrewarmService::class);
        $prewarm->afterRawSubmission($shoot);
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
        config()->set('media.archive_prewarm', true);
        config()->set('media.performance_shoot_ids', [(int) $shoot->id]);
        // Execute the after-commit callback inside this test's outer transaction.
        $database = DB::getFacadeRoot();
        DB::partialMock()->shouldReceive('transactionLevel')->andReturn(1);
        DB::shouldReceive('connection')->andReturnUsing(fn ($name = null) => $database->connection($name));
        DB::shouldReceive('afterCommit')->andReturnUsing(fn ($callback) => $callback());
        $prewarm->afterRawSubmission($shoot);
        $prewarm->afterRawSubmission($shoot);
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, 1);
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->type === 'raw' && $job->size === 'original');
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
            'address' => '250 Media Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ], $overrides));
    }

    protected function createShootFile(Shoot $shoot, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => 'media-file.jpg',
            'stored_filename' => 'media-file.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/media-file.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'sort_order' => 0,
        ], $overrides));
    }

    protected function mockDropboxDisabled(): void
    {
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(ShootMediaStorageService::class, $dropbox);
    }
}
