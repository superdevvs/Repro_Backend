<?php

namespace Tests\Feature;

use App\Jobs\GenerateListingVideo;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ListingVideoJobRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_failure_closes_an_active_job(): void
    {
        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $videoJob = $this->videoJob($user, $shoot);

        (new GenerateListingVideo($videoJob->id))->failed(
            new RuntimeException('The queue worker timed out.')
        );

        $videoJob->refresh();

        $this->assertSame(AiListingVideoJob::STATUS_FAILED, $videoJob->status);
        $this->assertSame(
            'Video generation took too long and was stopped. Please try again.',
            $videoJob->error_message
        );
        $this->assertNotNull($videoJob->completed_at);
    }

    public function test_jobs_endpoint_closes_an_orphaned_processing_job(): void
    {
        config()->set('services.fal.video_job_stale_after', 60);

        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $videoJob = $this->videoJob($user, $shoot, AiListingVideoJob::STATUS_PROCESSING);
        $staleAt = now()->subMinutes(5);

        DB::table('ai_listing_video_jobs')
            ->where('id', $videoJob->id)
            ->update([
                'started_at' => $staleAt,
                'updated_at' => $staleAt,
            ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/listing-videos/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $videoJob->id)
            ->assertJsonPath('data.0.status', AiListingVideoJob::STATUS_FAILED)
            ->assertJsonPath(
                'data.0.error_message',
                'Video generation stopped responding and was closed. Please try again.'
            );

        $this->assertSame(
            AiListingVideoJob::STATUS_FAILED,
            $videoJob->fresh()->status
        );
    }

    public function test_provider_polling_reports_completed_clips_without_waiting_for_the_first_request(): void
    {
        config()->set('services.fal.test_mode', false);
        config()->set('services.fal.video_poll_timeout', 1);
        config()->set('services.fal.video_poll_interval', 1);

        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $files = collect([1, 2])->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "photo-{$index}.png",
            'stored_filename' => "photo-{$index}.png",
            'path' => "shoots/{$shoot->id}/photo-{$index}.png",
            'storage_path' => "shoots/{$shoot->id}/photo-{$index}.png",
            'file_type' => 'image/png',
            'mime_type' => 'image/png',
            'file_size' => 68,
            'uploaded_by' => $user->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
        $videoJob = $this->videoJob(
            $user,
            $shoot,
            AiListingVideoJob::STATUS_QUEUED,
            $files->pluck('id')->all()
        );
        $imagePath = tempnam(sys_get_temp_dir(), 'listing-video-test-');
        file_put_contents($imagePath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        $fileAccess = Mockery::mock(ShootFileAccessService::class);
        $fileAccess->shouldReceive('findLocalFilePath')->twice()->andReturn($imagePath);

        $fal = Mockery::mock(FalService::class);
        $fal->shouldReceive('uploadImage')->twice()->andReturn('https://example.test/photo.png');
        $fal->shouldReceive('submit')->twice()->andReturn('request-1', 'request-2');
        $fal->shouldReceive('status')->with('request-1')->once()->andReturn('IN_PROGRESS');
        $fal->shouldReceive('status')->with('request-2')->once()->andReturn('COMPLETED');
        $fal->shouldReceive('result')->with('request-2')->once()->andReturn('https://example.test/clip.mp4');

        try {
            (new GenerateListingVideo($videoJob->id))->handle($fal, $fileAccess);
            $this->fail('Expected provider polling to time out.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        } finally {
            @unlink($imagePath);
        }

        $videoJob->refresh();

        $this->assertSame(1, $videoJob->completed_clips);
        $this->assertSame(AiListingVideoJob::STATUS_FAILED, $videoJob->status);
        $this->assertSame(
            'Video generation took too long and was stopped. Please try again.',
            $videoJob->error_message
        );
    }

    private function videoJob(
        User $user,
        Shoot $shoot,
        string $status = AiListingVideoJob::STATUS_QUEUED,
        array $selectedFileIds = []
    ): AiListingVideoJob {
        return AiListingVideoJob::create([
            'shoot_id' => $shoot->id,
            'user_id' => $user->id,
            'provider' => 'fal',
            'selected_file_ids' => $selectedFileIds,
            'target_seconds' => 40,
            'status' => $status,
            'total_clips' => $selectedFileIds === [] ? 6 : count($selectedFileIds),
            'completed_clips' => 0,
            'estimated_cost' => 4.80,
            'started_at' => $status === AiListingVideoJob::STATUS_PROCESSING ? now() : null,
        ]);
    }
}
