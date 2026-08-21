<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootUploadIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_attempt_is_replayed_without_storing_the_file_twice(): void
    {
        Storage::fake('public');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($admin);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToTodo')->once()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null) => ShootFile::create([
                'shoot_id' => $target->id,
                'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(),
                'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
                'media_type' => 'raw',
                'uploaded_by' => $actorId,
                'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $payload = fn () => [
            'files' => [UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
            'idempotency_key' => 'same-upload-key',
            'photographer_notes' => 'Use the side entrance.',
        ];

        $first = $this->post('/api/shoots/'.$shoot->id.'/upload', $payload(), ['Accept' => 'application/json']);
        $second = $this->post('/api/shoots/'.$shoot->id.'/upload', $payload(), ['Accept' => 'application/json']);

        $first->assertOk()->assertJsonPath('success_count', 1);
        $second->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.id', $first->json('uploaded_files.0.id'));
        $this->assertSame(1, ShootFile::query()->where('shoot_id', $shoot->id)->count());
        $this->assertDatabaseHas('shoot_upload_attempts', [
            'shoot_id' => $shoot->id,
            'actor_id' => $admin->id,
            'idempotency_key' => 'same-upload-key',
            'status' => ShootUploadAttempt::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('shoot_notes', [
            'shoot_id' => $shoot->id,
            'author_id' => $admin->id,
            'type' => 'photographer',
            'content' => 'Use the side entrance.',
            'source' => 'scalar_compat:photographer_notes',
        ]);
    }

    public function test_reusing_a_key_with_a_changed_fingerprint_returns_conflict(): void
    {
        Storage::fake('public');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToTodo')->once()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null) => ShootFile::create([
                'shoot_id' => $target->id,
                'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(),
                'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
                'media_type' => 'raw',
                'uploaded_by' => $actorId,
                'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
            'idempotency_key' => 'conflicting-key',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->create('different.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
            'idempotency_key' => 'conflicting-key',
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error_type', 'idempotency_conflict')
            ->assertJsonPath('success_count', 0);
    }

    public function test_concurrent_pending_key_returns_structured_in_progress_result(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg');
        $request = \Illuminate\Http\Request::create('/upload', 'POST', [
            'upload_type' => 'raw',
            'idempotency_key' => 'pending-key',
        ]);
        $request->files->set('files', [$file]);
        $fingerprint = app(\App\Services\Shoots\ShootUploadIdempotencyService::class)
            ->fingerprint($request, [$file]);

        ShootUploadAttempt::query()->create([
            'shoot_id' => $shoot->id,
            'actor_id' => $admin->id,
            'idempotency_key' => 'pending-key',
            'request_fingerprint' => $fingerprint,
            'upload_type' => 'raw',
            'status' => ShootUploadAttempt::STATUS_PENDING,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
            'idempotency_key' => 'pending-key',
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error_type', 'upload_in_progress')
            ->assertJsonPath('success_count', 0);
    }

    public function test_stale_pending_attempts_are_alerted_and_never_pruned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $stalePending = ShootUploadAttempt::query()->create([
            'shoot_id' => $shoot->id,
            'actor_id' => $admin->id,
            'idempotency_key' => 'stale-pending-key',
            'request_fingerprint' => str_repeat('a', 64),
            'upload_type' => 'raw',
            'status' => ShootUploadAttempt::STATUS_PENDING,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $stalePending->forceFill(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->save();

        $completed = ShootUploadAttempt::query()->create([
            'shoot_id' => $shoot->id,
            'actor_id' => $admin->id,
            'idempotency_key' => 'old-completed-key',
            'request_fingerprint' => str_repeat('b', 64),
            'upload_type' => 'raw',
            'status' => ShootUploadAttempt::STATUS_COMPLETED,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'completed_at' => now()->subDays(31),
        ]);
        $completed->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->save();

        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Upload attempts require reconciliation before retry.'
                && $context['attempt_count'] === 1
                && $context['attempts'][0]['id'] === $stalePending->id
            );

        $this->artisan('shoot-uploads:audit-pending', ['--minutes' => 5])
            ->expectsOutput('Upload attempt audit complete: 1 stale pending attempt(s).')
            ->assertExitCode(0);

        $prunableIds = (new ShootUploadAttempt)->prunable()->pluck('id')->all();
        $this->assertContains($completed->id, $prunableIds);
        $this->assertNotContains($stalePending->id, $prunableIds);
    }

    public function test_photographer_auto_selects_one_pivot_assignment_and_must_choose_among_multiple(): void
    {
        Storage::fake('public');
        Queue::fake();
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $service = Service::factory()->create();
        $firstItem = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'photographer_id' => $photographer->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        Sanctum::actingAs($photographer);

        $dropbox = Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('uploadToTodo')->once()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null) => ShootFile::create([
                'shoot_id' => $target->id,
                'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(),
                'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg',
                'file_size' => $file->getSize(),
                'media_type' => 'raw',
                'uploaded_by' => $actorId,
                'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->create('front.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('uploaded_files.0.shoot_service_id', $firstItem->id);

        ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => Service::factory()->create()->id,
            'photographer_id' => $photographer->id,
            'price' => 75,
            'quantity' => 1,
        ]);

        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->create('kitchen.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'missing_service_item')
            ->assertJsonPath('success_count', 0);
    }
}
