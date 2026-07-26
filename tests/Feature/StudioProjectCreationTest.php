<?php

namespace Tests\Feature;

use App\Jobs\GenerateListingVideo;
use App\Jobs\GenerateReel;
use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\BrandState;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Focused integration coverage for transactional Studio project submission.
 *
 * Validates: Requirements 3.4, 3.5, 3.8, 13.2-13.8, 13.13, 13.14, 16.9-16.11.
 */
class StudioProjectCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_video_uses_persisted_template_and_latest_brand_and_returns_contract(): void
    {
        Queue::fake();
        $user = $this->user();
        $shoot = Shoot::factory()->create(['created_by' => $user->id, 'address' => '12 Template Way']);
        $files = $this->files($shoot, $user, 6);
        $template = Template::create([
            'team_id' => 701,
            'created_by' => $user->id,
            'name' => 'Cinematic',
            'workflow_id' => 'listing-video',
            'config' => ['target_seconds' => 40, 'transition' => 'fade'],
        ]);
        BrandState::create([
            'team_id' => 701,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'settings' => ['include_logo' => true, 'logo' => '/brand/logo.png'],
        ]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/studio/projects', [
            'requestId' => 'listing-template-1',
            'workflowId' => 'listing-video',
            'sourceType' => 'shoot',
            'shootId' => $shoot->id,
            'selectedFileIds' => $files->pluck('id')->all(),
            'templateId' => $template->id,
            'workflowConfig' => ['target_seconds' => 30, 'client_demo' => true],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.aiJobId', '1')
            ->assertJsonPath('data.aiJobIds.0', '1')
            ->assertJsonPath('data.deepLink.destination', 'projects')
            ->assertJsonPath('data.deepLink.workflowId', 'listing-video')
            ->assertJsonPath('data.version', 1);

        $project = Project::findOrFail($response->json('data.projectId'));
        $job = AiListingVideoJob::firstOrFail();
        $this->assertSame($template->config, $project->workflow_config);
        $this->assertSame($template->config, $job->workflow_config);
        $this->assertSame(['include_logo' => true, 'logo' => '/brand/logo.png'], $project->brand_state);
        $this->assertSame($project->brand_state, $job->brand_state);
        $this->assertSame($project->id, $job->project_id);
        $this->assertSame('listing-template-1', $job->request_id);
        $this->assertSame(6, ProjectMedia::count());
        Queue::assertPushed(GenerateListingVideo::class);
    }

    public function test_retry_with_same_request_id_returns_original_result_without_duplicates(): void
    {
        Queue::fake();
        $user = $this->user();
        $shoot = Shoot::factory()->create(['created_by' => $user->id]);
        $files = $this->files($shoot, $user, 2);
        Sanctum::actingAs($user);
        $payload = [
            'request_id' => 'retry-photo-1',
            'workflow_id' => 'photo-enhancement',
            'source_type' => 'shoot',
            'shoot_id' => $shoot->id,
            'file_ids' => $files->pluck('id')->all(),
            'workflow_config' => ['strength' => 80],
        ];

        $first = $this->postJson('/api/studio/projects', $payload)->assertCreated();
        $second = $this->postJson('/api/studio/projects', [
            'request_id' => 'retry-photo-1',
            'workflow_id' => 'missing-workflow',
        ])->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Project::count());
        $this->assertSame(2, ProjectMedia::count());
        $this->assertSame(2, AiEditingJob::count());
        Queue::assertPushed(ProcessAutoenhanceEditingJob::class, 2);
    }
    public function test_uploaded_media_is_authorized_persisted_and_creates_photo_jobs(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('studio_uploads.disk', 'public');
        $user = $this->user();
        $first = "studio/uploads/701/{$user->id}/first.jpg";
        $second = "studio/uploads/701/{$user->id}/second.jpg";
        Storage::disk('public')->put($first, 'image-one');
        Storage::disk('public')->put($second, 'image-two');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/studio/projects', [
            'request_id' => 'upload-photo-1',
            'workflow_id' => 'photo-enhancement',
            'source_type' => 'upload',
            'media_refs' => [$first, $second],
            'workflow_config' => [],
        ])->assertCreated();

        $project = Project::findOrFail($response->json('data.projectId'));
        $this->assertSame('upload', $project->source_type);
        $this->assertNull($project->shoot_id);
        $this->assertEqualsCanonicalizing([$first, $second], ProjectMedia::pluck('media_ref')->all());
        $this->assertCount(2, $response->json('data.aiJobIds'));
        $this->assertSame(2, AiEditingJob::where('project_id', $project->id)->count());
    }

    public function test_matching_reel_and_video_cleanup_job_types_are_created(): void
    {
        Queue::fake();
        $user = $this->user();
        Sanctum::actingAs($user);

        $imageShoot = Shoot::factory()->create(['created_by' => $user->id]);
        $images = $this->files($imageShoot, $user, 2);
        $reel = $this->postJson('/api/studio/projects', [
            'request_id' => 'reel-1',
            'workflow_id' => 'reel-generator',
            'source_type' => 'shoot',
            'shoot_id' => $imageShoot->id,
            'file_ids' => $images->pluck('id')->all(),
            'workflow_config' => [],
        ])->assertCreated();
        $this->assertSame('reel', $reel->json('data.jobs.0.type'));
        $this->assertSame($reel->json('data.projectId'), AiReelJob::firstOrFail()->project_id);
        Queue::assertPushed(GenerateReel::class);

        $videoShoot = Shoot::factory()->create(['created_by' => $user->id]);
        $video = ShootFile::create([
            'shoot_id' => $videoShoot->id,
            'filename' => 'walkthrough.mp4',
            'stored_filename' => 'walkthrough.mp4',
            'path' => 'shoots/video/walkthrough.mp4',
            'storage_path' => 'shoots/video/walkthrough.mp4',
            'file_type' => 'video/mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 100,
            'uploaded_by' => $user->id,
            'media_type' => 'video',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
        $cleanup = $this->postJson('/api/studio/projects', [
            'request_id' => 'cleanup-1',
            'workflow_id' => 'video-cleanup',
            'source_type' => 'shoot',
            'shoot_id' => $videoShoot->id,
            'file_ids' => [$video->id],
            'workflow_config' => [],
        ])->assertCreated();
        $cleanupJob = AiEditingJob::findOrFail($cleanup->json('data.aiJobId'));
        $this->assertSame('video-cleanup', $cleanupJob->editing_type);
        $this->assertSame('fal', $cleanupJob->provider);
    }
    public function test_invalid_batch_and_missing_references_create_no_dependents(): void
    {
        Queue::fake();
        $user = $this->user();
        $shoot = Shoot::factory()->create(['created_by' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/studio/projects', [
            'request_id' => 'batch-empty',
            'workflow_id' => 'batch-ai-jobs',
            'source_type' => 'shoot',
            'shoot_id' => $shoot->id,
            'file_ids' => [],
            'workflow_config' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('file_ids');

        $this->postJson('/api/studio/projects', [
            'request_id' => 'missing-shoot',
            'workflow_id' => 'photo-enhancement',
            'source_type' => 'shoot',
            'shoot_id' => 999999,
            'file_ids' => [999999],
            'workflow_config' => [],
        ])->assertNotFound();

        $this->assertSame(0, Project::count());
        $this->assertSame(0, ProjectMedia::count());
        $this->assertSame(0, AiEditingJob::count());
        Queue::assertNothingPushed();
    }

    public function test_job_creation_failure_rolls_back_project_media_and_jobs(): void
    {
        Queue::fake();
        $user = $this->user();
        $shoot = Shoot::factory()->create(['created_by' => $user->id]);
        $file = $this->files($shoot, $user, 1)->first();
        Sanctum::actingAs($user);
        $event = 'eloquent.creating: '.AiEditingJob::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('forced transactional failure');
        });

        try {
            $this->postJson('/api/studio/projects', [
                'request_id' => 'rollback-1',
                'workflow_id' => 'photo-enhancement',
                'source_type' => 'shoot',
                'shoot_id' => $shoot->id,
                'file_ids' => [$file->id],
                'workflow_config' => [],
            ])->assertStatus(500);
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, Project::count());
        $this->assertSame(0, ProjectMedia::count());
        $this->assertSame(0, AiEditingJob::count());
        Queue::assertNothingPushed();
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'metadata' => ['team_id' => 701],
        ]);
    }

    private function files(Shoot $shoot, User $user, int $count)
    {
        return collect(range(1, $count))->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "photo-{$shoot->id}-{$index}.jpg",
            'stored_filename' => "photo-{$shoot->id}-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $user->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }
}
