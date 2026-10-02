<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootEditingAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(string $status = 'editing'): array
    {
        Queue::fake();
        Storage::fake('public');
        $photo = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $video = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $shoot = Shoot::factory()->create(['status' => $status, 'workflow_status' => $status, 'editor_id' => null]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->photoVideoIntake()->create(['requires_editing' => true])->id,
            'editor_id' => $photo->id, 'video_editor_id' => $video->id, 'quantity' => 1, 'price' => 100, 'workflow_status' => 'in_progress']);
        return [$shoot, $item, $photo, $video];
    }

    private function editedFile(Shoot $shoot, ShootService $item, User $user, string $lane = 'photo'): ShootFile
    {
        $name = $lane === 'video' ? 'edited.mp4' : 'edited.jpg';
        return ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => $name, 'stored_filename' => $name,
            'path' => "shoots/{$shoot->id}/completed/{$name}", 'file_type' => $lane === 'video' ? 'video/mp4' : 'image/jpeg', 'file_size' => 100,
            'media_type' => $lane === 'video' ? 'video' : 'edited', 'workflow_stage' => 'completed', 'uploaded_by' => $user->id]);
    }

    public function test_photo_and_video_submit_their_own_lanes_before_the_shoot_enters_review(): void
    {
        [$shoot, $item, $photo, $video] = $this->assignment();
        $this->editedFile($shoot, $item, $photo);
        $this->actingAs($photo)->postJson("/api/shoots/{$shoot->id}/upload/finalize-edited")->assertOk()->assertJsonPath('shoot_status', 'editing');
        $this->assertNotNull($item->fresh()->editing_completed_at);
        $this->assertNull($item->fresh()->video_editing_completed_at);
        $this->actingAs($video)->getJson("/api/shoots/{$shoot->id}")->assertOk()->assertJsonPath('data.can_submit_edits', false);
        $this->postJson("/api/shoots/{$shoot->id}/upload/finalize-edited")->assertUnprocessable();
        $this->editedFile($shoot, $item, $video, 'video');
        $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->assertJsonPath('data.can_submit_edits', true);
        $this->postJson("/api/shoots/{$shoot->id}/upload/finalize-edited")->assertOk()->assertJsonPath('shoot_status', 'review');
        $this->assertNotNull($item->fresh()->video_editing_completed_at);
    }

    public function test_pending_video_can_submit_saved_links_after_photo_delivery_without_reopening_delivery(): void
    {
        [$shoot, $item, $photo, $video] = $this->assignment('delivered');
        $item->update(['editing_completed_at' => now()]);
        $shoot->update(['tour_links' => ['video_link' => 'https://video.example.test/tour']]);
        $this->actingAs($video)->getJson("/api/shoots/{$shoot->id}")->assertOk()->assertJsonPath('data.can_submit_edits', true);
        $this->postJson("/api/shoots/{$shoot->id}/upload/finalize-edited")->assertOk()->assertJsonPath('shoot_status', 'delivered')->assertJsonPath('editing_submission_changed', true);
        $this->assertNotNull($item->fresh()->video_editing_completed_at);
        $this->getJson("/api/shoots/{$shoot->id}")->assertOk()->assertJsonPath('data.can_submit_edits', false);
        $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson("/api/shoots/{$shoot->id}/upload/finalize-edited")->assertForbidden();
    }

    public function test_staff_finalization_completes_verified_photos_but_leaves_video_assignment_open(): void
    {
        [$shoot, $item, $photo, $video] = $this->assignment();
        $this->editedFile($shoot, $item, $photo);
        $admin = User::factory()->create(['role' => 'admin']);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertNotNull($item->fresh()->editing_completed_at);
        $this->assertNull($item->fresh()->video_editing_completed_at);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
    }

    public function test_explicit_drone_editing_routes_raw_photos_to_photo_editor_without_changing_catalog(): void
    {
        [$shoot, $item, $photo, $video] = $this->assignment('uploaded');
        $item->service->update(['requires_editing' => false, 'upload_intake_type' => 'photo', 'name' => 'Drone photos']);
        $item->update(['editor_id' => null, 'video_editor_id' => null]);
        $file = $this->editedFile($shoot, $item, $photo);
        $file->update(['workflow_stage' => 'todo', 'media_type' => 'raw', 'required_for_editing' => false]);
        app(\App\Services\ShootWorkflowService::class)->startEditing($shoot, User::factory()->create(['role' => 'admin']));
        $this->assertSame($photo->id, (int) $item->fresh()->editor_id);
        $this->assertTrue($file->fresh()->required_for_editing);
        $this->assertFalse($item->service->fresh()->requires_editing);
        $this->assertTrue(app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot->fresh(), $file->fresh(), $photo));
        $this->actingAs($photo)->getJson("/api/shoots/{$shoot->id}/files?type=raw")->assertOk()->assertJsonCount(1, 'data');
    }
}
