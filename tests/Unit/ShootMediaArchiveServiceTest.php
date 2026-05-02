<?php

namespace Tests\Unit;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
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

    /** @test */
    public function it_generates_small_archives_from_optimized_media_when_available(): void
    {
        Storage::fake('public');
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
        $archivePath = Storage::disk('public')->path($archiveService->getArchivePath($shoot, 'edited', 'small'));

        $this->assertTrue($zip->open($archivePath) === true);
        $this->assertSame('small-preview-bytes', $zip->getFromName('front.jpg'));
        $zip->close();
    }

    /** @test */
    public function it_generates_full_archives_from_original_media_sources(): void
    {
        Storage::fake('public');
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
        $archivePath = Storage::disk('public')->path($archiveService->getArchivePath($shoot, 'edited', 'original'));

        $this->assertTrue($zip->open($archivePath) === true);
        $this->assertSame('original-photo-bytes', $zip->getFromName('front.jpg'));
        $zip->close();
    }

    /** @test */
    public function it_marks_cached_archives_as_stale_when_the_selected_source_changes(): void
    {
        Storage::fake('public');
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

    /** @test */
    public function it_generates_service_scoped_archives_for_only_the_selected_service_item(): void
    {
        Storage::fake('public');
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
        $archivePath = Storage::disk('public')->path(
            $archiveService->getArchivePath($shoot, 'edited', 'original', $firstServiceItemId)
        );

        $this->assertTrue($zip->open($archivePath) === true);
        $this->assertSame('service-one-bytes', $zip->getFromName('service-one.jpg'));
        $this->assertFalse($zip->locateName('service-two.jpg'));
        $zip->close();
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
            'sort_order' => 0,
        ], $overrides));
    }

    protected function mockDropboxDisabled(): void
    {
        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);
    }
}
