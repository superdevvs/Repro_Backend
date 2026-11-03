<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\PublishShootToBrightMlsJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootActiveWorkLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinalizeServiceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'timezone' => 'America/New_York',
            'scheduled_at' => now()->subDay(),
            'scheduled_date' => '2026-11-02',
        ]);
        $drone = $this->item($shoot, ['scheduled_at' => now()->subDay()]);
        $hdr = $this->item($shoot, ['scheduled_at' => now()->addDay()]);
        return [$shoot, $drone, $hdr, $admin];
    }

    private function item(Shoot $shoot, array $attributes = []): ShootService
    {
        return $shoot->serviceItems()->create($attributes + [
            'service_id' => Service::factory()->unbracketedPhoto()->create()->id,
            'price' => 100, 'quantity' => 1,
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            'delivery_status' => ShootService::DELIVERY_NOT_STARTED,
            'is_deliverable' => true,
        ]);
    }

    private function file(Shoot $shoot, ?ShootService $item, User $admin, array $attributes = []): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill($attributes + [
            'shoot_id' => $shoot->id, 'shoot_service_id' => $item?->id,
            'filename' => 'delivered.jpg', 'stored_filename' => 'delivered.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/delivered.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 1024,
            'media_type' => 'edited', 'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ])->save();
        return $file;
    }

    public function test_whole_order_delivery_finishes_only_the_service_with_output_and_keeps_future_visit_active(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $originalDate = $shoot->scheduled_date->toDateString();
        $file = $this->file($shoot, $drone, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));

        $this->assertSame('delivered', $drone->fresh()->delivery_status);
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertNull($hdr->fresh()->delivered_at);
        $this->assertSame('active', $shoot->fresh()->status);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('2026-11-04', $shoot->fresh()->active_work_date);
        $this->assertSame($originalDate, $shoot->fresh()->scheduled_date->toDateString());
        $this->assertSame(ShootFile::STAGE_VERIFIED, $file->fresh()->workflow_stage);
        Queue::assertNotPushed(PublishShootToBrightMlsJob::class);
        Queue::assertNotPushed(SendShootReadyEmailJob::class, fn ($job) => $job->isFullOrderDelivery);
    }

    public function test_future_service_cannot_be_delivered_even_when_files_are_mistakenly_assigned_to_it(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $file = $this->file($shoot, $hdr, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id, null, $hdr->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame('not_started', $drone->fresh()->delivery_status);
        $this->assertSame(ShootFile::STAGE_COMPLETED, $file->fresh()->workflow_stage);
        $this->assertDatabaseHas('workflow_logs', ['shoot_id' => $shoot->id, 'action' => 'finalize_failed']);
    }

    public function test_other_service_files_and_old_completion_timestamps_do_not_deliver_an_unfinished_line(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $hdr->forceFill(['scheduled_at' => now()->subHour(), 'ready_at' => now()->subDays(2),
            'delivered_at' => now()->subDays(2), 'editing_completed_at' => now()->subDays(2)])->save();
        $this->file($shoot, $drone, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_final_additional_service_closes_cycle_without_rewriting_original_delivery_history(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $originalDelivery = now()->subDays(2);
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => $originalDelivery])->save();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered',
            'admin_verified_at' => $originalDelivery, 'completed_at' => $originalDelivery])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->travel(1)->minutes();
        $this->file($shoot, $hdr, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertNotNull($shoot->fresh()->active_work_completed_at);
        $this->assertEquals($originalDelivery, $shoot->fresh()->admin_verified_at);
        $this->assertEquals($originalDelivery, $shoot->fresh()->completed_at);
        $this->assertSame('delivered', $hdr->fresh()->delivery_status);
    }

    public function test_single_service_legacy_unscoped_output_remains_deliverable(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $hdr->delete();
        $file = $this->file($shoot, null, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertSame('delivered', $drone->fresh()->delivery_status);
        $this->assertSame(ShootFile::STAGE_VERIFIED, $file->fresh()->workflow_stage);
    }

    public function test_unscoped_legacy_output_cannot_guess_between_two_services(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        $file = $this->file($shoot, null, $admin);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $drone->fresh()->delivery_status);
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame(ShootFile::STAGE_COMPLETED, $file->fresh()->workflow_stage);
    }

    public function test_verified_output_from_an_old_cycle_does_not_complete_current_work(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        $file = $this->file($shoot, $hdr, $admin, ['created_at' => now()->subDay(),
            'workflow_stage' => ShootFile::STAGE_VERIFIED, 'verified_at' => now()->subDay(), 'is_ai_edited' => true]);
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
        $this->assertEquals(now()->subDay(), $file->fresh()->verified_at);
    }

    public function test_a_current_published_replacement_is_fresh_evidence_even_if_the_file_id_is_older(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        $file = $this->file($shoot, $hdr, $admin, ['created_at' => now()->subDay(), 'content_version' => 2]);
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        ShootFileVersion::create(['shoot_id' => $shoot->id, 'source_file_id' => $file->id,
            'target_file_id' => $file->id, 'published_file_id' => $file->id, 'expected_version' => 1,
            'version' => 2, 'request_key' => 'current-cycle-replacement', 'status' => 'published', 'created_by' => $admin->id]);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $hdr->fresh()->delivery_status);
        $this->assertSame('delivered', $shoot->fresh()->status);
    }

    public function test_reviewed_current_video_link_can_finish_its_scoped_service_while_old_photos_remain(): void
    {
        [$shoot, $drone, $video, $admin] = $this->fixture();
        $catalog = Service::factory()->videoIntake()->create(['requires_editing' => true]);
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $video->forceFill(['service_id' => $catalog->id, 'scheduled_at' => now()->subHour()])->save();
        $old = $this->file($shoot, $drone, $admin, ['workflow_stage' => 'verified', 'verified_at' => now()->subDay(), 'created_at' => now()->subDay()]);
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/shoots/'.$shoot->id, ['tour_links' => ['video_link' => 'https://video.example.test/current-edit'],
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $video->forceFill(['editing_completed_at' => now(), 'workflow_status' => 'ready'])->save();
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', ['shoot_service_id' => $video->id])->assertAccepted();
        (new FinalizeShootJob($shoot->id, $admin->id, null, $video->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $video->fresh()->delivery_status);
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertEquals(now()->subDay(), $old->fresh()->verified_at);
    }

    public function test_old_video_completion_and_saved_link_cannot_finish_new_work(): void
    {
        [$shoot, $drone, $video, $admin] = $this->fixture();
        $catalog = Service::factory()->videoIntake()->create(['requires_editing' => true]);
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $video->forceFill(['service_id' => $catalog->id, 'scheduled_at' => now()->subHour(),
            'editing_completed_at' => now()->subDay()])->save();
        $shoot->forceFill(['tour_links' => ['video_link' => 'https://video.example.test/previous-edit']])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        (new FinalizeShootJob($shoot->id, $admin->id, null, $video->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $video->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_old_verified_media_cannot_be_restamped_as_current_bundle_editing_completion(): void
    {
        [$shoot, $drone, $bundle, $admin] = $this->fixture();
        $catalog = Service::factory()->photoVideoIntake()->create(['requires_editing' => true]);
        $bundle->forceFill(['service_id' => $catalog->id, 'scheduled_at' => now()->subHour()])->save();
        $this->file($shoot, $bundle, $admin, ['created_at' => now()->subDay(),
            'workflow_stage' => 'verified', 'verified_at' => now()->subDay()]);
        $this->file($shoot, $bundle, $admin, ['created_at' => now()->subDay(), 'filename' => 'old-video.mp4',
            'media_type' => 'video', 'file_type' => 'video/mp4', 'workflow_stage' => 'verified', 'verified_at' => now()->subDay()]);
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $editing = app(\App\Services\Shoots\ShootEditingAssignmentService::class);
        $editing->markAssignedServicesReadyForUser($shoot, $admin);
        $editing->markVerifiedLanesComplete($shoot, $bundle->id);
        $this->assertNull($bundle->fresh()->editing_completed_at);
        $this->assertNull($bundle->fresh()->video_editing_completed_at);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_original_delivered_images_do_not_enable_or_submit_additional_edits(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $this->file($shoot, $drone, $admin, ['created_at' => now()->subDay(),
            'workflow_stage' => 'verified', 'verified_at' => now()->subDay()]);
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->assertFalse(app(\App\Services\Shoots\ShootSubmissionCapabilityService::class)->canSubmitEdits($shoot, $admin));
        Sanctum::actingAs($admin);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-edited')->assertUnprocessable();
        $this->assertNull($hdr->fresh()->editing_completed_at);
        $this->assertSame('active', $shoot->fresh()->workflow_status);
    }

    public function test_current_additional_edits_can_be_submitted_while_another_service_has_moved_the_parent_to_ready(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        $hdr->service->forceFill(['requires_editing' => true])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->travel(1)->minutes();
        $this->file($shoot, $hdr, $admin);
        $shoot->refresh()->forceFill(['workflow_status' => 'ready', 'editing_completed_at' => now()])->save();
        $this->assertTrue(app(\App\Services\Shoots\ShootSubmissionCapabilityService::class)->canSubmitEdits($shoot, $admin));
        Sanctum::actingAs($admin);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-edited')->assertOk();
        $this->assertNotNull($hdr->fresh()->editing_completed_at);
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_legacy_bookings_use_the_existing_photographer_timezone_when_placing_new_work(): void
    {
        [$shoot, $drone, $hdr] = $this->fixture();
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $shoot->forceFill(['timezone' => null, 'photographer_id' => $photographer->id])->save();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $hdr->forceFill(['scheduled_at' => '2026-11-04 23:30:00', 'photographer_id' => $photographer->id])->save();
        $lifecycle = app(ShootActiveWorkLifecycle::class);
        $lifecycle->begin($shoot);
        $shoot->refresh();
        $this->assertSame('2026-11-04', $shoot->active_work_date);
        $this->assertSame('23:30', $shoot->active_work_time);
        $this->assertSame('2026-11-04', $lifecycle->presentation($shoot)['active_work_date']);
    }

    public function test_new_submitted_raw_invalidates_older_same_cycle_edits_but_allows_a_current_replacement(): void
    {
        [$shoot, $drone, $hdr, $admin] = $this->fixture();
        $drone->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->save();
        $hdr->forceFill(['scheduled_at' => now()->subHour()])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->travel(1)->minutes();
        $edited = $this->file($shoot, $hdr, $admin);
        $this->travel(1)->minutes();
        $this->file($shoot, $hdr, $admin, ['media_type' => 'raw', 'workflow_stage' => 'todo',
            'metadata' => ['active_work_raw_submission' => ['cycle' => $shoot->active_work_started_at->toIso8601String(),
                'submitted_at' => now()->toIso8601String(), 'content_version' => 1]]]);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $hdr->fresh()->delivery_status);
        $this->assertSame('completed', $edited->fresh()->workflow_stage);
        $this->travel(1)->minutes();
        $edited->forceFill(['content_version' => 2])->save();
        ShootFileVersion::create(['shoot_id' => $shoot->id, 'source_file_id' => $edited->id,
            'target_file_id' => $edited->id, 'published_file_id' => $edited->id, 'expected_version' => 1,
            'version' => 2, 'request_key' => 'replacement-after-new-raw', 'status' => 'published', 'created_by' => $admin->id]);
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $hdr->fresh()->delivery_status);
        $this->assertSame('delivered', $shoot->fresh()->status);
    }
}
