<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Re-run the release/payment/service-assignment endpoint contracts with offloading enabled. */
class OffloadedImageAuthorizationTest extends ImageEndpointAuthorizationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['media.download_offload' => true, 'media.performance_shoot_ids' => [], 'media.local_disk' => 'local']);
    }

    public function test_only_clean_authorized_media_can_issue_an_internal_redirect(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'uploaded_by' => $photographer->id,
            'filename' => 'image.jpg', 'stored_filename' => 'image.jpg',
            'path' => "shoots/{$shoot->id}/image.jpg", 'file_type' => 'image/jpeg',
            'file_size' => 5, 'media_type' => 'image',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);
        Storage::disk('local')->put($file->path, 'photo');
        Sanctum::actingAs($photographer);
        $this->get("/api/images/{$file->id}/download/original")
            ->assertOk()->assertHeader('X-Accel-Redirect', '/_repro-media/private/'.$file->path);

        foreach ([ShootFile::SCAN_STATUS_INFECTED, ShootFile::SCAN_STATUS_QUARANTINED, ShootFile::SCAN_STATUS_FAILED] as $status) {
            $file->update(['scan_status' => $status]);
            $response = $this->get("/api/images/{$file->id}/download/original")->assertForbidden();
            $this->assertFalse($response->headers->has('X-Accel-Redirect'));
        }
    }
}
