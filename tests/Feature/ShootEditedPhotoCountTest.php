<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShootEditedPhotoCountTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->service = Service::factory()->create(['name' => 'Counts Service']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function edited_photo_count_excludes_floor_plans_extras_and_raw_camera_files(): void
    {
        $shoot = $this->createShoot();

        // A genuine edited photo — counted.
        $this->createEditedFile($shoot, [
            'filename' => 'living-room.jpg',
            'stored_filename' => 'living-room.jpg',
            'media_type' => 'edited',
        ]);

        // A floor plan — excluded.
        $this->createEditedFile($shoot, [
            'filename' => 'floorplan.jpg',
            'stored_filename' => 'floorplan.jpg',
            'media_type' => 'floorplan',
        ]);

        // An extra (by flag) — excluded.
        $this->createEditedFile($shoot, [
            'filename' => 'bonus.jpg',
            'stored_filename' => 'bonus.jpg',
            'media_type' => 'edited',
            'is_extra' => true,
        ]);

        // A raw camera file that slipped into the completed stage — excluded.
        $this->createEditedFile($shoot, [
            'filename' => 'capture.CR2',
            'stored_filename' => 'capture.CR2',
            'media_type' => 'edited',
            'file_type' => 'image/x-canon-cr2',
        ]);

        $refreshed = app(ShootMediaMutationSupportService::class)->refreshMediaCounters($shoot);

        $this->assertSame(1, (int) $refreshed->edited_photo_count);
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ], $overrides));
    }

    protected function createEditedFile(Shoot $shoot, array $overrides = []): ShootFile
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
}
