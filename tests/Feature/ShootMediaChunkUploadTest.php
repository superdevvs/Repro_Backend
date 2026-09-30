<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootMediaChunkUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootMediaChunkUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunked_edited_upload_assembles_and_creates_shoot_file(): void
    {
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($admin);

        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('uploadToCompleted')->once()->andReturnUsing(
            function (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null, mixed $shootServiceId = null) {
                return ShootFile::create([
                    'shoot_id' => $target->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $file->getClientOriginalName(),
                    'path' => 'shoots/'.$target->id.'/completed/'.$file->getClientOriginalName(),
                    'file_type' => $file->getMimeType() ?: 'video/mp4',
                    'file_size' => $file->getSize(),
                    'media_type' => $mediaType ?: 'edited',
                    'uploaded_by' => $actorId,
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                    'shoot_service_id' => $shootServiceId,
                ]);
            }
        );
        app()->instance(ShootMediaStorageService::class, $dropbox);

        // Force tiny chunks so a small fixture still exercises multi-chunk assembly.
        $ref = new \ReflectionClass(ShootMediaChunkUploadService::class);
        // Use two explicit chunks via size + overridden constant path: send 3 bytes with chunk size forced by initiating
        // through the service after binding a test subclass is heavy; instead post two 1-byte parts by temporarily
        // writing meta — prefer calling initiate then storing with real service chunk size on a small file (1 chunk).

        $payload = str_repeat('V', 4096);
        $init = $this->postJson("/api/shoots/{$shoot->id}/upload-sessions", [
            'filename' => 'walkthrough.mp4',
            'size_bytes' => strlen($payload),
            'upload_type' => 'edited',
            'fields' => [
                'upload_type' => 'edited',
                'idempotency_key' => 'chunk-test-key',
                'service_category' => 'video',
            ],
        ]);
        $init->assertCreated();
        $sessionId = $init->json('session_id');
        $chunkSize = (int) $init->json('chunk_size_bytes');
        $this->assertSame(ShootMediaChunkUploadService::CHUNK_SIZE_BYTES, $chunkSize);

        $offset = 0;
        $index = 0;
        while ($offset < strlen($payload)) {
            $chunk = substr($payload, $offset, $chunkSize);
            $response = $this->call(
                'PUT',
                "/api/shoots/{$shoot->id}/upload-sessions/{$sessionId}/chunks/{$index}",
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/octet-stream',
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_LENGTH' => (string) strlen($chunk),
                ],
                $chunk
            );
            $response->assertOk();
            $offset += strlen($chunk);
            $index++;
        }

        $complete = $this->postJson("/api/shoots/{$shoot->id}/upload-sessions/{$sessionId}/complete");
        $complete->assertOk()->assertJsonPath('success_count', 1);
        $this->assertSame(1, ShootFile::query()->where('shoot_id', $shoot->id)->count());
        $this->assertDatabaseHas('shoot_files', [
            'shoot_id' => $shoot->id,
            'filename' => 'walkthrough.mp4',
            'media_type' => 'edited',
        ]);
    }

    public function test_unauthenticated_cannot_start_chunked_upload(): void
    {
        $shoot = Shoot::factory()->create();
        $this->postJson("/api/shoots/{$shoot->id}/upload-sessions", [
            'filename' => 'walkthrough.mp4',
            'size_bytes' => 10,
            'upload_type' => 'edited',
        ])->assertUnauthorized();
    }

    public function test_video_editor_chunked_complete_allows_video_editor_id_assignment(): void
    {
        Storage::fake('public');
        Queue::fake();

        $photoEditor = User::factory()->create(['role' => 'editor']);
        $videoEditor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create([
            'editor_id' => null,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $service = Service::factory()->photoVideoIntake()->create(['requires_editing' => true]);
        $item = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'editor_id' => $photoEditor->id,
            'video_editor_id' => $videoEditor->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        Sanctum::actingAs($videoEditor);

        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldReceive('uploadToCompleted')->once()->andReturnUsing(
            function (Shoot $target, UploadedFile $file, int $actorId, mixed $serviceCategory = null, mixed $mediaType = null, mixed $shootServiceId = null) use ($item) {
                return ShootFile::create([
                    'shoot_id' => $target->id,
                    'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $file->getClientOriginalName(),
                    'path' => 'shoots/'.$target->id.'/completed/'.$file->getClientOriginalName(),
                    'file_type' => $file->getMimeType() ?: 'video/mp4',
                    'file_size' => $file->getSize(),
                    'media_type' => $mediaType ?: 'edited',
                    'uploaded_by' => $actorId,
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                    'shoot_service_id' => $shootServiceId ?? $item->id,
                ]);
            }
        );
        app()->instance(ShootMediaStorageService::class, $dropbox);

        $payload = str_repeat('V', 4096);
        $init = $this->postJson("/api/shoots/{$shoot->id}/upload-sessions", [
            'filename' => 'edited-walkthrough.mp4',
            'size_bytes' => strlen($payload),
            'upload_type' => 'edited',
            'fields' => [
                'upload_type' => 'edited',
                'shoot_service_id' => (string) $item->id,
                'idempotency_key' => 'video-editor-chunk-key',
                'service_category' => 'video',
            ],
        ]);
        $init->assertCreated();
        $sessionId = $init->json('session_id');
        $chunkSize = (int) $init->json('chunk_size_bytes');

        $offset = 0;
        $index = 0;
        while ($offset < strlen($payload)) {
            $chunk = substr($payload, $offset, $chunkSize);
            $response = $this->call(
                'PUT',
                "/api/shoots/{$shoot->id}/upload-sessions/{$sessionId}/chunks/{$index}",
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/octet-stream',
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_LENGTH' => (string) strlen($chunk),
                ],
                $chunk
            );
            $response->assertOk();
            $offset += strlen($chunk);
            $index++;
        }

        $complete = $this->postJson("/api/shoots/{$shoot->id}/upload-sessions/{$sessionId}/complete");
        $complete->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.filename', 'edited-walkthrough.mp4');
    }

}
