<?php

namespace Tests\Feature;

use App\Jobs\CacheShootFinalToLocalJob;
use App\Jobs\FinalizeShootJob;
use App\Jobs\PublishShootToBrightMlsJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\ShootMediaStorageService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Events\ShootActivityBroadcast;
use App\Services\ShootActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootActivityLogDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_endpoint_merges_relevant_workflow_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->createShoot(['client_id' => User::factory()->create(['role' => 'client'])->id]);

        $shoot->workflowLogs()->create([
            'user_id' => $admin->id,
            'action' => 'finalize_completed',
            'details' => 'Finalize completed successfully',
            'metadata' => [
                'processed_files' => 4,
                'total_files' => 4,
            ],
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/shoots/{$shoot->id}/activity-log");

        $response->assertOk()
            ->assertJsonPath('data.0.action', 'shoot_finalized_delivered')
            ->assertJsonPath('data.0.source', 'workflow')
            ->assertJsonPath('data.0.description', 'Shoot has been finalized and delivered by admin');
    }

    public function test_photographer_raw_upload_creates_visible_media_uploaded_activity(): void
    {
        Queue::fake();
        Storage::fake('public');

        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->createShoot([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $this->mockUploadToTodo();

        Sanctum::actingAs($photographer);

        $this->post("/api/shoots/{$shoot->id}/upload", [
            'upload_type' => 'raw',
            'files' => [
                UploadedFile::fake()->create('front.nef', 16, 'application/octet-stream'),
                UploadedFile::fake()->create('kitchen.nef', 16, 'application/octet-stream'),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $log = $this->latestActivity($shoot, 'media_uploaded');

        $this->assertSame('Media uploaded by photographer: 2 files (raw)', $log->description);
        $this->assertSame('photographer', $log->metadata['uploaded_by_role']);
        $this->assertSame('raw', $log->metadata['type']);
        $this->assertSame(2, $log->metadata['file_count']);
        $this->assertCount(2, $log->metadata['file_ids']);
    }

    public function test_raw_upload_batch_creates_single_visible_media_uploaded_activity(): void
    {
        Queue::fake();
        Storage::fake('public');

        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = $this->createShoot([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $this->mockUploadToTodo(3);

        Sanctum::actingAs($photographer);

        foreach (['front.nef', 'kitchen.nef', 'bedroom.nef'] as $index => $filename) {
            $this->post("/api/shoots/{$shoot->id}/upload", [
                'upload_type' => 'raw',
                'upload_batch_id' => 'raw-batch-test',
                'upload_batch_total' => 3,
                'upload_batch_index' => $index,
                'files' => [
                    UploadedFile::fake()->create($filename, 16, 'application/octet-stream'),
                ],
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->assertSame(1, ShootActivityLog::query()
            ->where('shoot_id', $shoot->id)
            ->where('action', 'media_uploaded')
            ->count());

        $log = $this->latestActivity($shoot, 'media_uploaded');

        $this->assertSame('Media uploaded by photographer: 3 files (raw)', $log->description);
        $this->assertSame('photographer', $log->metadata['uploaded_by_role']);
        $this->assertSame('raw', $log->metadata['type']);
        $this->assertSame(3, $log->metadata['file_count']);
        $this->assertCount(3, $log->metadata['file_ids']);
    }

    public function test_editor_edited_upload_creates_visible_media_uploaded_activity(): void
    {
        Queue::fake();
        Storage::fake('public');

        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->createShoot([
            'editor_id' => $editor->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);
        $this->mockUploadToCompleted();

        Sanctum::actingAs($editor);

        $this->post("/api/shoots/{$shoot->id}/upload", [
            'upload_type' => 'edited',
            'files' => [
                UploadedFile::fake()->create('final.jpg', 16, 'application/octet-stream'),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $log = $this->latestActivity($shoot, 'media_uploaded');

        $this->assertSame('Media uploaded by editor: 1 file (edited)', $log->description);
        $this->assertSame('editor', $log->metadata['uploaded_by_role']);
        $this->assertSame('edited', $log->metadata['type']);
        $this->assertSame(1, $log->metadata['file_count']);
    }

    public function test_edited_upload_batch_creates_single_visible_media_uploaded_activity(): void
    {
        Queue::fake();
        Storage::fake('public');

        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->createShoot([
            'editor_id' => $editor->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);
        $this->mockUploadToCompleted(3);

        Sanctum::actingAs($editor);

        foreach (['front.jpg', 'kitchen.jpg', 'bedroom.jpg'] as $index => $filename) {
            $this->post("/api/shoots/{$shoot->id}/upload", [
                'upload_type' => 'edited',
                'upload_batch_id' => 'edited-batch-test',
                'upload_batch_total' => 3,
                'upload_batch_index' => $index,
                'files' => [
                    UploadedFile::fake()->create($filename, 16, 'application/octet-stream'),
                ],
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->assertSame(1, ShootActivityLog::query()
            ->where('shoot_id', $shoot->id)
            ->where('action', 'media_uploaded')
            ->count());

        $log = $this->latestActivity($shoot, 'media_uploaded');

        $this->assertSame('Media uploaded by editor: 3 files (edited)', $log->description);
        $this->assertSame('editor', $log->metadata['uploaded_by_role']);
        $this->assertSame('edited', $log->metadata['type']);
        $this->assertSame(3, $log->metadata['file_count']);
        $this->assertCount(3, $log->metadata['file_ids']);
    }

    public function test_finalize_job_creates_finalized_delivered_activity(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->createShoot([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => "shoots/{$shoot->id}/completed/final.jpg",
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        (new FinalizeShootJob($shoot->id, $admin->id, 'completed'))->handle(
            app(ShootActivityLogger::class)
        );

        $log = $this->latestActivity($shoot, 'shoot_finalized_delivered');

        $this->assertSame('Shoot has been finalized and delivered by admin', $log->description);
        $this->assertSame('admin', $log->metadata['finalized_by_role']);

        // Shoot was flipped to delivered via bulk DB commit.
        $shoot->refresh();
        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->workflow_status);
        $this->assertSame(ShootFile::STAGE_VERIFIED, $shoot->files()->first()->workflow_stage);

        // Heavy side effects are dispatched, not executed inline.
        Queue::assertPushed(SendShootReadyEmailJob::class, fn (SendShootReadyEmailJob $job) => $job->shootId === $shoot->id);
        Queue::assertPushed(PublishShootToBrightMlsJob::class, fn (PublishShootToBrightMlsJob $job) => $job->shootId === $shoot->id);
        // No dropbox_path on the test file, so no cache job is dispatched.
        Queue::assertNotPushed(CacheShootFinalToLocalJob::class);
    }

    public function test_successful_bright_mls_publish_creates_synced_activity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->createShoot(['mls_id' => 'MLS123']);

        $brightMls = Mockery::mock(BrightMlsService::class);
        $brightMls->shouldReceive('buildManifestFromShoot')->once()->andReturn(['manifest' => true]);
        $brightMls->shouldReceive('publishManifest')->once()->andReturn([
            'success' => true,
            'status' => 'published',
            'manifest_id' => 'manifest-123',
            'mls_id' => 'MLS123',
            'mode' => 'test',
            'environment' => 'sandbox',
        ]);
        $brightMls->shouldReceive('applyPublishResultToShoot')->once();
        $brightMls->shouldReceive('getRedirectUrl')->once()->with('manifest-123')->andReturn('https://bright.test/manifest-123');
        $this->app->instance(BrightMlsService::class, $brightMls);

        Sanctum::actingAs($admin);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/bright-mls/publish", [
            'photos' => [],
        ])->assertOk();

        $log = $this->latestActivity($shoot, 'bright_mls_synced');

        $this->assertSame('Media has been synced to Bright MLS', $log->description);
        $this->assertSame('manifest-123', $log->metadata['manifest_id']);
        $this->assertFalse($log->metadata['auto_publish']);
    }

    public function test_tour_link_update_creates_generated_activity(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->createShoot();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'tour_links' => [
                'branded' => 'https://tour.example/branded',
                'mls' => 'https://tour.example/mls',
            ],
        ])->assertOk();

        $log = $this->latestActivity($shoot, 'tour_links_generated');

        $this->assertSame('Tour links have been generated', $log->description);
        $this->assertSame(2, $log->metadata['tour_link_count']);
        $this->assertSame(['branded', 'mls'], $log->metadata['changed_keys']);
    }

    public function test_editor_submit_for_review_activity_is_broadcast_to_managers(): void
    {
        Event::fake([ShootActivityBroadcast::class]);

        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
            'editor_id' => $editor->id,
        ]);

        $log = app(ShootActivityLogger::class)->log(
            $shoot,
            'shoot_submitted_for_editing_review',
            ['edited_photo_count' => 3, 'role' => 'editor'],
            $editor
        );

        // 14.2: the editor-submit action is persisted with a readable description.
        $this->assertSame('shoot_submitted_for_editing_review', $log->action);
        $this->assertStringContainsString('editing manager for review', $log->description);

        // 14.1: it is on the broadcast allow-list, so a live in-app notification
        // reaches editing managers and admins (who see all shoot activity).
        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'shoot_submitted_for_editing_review';
        });
    }

    private function createShoot(array $overrides = []): Shoot
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create();

        return Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ], $overrides));
    }

    private function latestActivity(Shoot $shoot, string $action): ShootActivityLog
    {
        return ShootActivityLog::query()
            ->where('shoot_id', $shoot->id)
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }

    private function mockUploadToTodo(int $times = 2): void
    {
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('uploadToTodo')
            ->times($times)
            ->andReturnUsing(function (Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null, ?string $mediaTypeOverride = null) {
                return $this->createShootFile($shoot, $file, $userId, ShootFile::STAGE_TODO, $mediaTypeOverride ?? 'raw');
            });

        $this->app->instance(ShootMediaStorageService::class, $dropbox);
    }

    private function mockUploadToCompleted(int $times = 1): void
    {
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('uploadToCompleted')
            ->times($times)
            ->andReturnUsing(function (Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null, ?string $mediaTypeOverride = null) {
                return $this->createShootFile($shoot, $file, $userId, ShootFile::STAGE_COMPLETED, $mediaTypeOverride ?? 'edited');
            });

        $this->app->instance(ShootMediaStorageService::class, $dropbox);
    }

    private function createShootFile(Shoot $shoot, UploadedFile $file, int $userId, string $stage, string $mediaType): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => $file->getClientOriginalName(),
            'stored_filename' => $file->hashName(),
            'path' => "shoots/{$shoot->id}/{$stage}/" . $file->hashName(),
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'media_type' => $mediaType,
            'uploaded_by' => $userId,
            'workflow_stage' => $stage,
        ]);
    }
}
