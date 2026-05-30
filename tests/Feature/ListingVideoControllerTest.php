<?php

namespace Tests\Feature;

use App\Jobs\GenerateListingVideo;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingVideoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_listing_video(): void
    {
        $this->postJson('/api/listing-videos/generate', [])
            ->assertUnauthorized();
    }

    public function test_requires_six_to_ten_selected_photos(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $files = $this->createShootFiles($shoot, 5, $admin);

        $this->postJson('/api/listing-videos/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $files->pluck('id')->all(),
            'target_seconds' => 30,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_rejects_photos_from_another_shoot(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $otherShoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $selectedIds = $this->createShootFiles($shoot, 5, $admin)
            ->pluck('id')
            ->push($this->createShootFiles($otherShoot, 1, $admin)->first()->id)
            ->all();

        $this->postJson('/api/listing-videos/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $selectedIds,
            'target_seconds' => 30,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_creates_listing_video_job_preserving_selected_order(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $files = $this->createShootFiles($shoot, 6, $admin);
        $orderedIds = $files->pluck('id')->reverse()->values()->all();

        $response = $this->postJson('/api/listing-videos/generate', [
            'shoot_id' => $shoot->id,
            'selected_file_ids' => $orderedIds,
            'target_seconds' => 40,
        ])->assertCreated();

        $jobId = $response->json('data.id');
        $job = AiListingVideoJob::findOrFail($jobId);

        $this->assertSame($orderedIds, $job->selected_file_ids);
        $this->assertSame(40, $job->target_seconds);
        $this->assertSame(AiListingVideoJob::STATUS_QUEUED, $job->status);
        $this->assertSame(6, $job->total_clips);
        $this->assertSame('4.80', (string) $job->estimated_cost);

        Queue::assertPushed(GenerateListingVideo::class);
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
