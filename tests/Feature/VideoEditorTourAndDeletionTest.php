<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoEditorTourAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(): array
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        $video = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $photo = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = Shoot::factory()->create([
            'editor_id' => null, 'status' => Shoot::STATUS_REVIEW, 'workflow_status' => Shoot::STATUS_REVIEW,
            'submitted_for_review_at' => now(), 'tour_links' => ['matterport' => 'https://tour.test/3d', 'property_mls' => 'ORIGINAL'],
        ]);
        $item = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => Service::factory()->photoVideoIntake()->create(['requires_editing' => true])->id,
            'editor_id' => $photo->id, 'video_editor_id' => $video->id, 'editing_completed_at' => now(),
            'quantity' => 1, 'price' => 100,
        ]);

        return [$shoot, $item, $video, $photo];
    }

    private function video(Shoot $shoot, ShootService $item, User $uploader, array $attributes = []): ShootFile
    {
        $name = uniqid('video-').'.mp4';
        $path = "shoots/{$shoot->id}/completed/{$name}";
        Storage::disk('public')->put($path, 'fixture-video');

        return ShootFile::create(array_replace([
            'shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => $name, 'stored_filename' => $name,
            'path' => $path, 'storage_path' => $path, 'file_type' => 'video/mp4', 'file_size' => 13,
            'media_type' => 'video', 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'uploaded_by' => $uploader->id,
        ], $attributes));
    }

    public function test_only_assigned_video_editors_can_merge_video_links_and_other_tour_fields_survive(): void
    {
        [$shoot, $item, $video, $photo] = $this->assignment();
        $links = array_fill_keys(['video_link', 'video_branded', 'video_mls', 'video_generic'], 'https://video.test/walkthrough');
        $this->actingAs($video)->patchJson("/api/shoots/{$shoot->id}", ['tour_links' => $links])->assertOk();
        $this->assertSame(array_merge(['matterport' => 'https://tour.test/3d', 'property_mls' => 'ORIGINAL'], $links), $shoot->fresh()->tour_links);
        foreach (['matterport', 'branded', 'property_description', 'tour_style', 'autoplay'] as $key) {
            $this->patchJson("/api/shoots/{$shoot->id}", ['tour_links' => [$key => 'changed']])->assertForbidden();
        }
        $this->patchJson("/api/shoots/{$shoot->id}", ['address' => 'forbidden', 'tour_links' => $links])->assertForbidden();
        foreach ([$photo, User::factory()->create(['role' => 'editor']), $shoot->client] as $actor) {
            $this->actingAs($actor)->patchJson("/api/shoots/{$shoot->id}", ['tour_links' => $links])->assertForbidden();
        }
        $photo->update(['metadata' => ['editing_capabilities' => ['photo', 'video']]]);
        $this->actingAs($photo)->patchJson("/api/shoots/{$shoot->id}", ['tour_links' => $links])->assertForbidden();
    }

    public function test_video_tour_updates_are_scoped_to_the_assigned_unit_and_cannot_invoke_providers(): void
    {
        [$shoot, $item, $video, $photo] = $this->assignment();
        $unit = $shoot->units()->create(['client_key' => 'unit-a', 'label' => 'A', 'tour_links' => ['matterport' => 'https://tour.test/unit-a']]);
        $other = $shoot->units()->create(['client_key' => 'unit-b', 'label' => 'B']);
        $item->update(['shoot_unit_id' => $unit->id]);
        ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $other->id, 'service_id' => $item->service_id, 'editor_id' => $photo->id, 'video_editor_id' => $photo->id, 'quantity' => 1, 'price' => 100]);
        $payload = ['tour_links' => ['video_link' => 'https://video.test/unit-a', 'video_mls' => 'https://video.test/unit-a-mls']];
        $this->actingAs($video)->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)
            ->assertOk()->assertJsonPath('data.tour_links.video_link', 'https://video.test/unit-a');
        $this->assertSame('https://tour.test/unit-a', $unit->fresh()->tour_links['matterport']);
        $this->assertArrayNotHasKey('video_link', $shoot->fresh()->tour_links);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$other->id}/tour", $payload)->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload + ['bedrooms' => 5])->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", ['tour_links' => ['matterport' => 'changed']])->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/units/{$unit->id}/iguide/sync", [])->assertForbidden();
        $this->actingAs($photo)->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)->assertForbidden();
        $photo->update(['metadata' => ['editing_capabilities' => ['photo', 'video']]]);
        $this->actingAs($photo)->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)->assertForbidden();
        $this->actingAs($shoot->client)->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)->assertForbidden();
    }

    public function test_own_unverified_video_can_be_deleted_during_review_and_capability_matches_both_lists(): void
    {
        [$shoot, $item, $video] = $this->assignment();
        $item->update(['video_editing_completed_at' => now()]);
        $file = $this->video($shoot, $item, $video);
        $this->actingAs($video);
        foreach (['files', 'media'] as $endpoint) {
            $this->getJson("/api/shoots/{$shoot->id}/{$endpoint}?type=edited")->assertOk()->assertJsonPath('data.0.can_delete', true);
        }
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$file->id}")->assertOk();
        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        Storage::disk('public')->assertMissing($file->path);
    }

    public function test_delivered_photos_do_not_lock_the_unfinished_video_lane_but_completed_delivery_does(): void
    {
        [$shoot, $item, $video] = $this->assignment();
        $shoot->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $item->update(['delivery_status' => ShootService::DELIVERY_DELIVERED, 'delivered_at' => now()]);
        $file = $this->video($shoot, $item, $video);
        $this->actingAs($video)->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonPath('data.0.can_delete', true);
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$file->id}")->assertOk();
        $finished = $this->video($shoot, $item, $video);
        $item->update(['video_editing_completed_at' => now()]);
        $this->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonPath('data.0.can_delete', false);
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$finished->id}")->assertForbidden();
        $this->assertDatabaseHas('shoot_files', ['id' => $finished->id]);
    }

    public function test_ownership_raw_photo_verified_and_unassigned_boundaries_are_enforced(): void
    {
        [$shoot, $item, $video, $photo] = $this->assignment();
        $foreign = $this->video($shoot, $item, $photo);
        $raw = $this->video($shoot, $item, $video, ['workflow_stage' => ShootFile::STAGE_TODO]);
        $verified = $this->video($shoot, $item, $video, ['workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now()]);
        $image = $this->video($shoot, $item, $video, ['filename' => 'photo.jpg', 'media_type' => 'edited', 'file_type' => 'image/jpeg']);
        $this->actingAs($video);
        foreach ([$foreign, $raw, $verified, $image] as $file) {
            $this->deleteJson("/api/shoots/{$shoot->id}/media/{$file->id}")->assertForbidden();
            $this->assertDatabaseHas('shoot_files', ['id' => $file->id]);
        }
        $own = $this->video($shoot, $item, $video);
        foreach ([$photo, User::factory()->create(['role' => 'editor']), $shoot->client] as $actor) {
            $this->actingAs($actor)->deleteJson("/api/shoots/{$shoot->id}/media/{$own->id}")->assertForbidden();
        }
        $shoot->update(['status' => Shoot::STATUS_CANCELLED, 'workflow_status' => Shoot::STATUS_CANCELLED]);
        $this->actingAs($video)->deleteJson("/api/shoots/{$shoot->id}/media/{$own->id}")->assertForbidden();
    }

    public function test_bulk_delete_authorizes_every_file_before_deleting_any_and_rejects_foreign_shoot_ids(): void
    {
        [$shoot, $item, $video, $photo] = $this->assignment();
        $own = $this->video($shoot, $item, $video);
        $other = $this->video($shoot, $item, $photo);
        $this->actingAs($video)->postJson("/api/shoots/{$shoot->id}/media/bulk-delete", ['ids' => [$own->id, $other->id]])->assertForbidden();
        $this->assertDatabaseHas('shoot_files', ['id' => $own->id]);
        [$otherShoot, $otherItem] = $this->assignment();
        $foreign = $this->video($otherShoot, $otherItem, $video);
        $this->postJson("/api/shoots/{$shoot->id}/media/bulk-delete", ['ids' => [$own->id, $foreign->id]])->assertNotFound();
        $this->assertDatabaseHas('shoot_files', ['id' => $own->id]);
        $this->postJson("/api/shoots/{$shoot->id}/media/bulk-delete", ['ids' => [$own->id]])->assertOk();
        $this->assertDatabaseMissing('shoot_files', ['id' => $own->id]);
        $this->assertDatabaseHas('shoot_files', ['id' => $other->id]);
        $this->assertDatabaseHas('shoot_files', ['id' => $foreign->id]);
    }

    public function test_dual_editor_photo_permissions_keep_the_existing_submission_boundary(): void
    {
        [$shoot, $item, $video] = $this->assignment();
        $video->update(['metadata' => ['editing_capabilities' => ['photo', 'video']]]);
        $item->update(['editor_id' => $video->id, 'editing_completed_at' => null]);
        $shoot->update(['status' => Shoot::STATUS_EDITING, 'workflow_status' => Shoot::STATUS_EDITING, 'submitted_for_review_at' => null]);
        $file = $this->video($shoot, $item, $video, ['filename' => 'photo.jpg', 'media_type' => 'edited', 'file_type' => 'image/jpeg']);
        $this->actingAs($video)->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonPath('data.0.can_delete', true);
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$file->id}")->assertOk();
        $submitted = $this->video($shoot, $item, $video, ['filename' => 'submitted.jpg', 'media_type' => 'edited', 'file_type' => 'image/jpeg']);
        $shoot->update(['submitted_for_review_at' => now()]);
        $this->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk()->assertJsonPath('data.0.can_delete', false);
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$submitted->id}")->assertForbidden();
    }

    public function test_video_only_line_delivery_and_legacy_delivered_uploads_stay_protected(): void
    {
        [$shoot, $item, $video] = $this->assignment();
        $item->service->update(['upload_intake_type' => Service::INTAKE_VIDEO]);
        $item->update(['editor_id' => $video->id, 'video_editor_id' => null, 'editing_completed_at' => null, 'delivery_status' => ShootService::DELIVERY_DELIVERED]);
        $file = $this->video($shoot, $item, $video);
        $this->actingAs($video)->deleteJson("/api/shoots/{$shoot->id}/media/{$file->id}")->assertForbidden();
        $shoot->update(['editor_id' => $video->id, 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $legacy = $this->video($shoot, $item, $video, ['shoot_service_id' => null]);
        $this->deleteJson("/api/shoots/{$shoot->id}/media/{$legacy->id}")->assertForbidden();
    }

    public function test_legacy_unit_editor_fallback_cannot_cross_an_explicit_unit_assignment(): void
    {
        [$shoot, $item, $video, $photo] = $this->assignment();
        $shoot->update(['editor_id' => $video->id]);
        $unit = $shoot->units()->create(['client_key' => 'legacy-unit', 'label' => 'Legacy']);
        $item->update(['shoot_unit_id' => $unit->id, 'editor_id' => null, 'video_editor_id' => null]);
        $payload = ['tour_links' => ['video_link' => 'https://video.test/legacy']];
        $this->actingAs($video)->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)->assertOk();
        ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'service_id' => Service::factory()->photoVideoIntake()->create()->id, 'editor_id' => $photo->id, 'quantity' => 1, 'price' => 50]);
        $this->patchJson("/api/shoots/{$shoot->id}/units/{$unit->id}/tour", $payload)->assertForbidden();
    }

    public function test_listing_more_editor_videos_does_not_add_per_file_assignment_queries(): void
    {
        [$shoot, $item, $video] = $this->assignment();
        $this->video($shoot, $item, $video);
        $this->actingAs($video);
        $countQueries = function () use ($shoot): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson("/api/shoots/{$shoot->id}/media?type=edited")->assertOk();
            $count = count(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "shoot_service"')));
            DB::disableQueryLog();

            return $count;
        };
        $small = $countQueries();
        for ($index = 0; $index < 12; $index++) {
            $this->video($shoot, $item, $video);
        }
        $this->assertLessThanOrEqual($small + 2, $countQueries(), 'Editor capabilities must reuse loaded service assignments across the gallery.');
    }
}
