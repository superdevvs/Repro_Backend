<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{ActiveVideoLinkOutput, ShootActiveWorkLifecycle};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveVideoLinkOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
        $disk = 'active_video_'.bin2hex(random_bytes(6));
        Storage::fake($disk); Storage::set('public', Storage::disk($disk));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_submitting_new_photo_edits_cannot_complete_a_new_video_from_an_old_saved_link(): void
    {
        [$shoot, $photo, $video, $admin] = $this->fixture();
        $this->newPhoto($shoot, $photo, $admin);
        Sanctum::actingAs($admin);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-edited')->assertOk();
        $this->assertNotNull($photo->fresh()->editing_completed_at);
        $this->assertNull($video->fresh()->editing_completed_at);
        $this->assertFalse(app(ActiveVideoLinkOutput::class)->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        (new FinalizeShootJob($shoot->id, $admin->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('not_started', $video->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_changed_saved_link_can_be_submitted_and_delivered_for_its_current_service(): void
    {
        [$shoot, $photo, $video, $admin] = $this->fixture();
        $this->newPhoto($shoot, $photo, $admin);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'tour_links' => ['video_link' => 'https://video.example.test/current-work'],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $this->assertTrue(app(ActiveVideoLinkOutput::class)->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-edited')->assertOk();
        $this->assertNotNull($video->fresh()->editing_completed_at);
        (new FinalizeShootJob($shoot->id, $admin->id, null, $video->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $video->fresh()->delivery_status);
        $this->assertSame('not_started', $photo->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_unchanged_invalid_and_reverted_links_cannot_count_as_current_output(): void
    {
        [$shoot, , $video] = $this->fixture();
        $output = app(ActiveVideoLinkOutput::class);
        $output->recordChangedLinks($shoot);
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->saveLinks($shoot, ['video_link' => 'javascript:alert(1)']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/new']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertTrue($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/old']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
    }

    public function test_two_pending_video_services_do_not_inherit_an_ambiguous_shared_link(): void
    {
        [$shoot, , $video, , $other] = $this->fixture(true);
        // A legacy delivery flag alone does not complete an unfinished workflow.
        $other->forceFill(['delivery_status' => 'delivered'])->saveQuietly();
        $output = app(ActiveVideoLinkOutput::class);
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/ambiguous']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
        // Explicit service scope can own a subsequent changed output.
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/scoped']);
        $output->recordChangedLinks($shoot->fresh(), $video->id);
        $this->assertTrue($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
        $video->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered'])->saveQuietly();
        // Finishing the first line and saving unrelated details cannot grant its URL to the remaining line.
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
    }

    public function test_video_output_receipts_are_bound_to_the_service_and_current_cycle(): void
    {
        [$shoot, , $video, , $other] = $this->fixture(true);
        $output = app(ActiveVideoLinkOutput::class);
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/scoped']);
        $output->recordChangedLinks($shoot->fresh(), $video->id);
        $receipt = $video->fresh()->active_work_link_output;
        $other->forceFill(['active_work_link_output' => $receipt])->saveQuietly();
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
        $this->travel(2)->hours();
        $shoot->forceFill(['active_work_started_at' => now()])->saveQuietly();
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $output->ensureBaseline($shoot->fresh());
        $this->assertNull($video->fresh()->active_work_link_output['reviewed_output']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
    }

    public function test_distinct_units_own_their_video_link_receipts_independently(): void
    {
        [$shoot, , $video, , $other] = $this->fixture(true);
        $old = ['video_link' => 'https://video.example.test/old'];
        $unitA = $shoot->units()->create(['client_key' => 'a', 'label' => 'A', 'kind' => 'unit', 'tour_links' => $old]);
        $unitB = $shoot->units()->create(['client_key' => 'b', 'label' => 'B', 'kind' => 'unit', 'tour_links' => $old]);
        $video->forceFill(['shoot_unit_id' => $unitA->id])->saveQuietly();
        $other->forceFill(['shoot_unit_id' => $unitB->id])->saveQuietly();
        $output = app(ActiveVideoLinkOutput::class);
        $output->ensureBaseline($shoot->fresh());
        $unitA->forceFill(['tour_links' => ['video_link' => 'https://video.example.test/unit-a']])->saveQuietly();
        $output->recordChangedLinks($shoot->fresh());
        $this->assertTrue($output->hasReviewedOutput($shoot->fresh(), $video->fresh()));
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
        $this->saveLinks($shoot, ['video_link' => 'https://video.example.test/whole-order']);
        $output->recordChangedLinks($shoot->fresh());
        $this->assertFalse($output->hasReviewedOutput($shoot->fresh(), $other->fresh()));
    }

    private function fixture(bool $twoVideo = false): array
    {
        $fixture = Model::withoutEvents(function () use ($twoVideo): array {
            $admin = User::factory()->create(['role' => 'superadmin']);
            $editor = User::factory()->create(['role' => 'editor']);
            $shoot = Shoot::factory()->create([
                'status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
                'scheduled_at' => '2026-11-01 15:00:00', 'scheduled_date' => '2026-11-01', 'timezone' => 'America/New_York',
                'admin_verified_at' => '2026-11-02 15:00:00', 'completed_at' => '2026-11-02 15:00:00',
                'editing_completed_at' => '2026-11-02 14:00:00', 'editor_id' => $editor->id,
                'tour_links' => ['video_link' => 'https://video.example.test/old'],
            ]);
            $done = Service::factory()->unbracketedPhoto()->create(['requires_editing' => true]);
            ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $done->id, 'price' => 100,
                'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
                'editor_id' => $editor->id, 'editing_completed_at' => '2026-11-02 14:00:00', 'delivered_at' => '2026-11-02 15:00:00']);
            $photoCatalog = Service::factory()->unbracketedPhoto()->create(['requires_editing' => true]);
            $videoCatalog = Service::factory()->videoIntake()->create(['requires_editing' => true]);
            $common = ['shoot_id' => $shoot->id, 'quantity' => 1, 'price' => 25, 'is_deliverable' => true,
                'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'editor_id' => $editor->id];
            $photo = ShootService::create($common + ['service_id' => $photoCatalog->id]);
            $video = ShootService::create($common + ['service_id' => $videoCatalog->id]);
            $other = $twoVideo ? ShootService::create($common + ['service_id' => Service::factory()->videoIntake()->create(['requires_editing' => true])->id]) : null;
            return [$shoot, $photo, $video, $admin, $other];
        });
        Shoot::withoutEvents(fn () => app(ShootActiveWorkLifecycle::class)->begin($fixture[0]));
        $fixture[0] = $fixture[0]->fresh();
        return $fixture;
    }

    private function newPhoto(Shoot $shoot, ShootService $photo, User $admin): ShootFile
    {
        $this->travel(1)->minutes();
        return ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $photo->id,
            'filename' => 'new-photo.jpg', 'stored_filename' => 'new-photo.jpg', 'path' => 'new-photo.jpg', 'file_size' => 16,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'media_type' => 'edited', 'workflow_stage' => 'completed',
            'scan_status' => 'clean', 'uploaded_by' => $admin->id]);
    }

    private function saveLinks(Shoot $shoot, array $links): void
    {
        $shoot->fresh()->forceFill(['tour_links' => $links])->saveQuietly();
    }
}
