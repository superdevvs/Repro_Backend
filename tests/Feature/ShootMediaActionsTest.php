<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\GenerateWatermarkedImageJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\Actions\VerifyShootFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use ZipArchive;

class ShootMediaActionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $superadmin;
    protected User $editingManager;
    protected User $editor;
    protected User $client;
    protected User $photographer;
    protected User $salesRep;
    protected User $unassignedSalesRep;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'media-admin@test.com',
        ]);

        $this->superadmin = User::factory()->create([
            'role' => 'superadmin',
            'email' => 'media-superadmin@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'media-editor@test.com',
        ]);

        $this->editingManager = User::factory()->create([
            'role' => 'editing_manager',
            'email' => 'media-editing-manager@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'media-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'media-photographer@test.com',
        ]);

        $this->salesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'media-sales-rep@test.com',
        ]);

        $this->unassignedSalesRep = User::factory()->create([
            'role' => 'salesRep',
            'email' => 'media-sales-rep-unassigned@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Media Service',
            'price' => 150.00,
        ]);
    }

    /** @test */
    public function admin_can_finalize_raw_upload_after_queue_completes(): void
    {
        Storage::fake('public');
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToTodo')
            ->once()
            ->andReturnUsing(function (Shoot $shoot, UploadedFile $file, int $userId) {
                $path = 'shoots/' . $shoot->id . '/todo/' . $file->hashName();
                Storage::disk('public')->put($path, 'raw-upload');

                return ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => basename($path),
                    'path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'media_type' => 'raw',
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                ]);
            });
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->post('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->image('raw-upload.jpg')],
            'upload_type' => 'raw',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('shoot_status', Shoot::STATUS_SCHEDULED)
            ->assertJsonPath('raw_photo_count', 1);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->workflow_status);
        $this->assertDatabaseHas('shoot_files', [
            'shoot_id' => $shoot->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'media_type' => 'raw',
        ]);

        Queue::assertNotPushed(SyncShootIguideJob::class);

        $finalizeResponse = $this->post('/api/shoots/' . $shoot->id . '/upload/finalize-raw', [], [
            'Accept' => 'application/json',
        ]);

        $finalizeResponse->assertOk()
            ->assertJsonPath('shoot_status', Shoot::STATUS_UPLOADED)
            ->assertJsonPath('workflow_status_changed', true)
            ->assertJsonPath('raw_photo_count', 1);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_UPLOADED, $shoot->workflow_status);
        Queue::assertPushed(SyncShootIguideJob::class, function (SyncShootIguideJob $job) use ($shoot) {
            return $job->shootId === $shoot->id;
        });
    }

    /** @test */
    public function admin_can_upload_edited_files_while_a_shoot_is_still_scheduled(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToCompleted')
            ->once()
            ->andReturnUsing(function (Shoot $shoot, UploadedFile $file, int $userId) {
                $path = 'shoots/' . $shoot->id . '/completed/' . $file->hashName();
                Storage::disk('public')->put($path, 'edited-upload');

                return ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => basename($path),
                    'path' => $path,
                    'storage_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'media_type' => 'edited',
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                ]);
            });
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->post('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->image('edited-upload.jpg')],
            'upload_type' => 'edited',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('error_count', 0);

        $this->assertDatabaseHas('shoot_files', [
            'shoot_id' => $shoot->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'media_type' => 'edited',
        ]);
    }

    /** @test */
    public function superadmin_can_upload_edited_files_after_delivery(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->superadmin);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToCompleted')
            ->once()
            ->andReturnUsing(function (Shoot $shoot, UploadedFile $file, int $userId) {
                $path = 'shoots/' . $shoot->id . '/completed/' . $file->hashName();
                Storage::disk('public')->put($path, 'superadmin-edited-upload');

                return ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => basename($path),
                    'path' => $path,
                    'storage_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'media_type' => 'edited',
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                ]);
            });
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->post('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->image('superadmin-upload.jpg')],
            'upload_type' => 'edited',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('error_count', 0);
    }

    /** @test */
    public function upload_without_files_keeps_the_legacy_invalid_file_payload(): void
    {
        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot();

        $response = $this->post('/api/shoots/' . $shoot->id . '/upload', [
            'upload_type' => 'raw',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_file')
            ->assertJsonStructure([
                'error_type',
                'message',
                'errors',
                'debug',
            ]);
    }

    /** @test */
    public function upload_rejects_oversized_files_with_the_existing_payload_shape(): void
    {
        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot();

        $response = $this->post('/api/shoots/' . $shoot->id . '/upload', [
            'files' => [UploadedFile::fake()->create('oversized.nef', 600000)],
            'upload_type' => 'raw',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('error_type', 'oversize')
            ->assertJsonStructure([
                'error_type',
                'message',
                'errors',
            ]);
    }

    /** @test */
    public function verify_shoot_file_action_moves_the_last_completed_file_to_ready(): void
    {
        Queue::fake();

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);

        $file = $this->createShootFile($shoot, [
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'path' => 'shoots/' . $shoot->id . '/completed/final.jpg',
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('moveToFinal')
            ->once()
            ->withArgs(function (ShootFile $passedFile, int $userId) use ($file) {
                return $passedFile->id === $file->id && $userId === $this->admin->id;
            })
            ->andReturnNull();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $action = app(VerifyShootFileAction::class);
        $payload = $action->execute(
            Request::create('/verify', 'POST', ['verification_notes' => 'Looks good']),
            $shoot,
            $file,
            $this->admin
        );

        $this->assertSame('File verified and moved to final storage successfully', $payload['message']);
        $this->assertSame(Shoot::STATUS_READY, $payload['shoot_status']);

        $shoot->refresh();
        $file->refresh();

        $this->assertSame(Shoot::STATUS_READY, $shoot->workflow_status);
        $this->assertSame(ShootFile::STAGE_VERIFIED, $file->workflow_stage);
        $this->assertSame($this->admin->id, $file->verified_by);

        Queue::assertPushed(GenerateWatermarkedImageJob::class);
    }

    /** @test */
    public function editor_can_generate_share_link_with_local_zip_fallback_and_broadcast_activity(): void
    {
        Storage::fake('public');
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
        ]);

        $rawPath = 'shoots/' . $shoot->id . '/todo/raw-1.nef';
        Storage::disk('public')->put($rawPath, 'raw-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'raw-1.nef',
            'stored_filename' => 'raw-1.nef',
            'path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/generate-share-link', [
            'file_ids' => [$file->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Share link generated successfully')
            ->assertJsonPath('file_count', 1)
            ->assertJsonStructure([
                'share_link',
                'share_link_id',
                'file_count',
                'expires_in_hours',
                'expires_at',
                'message',
            ]);

        $this->assertDatabaseHas('shoot_share_links', [
            'shoot_id' => $shoot->id,
            'created_by' => $this->editor->id,
        ]);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'share_link_generated',
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'share_link_generated';
        });
    }

    /** @test */
    public function admin_can_set_cover_media_and_clear_cached_file_lists(): void
    {
        Storage::fake('public');
        Event::fake([ShootActivityBroadcast::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $webPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        Storage::disk('public')->put($webPath, 'web-preview');

        $file = $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/front.jpg',
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'is_cover' => false,
            'is_hidden' => false,
        ]);

        $adminCacheKey = 'shoot_files_' . $shoot->id . '__' . $this->admin->id . '_' . $this->admin->role;
        $clientCacheKey = 'shoot_files_' . $shoot->id . '__' . $this->client->id . '_client';
        Cache::put($adminCacheKey, ['cached' => true], now()->addMinutes(5));
        Cache::put($clientCacheKey, ['cached' => true], now()->addMinutes(5));

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/cover');

        $response->assertOk()
            ->assertJsonPath('message', 'Cover updated');

        $shoot->refresh();
        $file->refresh();

        $this->assertTrue($file->is_cover);
        $this->assertNotNull($shoot->hero_image);
        $this->assertNull(Cache::get($adminCacheKey));
        $this->assertNull(Cache::get($clientCacheKey));
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'hero_image_updated',
        ]);

        Event::assertDispatched(ShootActivityBroadcast::class, function (ShootActivityBroadcast $event) use ($shoot, $file) {
            return $event->shoot->id === $shoot->id
                && $event->activityType === 'hero_image_updated'
                && ($event->metadata['file_id'] ?? null) === $file->id;
        });
    }

    /** @test */
    public function admin_can_reorder_media_after_the_controller_refactor(): void
    {
        Sanctum::actingAs($this->admin);
        $shoot = $this->createShoot();

        $first = $this->createShootFile($shoot, ['sort_order' => 0]);
        $second = $this->createShootFile($shoot, [
            'filename' => 'second.jpg',
            'stored_filename' => 'second.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/second.jpg',
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/media/reorder', [
            'files' => [
                ['id' => $first->id, 'sort_order' => 5],
                ['id' => $second->id, 'sort_order' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Media order updated successfully');

        $this->assertDatabaseHas('shoot_files', [
            'id' => $first->id,
            'sort_order' => 5,
        ]);
        $this->assertDatabaseHas('shoot_files', [
            'id' => $second->id,
            'sort_order' => 1,
        ]);
    }

    /** @test */
    public function media_zip_download_returns_a_cached_archive_redirect_when_small_zip_is_ready(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $webPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($webPath, 'small-preview-bytes');
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $archiveService = app(ShootMediaArchiveService::class);
        $archiveService->generateArchive($shoot, 'edited', 'small');

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/download-zip?type=edited&size=small');

        $response->assertOk()
            ->assertJsonPath('type', 'redirect');

        $this->assertStringContainsString(
            '/storage/shoots/' . $shoot->id . '/archives/edited-small.zip',
            (string) $response->json('url')
        );
    }

    /** @test */
    public function admin_can_download_raw_files_from_an_in_progress_shoot(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/raw-photo.jpg';
        Storage::disk('public')->put($rawPath, 'raw-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'raw-photo.jpg',
            'stored_filename' => 'raw-photo.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->get('/api/shoots/' . $shoot->id . '/editor-download-raw', [
            'Accept' => 'application/json, application/zip',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shoot-' . $shoot->id . '-raw-files.zip',
            (string) $response->headers->get('content-disposition')
        );
    }

    /** @test */
    public function editing_manager_can_download_raw_files_from_an_in_progress_shoot(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/editing-manager-raw.jpg';
        Storage::disk('public')->put($rawPath, 'editing-manager-raw-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'editing-manager-raw.jpg',
            'stored_filename' => 'editing-manager-raw.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->get('/api/shoots/' . $shoot->id . '/editor-download-raw', [
            'Accept' => 'application/json, application/zip',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shoot-' . $shoot->id . '-raw-files.zip',
            (string) $response->headers->get('content-disposition')
        );
    }

    /** @test */
    public function editing_manager_can_download_selected_files_zip(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->createShoot();
        $originalPath = 'shoots/' . $shoot->id . '/completed/editing-manager-selected.jpg';
        Storage::disk('public')->put($originalPath, 'editing-manager-selected-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'editing-manager-selected.jpg',
            'stored_filename' => 'editing-manager-selected.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->post('/api/shoots/' . $shoot->id . '/files/download', [
            'file_ids' => [$file->id],
            'size' => 'original',
        ], [
            'Accept' => 'application/zip',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shoot-' . $shoot->id . '-selected.zip',
            (string) $response->headers->get('content-disposition')
        );
    }

    /** @test */
    public function editing_manager_can_get_a_single_file_download_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editingManager);

        $shoot = $this->createShoot();
        $originalPath = 'shoots/' . $shoot->id . '/completed/editing-manager-single.jpg';
        Storage::disk('public')->put($originalPath, 'editing-manager-single-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'editing-manager-single.jpg',
            'stored_filename' => 'editing-manager-single.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/download');

        $response->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertNotEmpty($response->json('url'));
    }

    /** @test */
    public function assigned_editor_can_download_raw_files_via_the_editor_raw_endpoint(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/editor-raw-download.jpg';
        Storage::disk('public')->put($rawPath, 'editor-raw-download-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'editor-raw-download.jpg',
            'stored_filename' => 'editor-raw-download.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->get('/api/shoots/' . $shoot->id . '/editor-download-raw', [
            'Accept' => 'application/json, application/zip',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shoot-' . $shoot->id . '-raw-files.zip',
            (string) $response->headers->get('content-disposition')
        );
    }

    /** @test */
    public function unassigned_editor_cannot_download_raw_files_via_the_editor_raw_endpoint(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $otherEditor = User::factory()->create([
            'role' => 'editor',
            'email' => 'other-media-editor@test.com',
        ]);

        $shoot = $this->createShoot([
            'editor_id' => $otherEditor->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/unassigned-editor-raw.jpg';
        Storage::disk('public')->put($rawPath, 'unassigned-editor-raw-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'unassigned-editor-raw.jpg',
            'stored_filename' => 'unassigned-editor-raw.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/editor-download-raw');

        $response->assertForbidden();
    }

    /** @test */
    public function editor_cannot_download_archive_zip_files(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/editor-archive.jpg';
        Storage::disk('public')->put($originalPath, 'editor-archive-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'editor-archive.jpg',
            'stored_filename' => 'editor-archive.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/download-zip?type=edited&size=small');

        $response->assertForbidden();
    }

    /** @test */
    public function editor_cannot_download_selected_files_zip(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/editor-selected.jpg';
        Storage::disk('public')->put($originalPath, 'editor-selected-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'editor-selected.jpg',
            'stored_filename' => 'editor-selected.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/download', [
            'file_ids' => [$file->id],
            'size' => 'original',
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function assigned_editor_can_get_a_single_raw_file_download_url(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/editor-single-raw.jpg';
        Storage::disk('public')->put($rawPath, 'editor-single-raw-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'editor-single-raw.jpg',
            'stored_filename' => 'editor-single-raw.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/download');

        $response->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertNotEmpty($response->json('url'));
    }

    /** @test */
    public function editor_cannot_download_an_edited_single_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->editor);

        $shoot = $this->createShoot([
            'editor_id' => $this->editor->id,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/editor-single-edited.jpg';
        Storage::disk('public')->put($originalPath, 'editor-single-edited-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'editor-single-edited.jpg',
            'stored_filename' => 'editor-single-edited.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/download');

        $response->assertForbidden();
    }

    /** @test */
    public function assigned_sales_rep_can_download_a_delivered_archive(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $webPath = 'shoots/' . $shoot->id . '/web/sales-rep-front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/sales-rep-front.jpg';
        Storage::disk('public')->put($webPath, 'sales-rep-small-preview');
        Storage::disk('public')->put($originalPath, 'sales-rep-original-photo');

        $this->createShootFile($shoot, [
            'filename' => 'sales-rep-front.jpg',
            'stored_filename' => 'sales-rep-front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        app(ShootMediaArchiveService::class)->generateArchive($shoot, 'edited', 'small');

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/download-zip?type=edited&size=small');

        $response->assertOk()
            ->assertJsonPath('type', 'redirect');

        $this->assertStringContainsString(
            '/storage/shoots/' . $shoot->id . '/archives/edited-small.zip',
            (string) $response->json('url')
        );
    }

    /** @test */
    public function unassigned_sales_rep_cannot_download_a_delivered_archive(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->unassignedSalesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $webPath = 'shoots/' . $shoot->id . '/web/unassigned-sales-rep-front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/unassigned-sales-rep-front.jpg';
        Storage::disk('public')->put($webPath, 'unassigned-sales-rep-small-preview');
        Storage::disk('public')->put($originalPath, 'unassigned-sales-rep-original-photo');

        $this->createShootFile($shoot, [
            'filename' => 'unassigned-sales-rep-front.jpg',
            'stored_filename' => 'unassigned-sales-rep-front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/download-zip?type=edited&size=small');

        $response->assertForbidden();
    }

    /** @test */
    public function assigned_sales_rep_can_download_selected_edited_files(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/sales-rep-selected.jpg';
        Storage::disk('public')->put($originalPath, 'sales-rep-selected-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'sales-rep-selected.jpg',
            'stored_filename' => 'sales-rep-selected.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->post('/api/shoots/' . $shoot->id . '/files/download', [
            'file_ids' => [$file->id],
            'size' => 'original',
        ], [
            'Accept' => 'application/zip',
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shoot-' . $shoot->id . '-selected.zip',
            (string) $response->headers->get('content-disposition')
        );
    }

    /** @test */
    public function unassigned_sales_rep_cannot_download_selected_edited_files(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->unassignedSalesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/unassigned-sales-rep-selected.jpg';
        Storage::disk('public')->put($originalPath, 'unassigned-sales-rep-selected-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'unassigned-sales-rep-selected.jpg',
            'stored_filename' => 'unassigned-sales-rep-selected.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/download', [
            'file_ids' => [$file->id],
            'size' => 'original',
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function assigned_sales_rep_can_download_a_single_media_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/sales-rep-single.jpg';
        Storage::disk('public')->put($originalPath, 'sales-rep-single-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'sales-rep-single.jpg',
            'stored_filename' => 'sales-rep-single.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/download');

        $response->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertNotEmpty($response->json('url'));
    }

    /** @test */
    public function unassigned_sales_rep_cannot_download_a_single_media_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->unassignedSalesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $originalPath = 'shoots/' . $shoot->id . '/completed/unassigned-sales-rep-single.jpg';
        Storage::disk('public')->put($originalPath, 'unassigned-sales-rep-single-bytes');

        $file = $this->createShootFile($shoot, [
            'filename' => 'unassigned-sales-rep-single.jpg',
            'stored_filename' => 'unassigned-sales-rep-single.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/download');

        $response->assertForbidden();
    }

    /** @test */
    public function sales_rep_cannot_download_raw_files_from_the_editor_raw_endpoint(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->salesRep);

        $shoot = $this->createShoot([
            'rep_id' => $this->salesRep->id,
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);
        $rawPath = 'shoots/' . $shoot->id . '/todo/sales-rep-raw.jpg';
        Storage::disk('public')->put($rawPath, 'sales-rep-raw-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'sales-rep-raw.jpg',
            'stored_filename' => 'sales-rep-raw.jpg',
            'path' => $rawPath,
            'storage_path' => $rawPath,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/editor-download-raw');

        $response->assertForbidden();
    }

    /** @test */
    public function original_media_zip_download_returns_preparing_and_queues_generation(): void
    {
        Storage::fake('public');
        Queue::fake([GenerateShootMediaArchiveJob::class]);
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/media/download-zip?type=edited&size=original');

        $response->assertStatus(202)
            ->assertJsonPath('type', 'preparing');

        $this->assertStringContainsString(
            '/api/shoots/' . $shoot->id . '/media/download-zip',
            (string) $response->json('status_url')
        );
        $this->assertStringContainsString('type=edited', (string) $response->json('status_url'));
        $this->assertStringContainsString('size=original', (string) $response->json('status_url'));

        Queue::assertPushed(GenerateShootMediaArchiveJob::class, function (GenerateShootMediaArchiveJob $job) use ($shoot) {
            return $job->shootId === $shoot->id
                && $job->type === 'edited'
                && $job->size === 'original';
        });
    }

    /** @test */
    public function public_signed_media_archive_route_redirects_when_cached_archive_is_ready(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot();
        $webPath = 'shoots/' . $shoot->id . '/web/front_web.jpg';
        $originalPath = 'shoots/' . $shoot->id . '/completed/front.jpg';
        Storage::disk('public')->put($webPath, 'small-preview-bytes');
        Storage::disk('public')->put($originalPath, 'original-photo-bytes');

        $this->createShootFile($shoot, [
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => $originalPath,
            'storage_path' => $originalPath,
            'web_path' => $webPath,
            'media_type' => 'edited',
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('isEnabled')->andReturnFalse();
        app()->instance(DropboxWorkflowService::class, $dropbox);

        app(ShootMediaArchiveService::class)->generateArchive($shoot, 'edited', 'small');

        $signedUrl = URL::temporarySignedRoute(
            'api.public.shoot-media.download',
            now()->addMinutes(5),
            [
                'shoot' => $shoot->id,
                'type' => 'edited',
                'size' => 'small',
            ]
        );

        $response = $this->getJson($signedUrl);

        $response->assertOk()
            ->assertJsonPath('type', 'redirect');

        $this->assertStringContainsString(
            '/storage/shoots/' . $shoot->id . '/archives/edited-small.zip',
            (string) $response->json('url')
        );
    }

    /** @test */
    public function moving_a_shoot_to_editing_queues_the_raw_small_archive(): void
    {
        Queue::fake([GenerateShootMediaArchiveJob::class]);

        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);

        $shoot->updateWorkflowStatus(Shoot::STATUS_EDITING, $this->admin->id);

        Queue::assertPushed(GenerateShootMediaArchiveJob::class, function (GenerateShootMediaArchiveJob $job) use ($shoot) {
            return $job->shootId === $shoot->id
                && $job->type === 'raw'
                && $job->size === 'small';
        });
    }

    /** @test */
    public function moving_a_shoot_to_ready_or_delivered_queues_the_edited_small_archive(): void
    {
        Queue::fake([GenerateShootMediaArchiveJob::class]);

        $readyShoot = $this->createShoot([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $readyShoot->updateWorkflowStatus(Shoot::STATUS_READY, $this->admin->id);

        $deliveredShoot = $this->createShoot([
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);
        $deliveredShoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $this->admin->id);

        Queue::assertPushed(GenerateShootMediaArchiveJob::class, function (GenerateShootMediaArchiveJob $job) use ($readyShoot) {
            return $job->shootId === $readyShoot->id
                && $job->type === 'edited'
                && $job->size === 'small';
        });

        Queue::assertPushed(GenerateShootMediaArchiveJob::class, function (GenerateShootMediaArchiveJob $job) use ($deliveredShoot) {
            return $job->shootId === $deliveredShoot->id
                && $job->type === 'edited'
                && $job->size === 'small';
        });
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'editor_id' => $this->editor->id,
            'service_id' => $this->service->id,
            'address' => '250 Media Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'base_quote' => 150,
            'tax_amount' => 9,
            'total_quote' => 159,
            'payment_status' => 'paid',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:00',
        ], $overrides));
    }

    protected function createShootFile(Shoot $shoot, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => 'media-file.jpg',
            'stored_filename' => 'media-file.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/media-file.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'sort_order' => 0,
        ], $overrides));
    }
}
