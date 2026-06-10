<?php

namespace Tests\Feature;

use App\Jobs\GenerateReel;
use App\Models\AiReelJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_reel(): void
    {
        $this->postJson('/api/reels/generate', [])
            ->assertUnauthorized();
    }

    public function test_rejects_empty_media_selection(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/reels/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => [],
        ])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, AiReelJob::count());
    }

    public function test_rejects_photos_from_another_shoot(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $otherShoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $selectedIds = $this->createShootFiles($shoot, 2, $admin)
            ->pluck('id')
            ->push($this->createShootFiles($otherShoot, 1, $admin)->first()->id)
            ->all();

        $this->postJson('/api/reels/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $selectedIds,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, AiReelJob::count());
    }

    public function test_valid_submit_creates_queued_job_and_dispatches_generate(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $files = $this->createShootFiles($shoot, 3, $admin);
        $selectedIds = $files->pluck('id')->all();

        $response = $this->postJson('/api/reels/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $selectedIds,
        ])->assertCreated();

        $jobId = $response->json('data.id');
        $this->assertNotNull($jobId);

        $job = AiReelJob::findOrFail($jobId);
        $this->assertSame($selectedIds, $job->selected_file_ids);
        $this->assertSame(AiReelJob::STATUS_QUEUED, $job->status);
        $this->assertSame($admin->id, $job->user_id);
        $this->assertSame($shoot->id, $job->shoot_id);

        $this->assertSame(1, AiReelJob::count());

        Queue::assertPushed(GenerateReel::class);
    }

    public function test_status_endpoint_reflects_job_state(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $files = $this->createShootFiles($shoot, 3, $admin);

        $jobId = $this->postJson('/api/reels/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $files->pluck('id')->all(),
        ])->assertCreated()->json('data.id');

        // Freshly created job is queued.
        $this->getJson("/api/reels/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.status', AiReelJob::STATUS_QUEUED);

        // Advance job state and confirm the status endpoint reflects it.
        $job = AiReelJob::findOrFail($jobId);
        $job->markAsProcessing();

        $this->getJson("/api/reels/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.status', AiReelJob::STATUS_PROCESSING);

        $job->markAsCompleted(['reel_url' => 'https://example.com/reel.mp4']);

        $this->getJson("/api/reels/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.status', AiReelJob::STATUS_COMPLETED);
    }

    private function createShootFiles(Shoot $shoot, int $count, User $uploadedBy)
    {
        return collect(range(1, $count))->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "photo-{$index}.jpg",
            'stored_filename' => "photo-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 123456,
            'uploaded_by' => $uploadedBy->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }
}
