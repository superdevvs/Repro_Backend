<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\Shoots\{ShootAuthorizationSupport, ShootMediaArchiveService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoEditorFinalImageAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['media.read_from_r2' => false, 'media.r2_only' => false, 'media.dual_write' => false]);
        $video = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $photo = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = Shoot::factory()->create(['editor_id' => null, 'status' => Shoot::STATUS_EDITING]);
        $item = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => Service::factory()->photoVideoIntake()->create(['requires_editing' => true])->id,
            'editor_id' => $photo->id, 'video_editor_id' => $video->id, 'quantity' => 1, 'price' => 100,
        ]);
        $files = [];
        foreach (['final.jpg', 'second.jpg', 'raw.CR3', 'raw.mp4', 'final.mp4'] as $i => $name) {
            $raw = str_starts_with($name, 'raw');
            $isVideo = str_ends_with($name, '.mp4');
            $path = "shoots/{$shoot->id}/".($raw ? 'todo' : 'completed')."/{$name}";
            Storage::disk('public')->put($path, 'fixture-'.$name);
            $files[] = ShootFile::create([
                'shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => $name, 'stored_filename' => $name,
                'path' => $path, 'storage_path' => $path, 'file_type' => $isVideo ? 'video/mp4' : ($raw ? 'image/x-canon-cr3' : 'image/jpeg'),
                'file_size' => 20, 'media_type' => $isVideo ? 'video' : ($raw ? 'raw' : 'edited'),
                'workflow_stage' => $raw ? ShootFile::STAGE_TODO : ShootFile::STAGE_COMPLETED,
                'uploaded_by' => $photo->id, 'scan_status' => 'clean',
            ]);
        }
        Sanctum::actingAs($video);
        return [$shoot, $item, $video, $photo, $files];
    }

    public function test_final_images_are_read_only_and_single_and_selected_downloads_work(): void
    {
        [$shoot, , $video, , $files] = $this->fixture();
        [$image, $second, $raw] = $files;
        $rows = $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $image->id);
        foreach (['can_interact', 'can_delete', 'can_manage_visibility', 'can_set_hero'] as $flag) $this->assertFalse($row[$flag]);
        $this->assertTrue($row['can_download']);
        $this->assertNull($row['path']);
        $this->get($row['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/shoots/{$shoot->id}/files/{$image->id}/preview")->assertOk();
        $download = $this->getJson("/api/shoots/{$shoot->id}/media/{$image->id}/download")->assertOk()->json('url');
        $this->get($download)->assertOk();
        $response = $this->postJson("/api/shoots/{$shoot->id}/files/download", ['file_ids' => [$image->id, $second->id]]);
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->postJson("/api/shoots/{$shoot->id}/files/download", ['file_ids' => [$image->id, $raw->id]])->assertForbidden();
        $policy = app(ShootAuthorizationSupport::class);
        $this->assertFalse($policy->canInteractWithShootMediaFile($shoot, $image, $video));
        $this->postJson("/api/shoots/{$shoot->id}/media/{$image->id}/favorite")->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/media/{$image->id}/cover")->assertForbidden();
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$image->id}")->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/media/{$image->id}/rename", ['filename' => 'changed.jpg'])->assertForbidden();
    }

    public function test_reassignment_revokes_lists_downloads_and_previously_issued_image_urls(): void
    {
        [$shoot, $item, $video, $photo, $files] = $this->fixture();
        $image = $files[0];
        $row = collect($this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->json('data'))->firstWhere('id', $image->id);
        $bulkUrl = $this->postJson("/api/shoots/{$shoot->id}/media/bulk-download", ['ids' => [$image->id]])
            ->assertOk()->json('urls.0');
        $this->get($bulkUrl)->assertOk();
        // Keep photo assignment so the old editor can still open the shoot.
        // Reference access must follow the video assignment, including cache hits.
        $item->update(['video_editor_id' => $photo->id, 'editor_id' => $video->id]);
        foreach ([$row['url'], $row['original_url'], $bulkUrl] as $url) $this->get($url)->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/media/{$image->id}/download")->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/files/download", ['file_ids' => [$image->id]])->assertForbidden();
        $freshRows = $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertOk()->json('data');
        $this->assertFalse(collect($freshRows)->firstWhere('id', $image->id)['can_download']);
        $item->update(['editor_id' => $photo->id]);
        $this->getJson("/api/shoots/{$shoot->id}/files?type=edited")->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/files/{$image->id}/preview")->assertForbidden();
    }

    public function test_legacy_default_capabilities_do_not_expand_photo_downloads(): void
    {
        [$shoot, $item, $video, , $files] = $this->fixture();
        $video->update(['metadata' => []]);
        $policy = app(ShootAuthorizationSupport::class);
        // Explicit video assignment works even for older accounts.
        $this->assertTrue($policy->canVideoEditorReadFinalImage($shoot->fresh(), $files[0], $video));
        $item->update(['video_editor_id' => null, 'editor_id' => $video->id]);
        $item->service->update(['upload_intake_type' => Service::INTAKE_PHOTO]);
        $this->assertFalse($policy->canVideoEditorReadFinalImage($shoot->fresh(), $files[0], $video));
        $item->service->update(['upload_intake_type' => Service::INTAKE_VIDEO]);
        $this->assertTrue($policy->canVideoEditorReadFinalImage($shoot->fresh(), $files[0], $video));
        $item->service->update(['upload_intake_type' => Service::INTAKE_PHOTO]);
        $video->update(['metadata' => ['editing_capabilities' => ['video']]]);
        $this->assertTrue($policy->canVideoEditorReadFinalImage($shoot->fresh(), $files[0], $video));
    }

    public function test_raw_photo_and_existing_photo_editor_permissions_are_unchanged(): void
    {
        [$shoot, , $video, $photo, $files] = $this->fixture();
        [$image, , $rawPhoto, $rawVideo, $finalVideo] = $files;
        $policy = app(ShootAuthorizationSupport::class);
        $this->assertFalse($policy->canDownloadShootMediaFile($shoot, $rawPhoto, $video));
        $this->assertTrue($policy->canDownloadShootMediaFile($shoot, $rawVideo, $video));
        $this->assertFalse($policy->canDownloadShootMediaFile($shoot, $finalVideo, $video));
        $this->assertTrue($policy->canDownloadShootMediaFile($shoot, $rawPhoto, $photo));
        $this->assertFalse($policy->canDownloadShootMediaFile($shoot, $image, $photo));
        $this->assertTrue($policy->canInteractWithShootMediaFile($shoot, $image, $photo));
        $this->getJson("/api/shoots/{$shoot->id}/media/{$rawPhoto->id}/download")->assertForbidden();
        $unassigned = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $this->assertFalse($policy->canVideoEditorReadFinalImage($shoot, $image, $unassigned));
        foreach (['quarantined', 'infected', 'failed'] as $status) {
            $image->scan_status = $status;
            $this->assertFalse($policy->canVideoEditorReadFinalImage($shoot, $image, $video));
        }
    }

    public function test_cached_photo_zip_rechecks_assignment_and_mixed_archives_are_denied(): void
    {
        [$shoot, $item, , $photo] = $this->fixture();
        $archives = app(ShootMediaArchiveService::class);
        $type = $archives->buildArchiveTypeToken('edited', false, [], 'photos');
        $archives->generateArchive($shoot, $type, 'original');
        $url = "/api/shoots/{$shoot->id}/media/download-zip?type=edited&asset_type=photos";
        $ready = $this->getJson($url)->assertOk()->assertJsonPath('type', 'redirect')->json('url');
        $this->get($ready)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->getJson("/api/shoots/{$shoot->id}/media/download-zip?type=edited")->assertForbidden();
        $item->update(['video_editor_id' => $photo->id]);
        $this->get($ready)->assertForbidden();
        $this->getJson($url)->assertForbidden();
    }
}

