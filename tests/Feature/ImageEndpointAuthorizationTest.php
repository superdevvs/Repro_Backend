<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageEndpointAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.r2_only' => false, 'media.read_from_r2' => false]);
    }

    public function test_unassigned_editor_cannot_read_process_or_reprocess_a_file(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => null, 'status' => 'delivered']);
        $file = $this->file($shoot, ['processed_at' => now(), 'web_path' => 'previews/private.jpg']);
        Storage::disk('public')->put('previews/private.jpg', 'private-preview');
        Sanctum::actingAs($editor);

        foreach (['download/original', 'download/web', 'status'] as $path) {
            $this->getJson("/api/images/{$file->id}/{$path}")->assertForbidden();
        }
        foreach (['process', 'reprocess'] as $path) {
            $this->postJson("/api/images/{$file->id}/{$path}")->assertForbidden();
        }
        $this->postJson('/api/images/process/batch', ['file_ids' => [$file->id]])->assertForbidden();
        $this->postJson('/api/images/download/batch', ['file_ids' => [$file->id]])->assertNotFound();
        $this->assertNotNull($file->fresh()->processed_at);
        Storage::disk('public')->assertExists('previews/private.jpg');
        Queue::assertNotPushed(ProcessImageJob::class);
    }

    public function test_processing_mixed_assignment_batch_is_rejected_before_dispatch(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $own = $this->file(Shoot::factory()->create(['editor_id' => $editor->id]));
        $foreign = $this->file(Shoot::factory()->create(['editor_id' => null]));
        Sanctum::actingAs($editor);

        $this->postJson('/api/images/process/batch', ['file_ids' => [$own->id, $foreign->id]])->assertForbidden();
        Queue::assertNotPushed(ProcessImageJob::class);
        $this->postJson("/api/images/{$own->id}/process")->assertOk();
        Queue::assertPushed(ProcessImageJob::class, 1);
        $this->getJson("/api/images/{$own->id}/status")->assertOk();
    }

    public function test_client_cannot_use_image_routes_to_bypass_release_or_raw_file_rules(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'payment_status' => 'unpaid',
            'status' => 'delivered', 'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $edited = $this->file($shoot, ['workflow_stage' => ShootFile::STAGE_COMPLETED]);
        Storage::disk('local')->put($edited->path, 'released-image');
        Sanctum::actingAs($client);

        $this->getJson("/api/images/{$edited->id}/download/original")->assertForbidden();
        $this->getJson("/api/images/{$edited->id}/status")->assertForbidden();
        $this->postJson("/api/images/{$edited->id}/process")->assertForbidden();

        $shoot->update(['payment_status' => 'paid']);
        $this->get("/api/images/{$edited->id}/download/original")->assertOk();
        $raw = $this->file($shoot, ['filename' => 'camera.cr3', 'media_type' => 'raw']);
        $this->getJson("/api/images/{$raw->id}/download/original")->assertForbidden();
        $this->getJson("/api/images/{$raw->id}/download/web")->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/media/download-zip?type=raw")->assertForbidden();
    }

    public function test_shoot_assignment_does_not_grant_another_photographers_service_files(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $otherPhotographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        $service = Service::factory()->create();
        $shoot->services()->attach($service->id, [
            'price' => 100, 'quantity' => 1, 'photographer_id' => $otherPhotographer->id,
        ]);
        $serviceItem = $shoot->serviceItems()->where('service_id', $service->id)->firstOrFail();
        $file = $this->file($shoot, ['shoot_service_id' => $serviceItem->id]);
        Sanctum::actingAs($photographer);

        foreach (['download/original', 'download/web', 'status'] as $path) {
            $this->getJson("/api/images/{$file->id}/{$path}")->assertForbidden();
        }
        $this->postJson("/api/images/{$file->id}/process")->assertForbidden();
        $this->postJson("/api/images/{$file->id}/reprocess")->assertForbidden();
        $this->getJson("/api/shoots/{$shoot->id}/media/download-zip?type=raw")->assertForbidden();
        Queue::assertNotPushed(ProcessImageJob::class);

        Sanctum::actingAs($otherPhotographer);
        $this->getJson("/api/images/{$file->id}/status")->assertOk();
    }

    private function file(Shoot $shoot, array $overrides = []): ShootFile
    {
        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'uploaded_by' => $shoot->photographer_id,
            'filename' => 'image.jpg', 'stored_filename' => 'image.jpg',
            'path' => "shoots/{$shoot->id}/image.jpg", 'file_type' => 'image/jpeg',
            'file_size' => 12, 'media_type' => 'image',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'required_for_editing' => true,
        ], $overrides));
    }
}
