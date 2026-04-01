<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Jobs\GenerateWatermarkedImageJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\Actions\VerifyShootFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ShootMediaActionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $editor;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'media-admin@test.com',
        ]);

        $this->editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'media-editor@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'email' => 'media-client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'media-photographer@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'Media Service',
            'price' => 150.00,
        ]);
    }

    /** @test */
    public function admin_can_upload_raw_files_and_promote_scheduled_shoot_to_uploaded(): void
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
            ->assertJsonPath('shoot_status', Shoot::STATUS_UPLOADED)
            ->assertJsonPath('raw_photo_count', 1);

        $shoot->refresh();

        $this->assertSame(Shoot::STATUS_UPLOADED, $shoot->workflow_status);
        $this->assertDatabaseHas('shoot_files', [
            'shoot_id' => $shoot->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'media_type' => 'raw',
        ]);
        Queue::assertPushed(SyncShootIguideJob::class, function (SyncShootIguideJob $job) use ($shoot) {
            return $job->shootId === $shoot->id;
        });
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
