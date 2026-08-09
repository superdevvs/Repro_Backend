<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootFinalizeProgressTest extends TestCase
{
    use RefreshDatabase;

    private function tracker(): FinalizeProgressTracker
    {
        return app(FinalizeProgressTracker::class);
    }

    private function makeFinalizableShoot(User $admin): Shoot
    {
        $shoot = Shoot::factory()->create([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'service_id' => Service::factory()->create()->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/final.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        return $shoot;
    }

    public function test_finalize_request_seeds_progress_document(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeFinalizableShoot($admin);

        Sanctum::actingAs($admin);

        $this->postJson('/api/shoots/' . $shoot->id . '/finalize')
            ->assertAccepted()
            ->assertJsonPath('progress.status', FinalizeProgressTracker::STATUS_RUNNING)
            ->assertJsonPath('progress.stages.0.key', FinalizeProgressTracker::STAGE_QUEUED)
            ->assertJsonPath('progress.stages.0.status', FinalizeProgressTracker::STATUS_COMPLETED)
            ->assertJsonPath('progress.stages.1.status', FinalizeProgressTracker::STATUS_PENDING);

        $this->getJson('/api/shoots/' . $shoot->id . '/finalize-progress')
            ->assertOk()
            ->assertJsonPath('data.shoot_id', $shoot->id)
            ->assertJsonPath('data.status', FinalizeProgressTracker::STATUS_RUNNING)
            ->assertJsonCount(5, 'data.stages');
    }

    public function test_progress_endpoint_returns_null_when_nothing_is_tracked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/shoots/' . $shoot->id . '/finalize-progress')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_progress_endpoint_is_forbidden_for_non_finalizers(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create();

        Sanctum::actingAs($photographer);

        $this->getJson('/api/shoots/' . $shoot->id . '/finalize-progress')->assertForbidden();
    }

    public function test_finalize_job_completes_progress_when_no_side_effect_work_remains(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->makeFinalizableShoot($admin);

        $this->tracker()->start($shoot->id);
        (new FinalizeShootJob($shoot->id, $admin->id, 'admin_verified'))
            ->handle(app(ShootActivityLogger::class), $this->tracker());

        $progress = $this->tracker()->get($shoot->id);

        $this->assertNotNull($progress);
        $this->assertSame(FinalizeProgressTracker::STATUS_COMPLETED, $progress['stages'][1]['status']);
        // Nothing to cache locally (no dropbox_path) so that stage is skipped.
        $this->assertSame(FinalizeProgressTracker::STATUS_SKIPPED, $progress['stages'][2]['status']);
        // MLS + delivery email are queued jobs that report their own outcome,
        // so the run is still in flight at this point.
        $this->assertSame(FinalizeProgressTracker::STATUS_RUNNING, $progress['status']);
        $this->assertGreaterThan(0, $progress['percentage']);
        $this->assertLessThan(100, $progress['percentage']);
    }

    public function test_failed_finalize_marks_progress_failed_with_reason(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);

        $this->tracker()->start($shoot->id);
        (new FinalizeShootJob($shoot->id, $admin->id, 'admin_verified'))
            ->handle(app(ShootActivityLogger::class), $this->tracker());

        $progress = $this->tracker()->get($shoot->id);

        $this->assertSame(FinalizeProgressTracker::STATUS_FAILED, $progress['status']);
        $this->assertSame('No edited files to finalize', $progress['error']);
    }

    public function test_countable_stage_reports_a_real_fraction_and_terminates_the_run(): void
    {
        $shootId = 4242;
        $tracker = $this->tracker();

        $tracker->start($shootId);
        $tracker->stageCompleted($shootId, FinalizeProgressTracker::STAGE_COMMIT);
        $tracker->stageRunning($shootId, FinalizeProgressTracker::STAGE_LOCAL_CACHE, 'Caching 4 file(s)', 4);

        $tracker->stageAdvanced($shootId, FinalizeProgressTracker::STAGE_LOCAL_CACHE);
        $partial = $tracker->get($shootId);
        $this->assertSame(1, $partial['stages'][2]['processed']);
        $this->assertSame(4, $partial['stages'][2]['total']);
        $this->assertFalse($partial['stages'][2]['indeterminate']);
        $this->assertSame(FinalizeProgressTracker::STATUS_RUNNING, $partial['status']);

        $tracker->stageAdvanced($shootId, FinalizeProgressTracker::STAGE_LOCAL_CACHE, 3);
        $tracker->stageSkipped($shootId, FinalizeProgressTracker::STAGE_MLS_PUBLISH);
        $tracker->stageFailed($shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'SMTP down');

        $done = $tracker->get($shootId);
        $this->assertSame(FinalizeProgressTracker::STATUS_COMPLETED, $done['status']);
        $this->assertSame(100, $done['percentage']);
        $this->assertSame('Finalize complete with warnings', $done['message']);
        $this->assertCount(1, $done['failures']);
        $this->assertSame(FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, $done['failures'][0]['stage']);

        $tracker->forget($shootId);
        $this->assertNull($tracker->get($shootId));
    }

    public function test_running_stage_without_a_unit_count_is_indeterminate(): void
    {
        $shootId = 5252;
        $tracker = $this->tracker();

        $tracker->start($shootId);
        $tracker->stageRunning($shootId, FinalizeProgressTracker::STAGE_COMMIT);

        $progress = $tracker->get($shootId);

        $this->assertTrue($progress['indeterminate']);
        $this->assertTrue($progress['stages'][1]['indeterminate']);
        // Only the queued stage weight has been earned so far — the running
        // stage contributes nothing rather than a made-up fraction.
        $this->assertSame(5, $progress['percentage']);
    }

    public function test_updates_for_an_untracked_shoot_are_a_no_op(): void
    {
        $tracker = $this->tracker();
        $tracker->stageCompleted(999999, FinalizeProgressTracker::STAGE_COMMIT);

        $this->assertNull($tracker->get(999999));
    }
}
