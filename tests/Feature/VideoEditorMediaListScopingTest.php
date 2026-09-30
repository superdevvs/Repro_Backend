<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\ShootShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoEditorMediaListScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_only_editor_sees_raw_video_but_not_photo_media_or_photo_share_links(): void
    {
        $photoEditor = User::factory()->create(['role' => 'editor']);
        $photoEditor->setEditingCapabilities(['photo']);
        $photoEditor->save();

        $videoEditor = User::factory()->create(['role' => 'editor']);
        $videoEditor->setEditingCapabilities(['video']);
        $videoEditor->save();

        $shoot = Shoot::factory()->create(['editor_id' => null, 'status' => Shoot::STATUS_EDITING]);
        $service = Service::factory()->photoVideoIntake()->create(['requires_editing' => true]);
        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'editor_id' => $photoEditor->id,
            'video_editor_id' => $videoEditor->id,
            'price' => 100,
            'quantity' => 1,
        ]);

        $rawPhoto = ShootFile::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'filename' => 'bracket-01.CR3',
            'stored_filename' => 'bracket-01.CR3',
            'path' => 'shoots/'.$shoot->id.'/todo/bracket-01.CR3',
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'file_type' => 'image/x-canon-cr3',
            'file_size' => 100,
            'uploaded_by' => $photoEditor->id,
        ]);
        $rawVideo = ShootFile::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'filename' => 'clip-01.MP4',
            'stored_filename' => 'clip-01.MP4',
            'path' => 'shoots/'.$shoot->id.'/todo/clip-01.MP4',
            'media_type' => 'video',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'file_type' => 'video/mp4',
            'file_size' => 100,
            'uploaded_by' => $photoEditor->id,
        ]);
        $editedPhoto = ShootFile::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'filename' => 'final-01.jpg',
            'stored_filename' => 'final-01.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/final-01.jpg',
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'file_type' => 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $photoEditor->id,
        ]);
        $editedVideo = ShootFile::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $item->id,
            'filename' => 'walkthrough.mp4',
            'stored_filename' => 'walkthrough.mp4',
            'path' => 'shoots/'.$shoot->id.'/completed/walkthrough.mp4',
            'media_type' => 'video',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'file_type' => 'video/mp4',
            'file_size' => 100,
            'uploaded_by' => $videoEditor->id,
        ]);

        ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $photoEditor->id,
            'share_url' => 'https://example.test/photo-raw',
            'media_stage' => 'raw_photo',
            'public_token' => str_repeat('p', 64),
            'expires_at' => now()->addDay(),
        ]);
        $videoLink = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $videoEditor->id,
            'share_url' => 'https://example.test/video-raw',
            'media_stage' => 'raw_video',
            'public_token' => str_repeat('v', 64),
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($videoEditor);

        $raw = $this->getJson("/api/shoots/{$shoot->id}/files?type=raw");
        $raw->assertOk();
        $rawIds = collect($raw->json('data'))->pluck('id')->all();
        $this->assertContains($rawVideo->id, $rawIds, 'Video editor must see raw VIDEO uploads');
        $this->assertNotContains($rawPhoto->id, $rawIds);
        $this->assertNotContains($editedPhoto->id, $rawIds);

        $edited = $this->getJson("/api/shoots/{$shoot->id}/files?type=edited");
        $edited->assertOk();
        $editedIds = collect($edited->json('data'))->pluck('id')->all();
        $this->assertContains($editedVideo->id, $editedIds);
        $this->assertNotContains($editedPhoto->id, $editedIds);
        $this->assertNotContains($rawPhoto->id, $editedIds);

        $media = $this->getJson("/api/shoots/{$shoot->id}/media?type=edited");
        $media->assertOk();
        $mediaIds = collect($media->json('data'))->pluck('id')->all();
        $this->assertContains($editedVideo->id, $mediaIds);
        $this->assertNotContains($editedPhoto->id, $mediaIds);

        $links = $this->getJson("/api/shoots/{$shoot->id}/share-links");
        $links->assertOk();
        $linkIds = collect($links->json('data'))->pluck('id')->all();
        $this->assertSame([$videoLink->id], $linkIds);
    }
}
