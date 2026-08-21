<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Jobs\GenerateWatermarkedImageJob;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\ScanShootFileJob;
use App\Services\ImageProcessingService;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the asynchronous media-archive generation side effect that the
        // ShootFile/Shoot observers dispatch on save/status change. In production
        // GenerateShootMediaArchiveJob runs on the queue (failures are logged via the
        // job's failed() handler); under the sync test queue it would otherwise execute
        // inline and surface unrelated archive errors. The malware scan is also a
        // separately covered integration boundary and must not require local clamd in
        // this controller suite. Tests that replace the queue fake include both jobs.
        Queue::fake([GenerateShootMediaArchiveJob::class, ScanShootFileJob::class]);
    }

    protected function insertUser(array $attributes = []): User
    {
        $payload = array_merge(User::factory()->raw(), $attributes, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('users')->insertGetId($payload);

        return User::query()->findOrFail($id);
    }

    protected function createShoot(array $overrides = []): Shoot
    {
        $client = $this->insertUser(['role' => 'client']);
        $photographer = $this->insertUser(['role' => 'photographer']);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Test Category ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
            'icon' => null,
            'is_default' => 0,
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'name' => 'Test Service ' . uniqid(),
            'description' => 'Test service',
            'price' => 125.00,
            'delivery_time' => 24,
            'category_id' => $categoryId,
            'icon' => null,
            'photographer_required' => 1,
            'photographer_pay' => null,
            'photo_count' => null,
            'pricing_type' => 'fixed',
            'allow_multiple' => 0,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shootId = DB::table('shoots')->insertGetId(array_merge([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $serviceId,
            'address' => '123 Test St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_date' => now()->toDateString(),
            'time' => '10:00',
            'base_quote' => 125.00,
            'tax_amount' => 0.00,
            'total_quote' => 125.00,
            'payment_status' => 'paid',
            'payment_type' => 'card',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'created_by' => 'test-suite',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Shoot::query()->findOrFail($shootId);
    }

    protected function createShootFile(Shoot $shoot, array $overrides = []): ShootFile
    {
        $filename = $overrides['filename'] ?? ('file-' . uniqid() . '.jpg');
        $storedFilename = $overrides['stored_filename'] ?? $filename;
        $path = $overrides['path'] ?? 'shoots/' . $shoot->id . '/completed/' . $storedFilename;
        $uploadedBy = $overrides['uploaded_by'] ?? $this->insertUser(['role' => 'admin'])->id;

        return ShootFile::create(array_merge([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $storedFilename,
            'path' => $path,
            'file_type' => 'image/jpeg',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => $uploadedBy,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function edited_files_endpoint_includes_supported_edited_formats_and_excludes_raw_camera_files(): void
    {
        $admin = $this->insertUser(['role' => 'admin']);
        $shoot = $this->createShoot();

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/front.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'living-room.webp',
            'stored_filename' => 'living-room.webp',
            'path' => 'shoots/' . $shoot->id . '/completed/living-room.webp',
            'file_type' => 'image/webp',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_VERIFIED,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'kitchen.tif',
            'stored_filename' => 'kitchen.tif',
            'path' => 'shoots/' . $shoot->id . '/completed/kitchen.tif',
            'file_type' => 'image/tiff',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'detail.heic',
            'stored_filename' => 'detail.heic',
            'path' => 'shoots/' . $shoot->id . '/completed/detail.heic',
            'file_type' => 'image/heic',
            'file_size' => 12345,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'bracket.nef',
            'stored_filename' => 'bracket.nef',
            'path' => 'shoots/' . $shoot->id . '/completed/bracket.nef',
            'file_type' => 'image/x-nikon-nef',
            'file_size' => 12345,
            'media_type' => 'raw',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited');

        $response->assertOk();

        $filenames = collect($response->json('data'))->pluck('filename')->all();

        $this->assertContains('front.jpg', $filenames);
        $this->assertContains('living-room.webp', $filenames);
        $this->assertContains('kitchen.tif', $filenames);
        $this->assertContains('detail.heic', $filenames);
        $this->assertNotContains('bracket.nef', $filenames);
    }

    #[Test]
    public function uploading_the_same_edited_filename_replaces_in_place_and_keeps_preview_files_available(): void
    {
        Storage::fake('public');
        Queue::fake([
            ProcessImageJob::class,
            SyncShootFileToDropboxJob::class,
            GenerateShootMediaArchiveJob::class,
            ScanShootFileJob::class,
        ]);
        Config::set('services.dropbox.enabled', false);
        Config::set('services.dropbox.access_token', null);
        // Filename replacement is the behavior under test; external metadata
        // extraction is covered separately and can emit process-level diagnostics
        // for PHPUnit-managed temporary files on Windows.
        $service = new class extends DropboxWorkflowService {
            protected function extractMetadataWithExifTool(?string $path): array
            {
                return [];
            }
        };
        app()->instance(ImageProcessingService::class, new class extends ImageProcessingService {
            public function processImageFromPath(int $shootId, string $fileName, string $sourcePath): array
            {
                $baseName = pathinfo($fileName, PATHINFO_FILENAME);
                $paths = [
                    'thumbnail' => "shoots/{$shootId}/thumbnails/{$baseName}_thumbnail.jpg",
                    'web' => "shoots/{$shootId}/webs/{$baseName}_web.jpg",
                    'placeholder' => "shoots/{$shootId}/placeholders/{$baseName}_placeholder.jpg",
                ];

                foreach ($paths as $path) {
                    Storage::disk('public')->put($path, 'generated-preview');
                }

                return $paths;
            }
        });

        $admin = $this->insertUser(['role' => 'admin']);
        $shoot = $this->createShoot();

        $firstUpload = UploadedFile::fake()->image('edited-shot.jpg', 2000, 1200);
        $firstFile = $service->uploadToCompleted($shoot, $firstUpload, $admin->id);
        $firstFile->update([
            'is_cover' => true,
            'is_hidden' => true,
            'sort_order' => 7,
        ]);
        $firstFile->refresh();

        $originalId = $firstFile->id;
        $originalPath = $firstFile->path;
        $originalThumbnailPath = $firstFile->thumbnail_path;
        $originalWebPath = $firstFile->web_path;
        $originalPlaceholderPath = $firstFile->placeholder_path;

        $this->assertNotNull($originalThumbnailPath);
        $this->assertNotNull($originalWebPath);
        $this->assertNotNull($originalPlaceholderPath);
        Storage::disk('public')->assertExists($originalPath);
        Storage::disk('public')->assertExists($originalThumbnailPath);
        Storage::disk('public')->assertExists($originalWebPath);
        Storage::disk('public')->assertExists($originalPlaceholderPath);

        $replacementUpload = UploadedFile::fake()->image('edited-shot.jpg', 1800, 1000);
        $replacementFile = $service->uploadToCompleted($shoot->fresh(), $replacementUpload, $admin->id)->fresh();

        $this->assertSame($originalId, $replacementFile->id);
        $this->assertNotSame($originalPath, $replacementFile->path);
        $this->assertSame($originalThumbnailPath, $replacementFile->thumbnail_path);
        $this->assertSame($originalWebPath, $replacementFile->web_path);
        $this->assertSame($originalPlaceholderPath, $replacementFile->placeholder_path);
        $this->assertTrue((bool) $replacementFile->is_cover);
        $this->assertTrue((bool) $replacementFile->is_hidden);
        $this->assertSame(7, $replacementFile->sort_order);
        $this->assertNotNull($replacementFile->processed_at);

        $this->assertSame(
            1,
            ShootFile::where('shoot_id', $shoot->id)
                ->where('filename', 'edited-shot.jpg')
                ->where('workflow_stage', ShootFile::STAGE_COMPLETED)
                ->count()
        );

        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($replacementFile->path);
        Storage::disk('public')->assertExists($replacementFile->thumbnail_path);
        Storage::disk('public')->assertExists($replacementFile->web_path);
        Storage::disk('public')->assertExists($replacementFile->placeholder_path);
    }

    #[Test]
    public function client_can_reorder_media_for_their_own_shoot(): void
    {
        $shoot = $this->createShoot();
        $client = User::query()->findOrFail($shoot->client_id);

        $first = $this->createShootFile($shoot, ['filename' => 'first.jpg', 'sort_order' => 0]);
        $second = $this->createShootFile($shoot, ['filename' => 'second.jpg', 'sort_order' => 1]);
        $third = $this->createShootFile($shoot, ['filename' => 'third.jpg', 'sort_order' => 2]);

        Sanctum::actingAs($client);

        $this->patchJson('/api/shoots/' . $shoot->id . '/files/reorder', [
            'file_ids' => [$third->id, $first->id, $second->id],
        ])->assertOk();

        // Positions are 1-based. The media tab decides whether a shoot has a
        // saved arrangement with `(sort_order ?? 0) > 0`, so a first item stored
        // as 0 was indistinguishable from an unset column and the manual order
        // was discarded and re-derived. See ShootMediaInteractionService::reorderFiles.
        $this->assertSame(1, $third->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(3, $second->fresh()->sort_order);
    }

    #[Test]
    public function client_cannot_reorder_media_for_another_clients_shoot(): void
    {
        $shoot = $this->createShoot();
        $otherClient = $this->insertUser(['role' => 'client']);
        $file = $this->createShootFile($shoot, ['filename' => 'locked.jpg']);

        Sanctum::actingAs($otherClient);

        $this->patchJson('/api/shoots/' . $shoot->id . '/files/reorder', [
            'file_ids' => [$file->id],
        ])->assertForbidden();
    }

    #[Test]
    public function sales_rep_receives_structured_forbidden_error_for_raw_uploads(): void
    {
        Storage::fake('public');

        $photographer = $this->insertUser(['role' => 'photographer']);
        $salesRep = $this->insertUser(['role' => 'salesRep']);
        $shoot = $this->createShoot([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);

        // Sales representatives may view their assigned shoots, but upload
        // provenance is restricted to admins and assigned production roles.
        Sanctum::actingAs($salesRep);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/upload', [
            'upload_type' => 'raw',
            'files' => [
                UploadedFile::fake()->create('batch.nef', 100, 'image/x-nikon-nef'),
            ],
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('error_type', 'forbidden');
        $response->assertJsonPath('message', 'You do not have permission to upload media for this shoot.');
        $response->assertJsonPath('success_count', 0);
        $response->assertJsonPath('partial_success', false);
    }

    #[Test]
    public function listing_raw_files_queues_reprocessing_for_small_cr3_placeholder_previews(): void
    {
        Storage::fake('public');
        Queue::fake([ProcessImageJob::class, GenerateShootMediaArchiveJob::class]);

        $admin = $this->insertUser(['role' => 'admin']);
        $shoot = $this->createShoot([
            'status' => Shoot::STATUS_UPLOADED,
            'workflow_status' => Shoot::STATUS_UPLOADED,
        ]);

        $thumbnailPath = 'shoots/' . $shoot->id . '/thumbnails/bracket_thumbnail.jpg';
        $webPath = 'shoots/' . $shoot->id . '/webs/bracket_web.jpg';
        $rawPath = 'shoots/' . $shoot->id . '/todo/bracket.cr3';

        $thumbnailImage = UploadedFile::fake()->image('thumb.jpg', 300, 300);
        $webImage = UploadedFile::fake()->image('web.jpg', 300, 300);

        Storage::disk('public')->put($thumbnailPath, file_get_contents($thumbnailImage->getRealPath()));
        Storage::disk('public')->put($webPath, file_get_contents($webImage->getRealPath()));
        Storage::disk('public')->put($rawPath, 'raw-binary-placeholder');

        $file = $this->createShootFile($shoot, [
            'filename' => 'bracket.cr3',
            'stored_filename' => 'bracket.cr3',
            'path' => $rawPath,
            'file_type' => 'image/x-canon-cr3',
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'thumbnail_path' => $thumbnailPath,
            'web_path' => $webPath,
            'processed_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/shoots/' . $shoot->id . '/files?type=raw')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $file->id,
                'filename' => 'bracket.cr3',
            ]);

        Queue::assertPushed(ProcessImageJob::class, function (ProcessImageJob $job) use ($file) {
            $reflection = new \ReflectionProperty($job, 'shootFile');
            $reflection->setAccessible(true);

            return $reflection->getValue($job)->id === $file->id;
        });
    }

    #[Test]
    public function client_can_set_hero_image_for_their_own_shoot_and_it_is_logged_in_activity(): void
    {
        $shoot = $this->createShoot();
        $client = User::query()->findOrFail($shoot->client_id);

        $existingHero = $this->createShootFile($shoot, [
            'filename' => 'existing-hero.jpg',
            'is_cover' => true,
        ]);
        $targetFile = $this->createShootFile($shoot, [
            'filename' => 'new-hero.jpg',
            'web_path' => 'shoots/' . $shoot->id . '/webs/new-hero_web.jpg',
            'thumbnail_path' => 'shoots/' . $shoot->id . '/thumbnails/new-hero_thumb.jpg',
        ]);

        Sanctum::actingAs($client);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/media/' . $targetFile->id . '/cover');
        $response->assertOk()
            ->assertJsonPath('file.id', $targetFile->id);

        $this->assertFalse((bool) $existingHero->fresh()->is_cover);
        $this->assertTrue((bool) $targetFile->fresh()->is_cover);
        $this->assertNotNull($shoot->fresh()->hero_image);
        $this->assertStringContainsString('new-hero', $shoot->fresh()->hero_image);

        $log = DB::table('shoot_activity_logs')
            ->where('shoot_id', $shoot->id)
            ->where('action', 'hero_image_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($client->id, $log->user_id);
        $this->assertStringContainsString('Hero image updated by ' . $client->name, $log->description);
        $this->assertStringContainsString('new-hero.jpg', $log->description);

        $this->getJson('/api/shoots/' . $shoot->id . '/activity-log')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'hero_image_updated',
                'description' => $log->description,
            ]);
    }

    #[Test]
    public function client_cannot_set_hero_image_for_another_clients_shoot(): void
    {
        $shoot = $this->createShoot();
        $otherClient = $this->insertUser(['role' => 'client']);
        $file = $this->createShootFile($shoot, ['filename' => 'other-owner.jpg']);

        Sanctum::actingAs($otherClient);

        $this->postJson('/api/shoots/' . $shoot->id . '/media/' . $file->id . '/cover')
            ->assertForbidden();
    }

    #[Test]
    public function client_cannot_set_hero_image_for_ineligible_media(): void
    {
        $shoot = $this->createShoot();
        $client = User::query()->findOrFail($shoot->client_id);
        $video = $this->createShootFile($shoot, [
            'filename' => 'walkthrough.mp4',
            'stored_filename' => 'walkthrough.mp4',
            'path' => 'shoots/' . $shoot->id . '/completed/walkthrough.mp4',
            'file_type' => 'video/mp4',
            'media_type' => 'video',
        ]);

        Sanctum::actingAs($client);

        $this->postJson('/api/shoots/' . $shoot->id . '/media/' . $video->id . '/cover')
            ->assertStatus(422);
    }

    #[Test]
    public function unpaid_client_receives_watermarked_media_payload(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/preview.jpg',
            'web_path' => null,
            'thumbnail_path' => null,
            'placeholder_path' => null,
            'watermarked_storage_path' => 'shoots/' . $shoot->id . '/watermarked/preview.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/preview_web.jpg',
            'watermarked_thumbnail_path' => 'shoots/' . $shoot->id . '/watermarked/preview_thumb.jpg',
            'watermarked_placeholder_path' => 'shoots/' . $shoot->id . '/watermarked/preview_placeholder.jpg',
        ]);

        Storage::disk('public')->put($file->watermarked_web_path, 'preview-web');
        Storage::disk('public')->put($file->watermarked_thumbnail_path, 'preview-thumb');
        Storage::disk('public')->put($file->watermarked_placeholder_path, 'preview-placeholder');

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited');
        $response->assertOk();

        $payload = $response->json('data.0');

        $this->assertTrue($payload['uses_watermark']);
        $this->assertNull($payload['path']);
        $this->assertNull($payload['thumbnail_path']);
        $this->assertNull($payload['web_path']);
        $this->assertArrayHasKey('watermarked_web_path', $payload);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/watermarked/preview_web.jpg', $payload['url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/watermarked/preview_web.jpg', $payload['original_url']);
    }

    #[Test]
    public function partial_client_receives_watermarked_media_payload(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'partial',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'partial-preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/partial-preview.jpg',
            'web_path' => 'shoots/' . $shoot->id . '/completed/partial-preview-web.jpg',
            'thumbnail_path' => 'shoots/' . $shoot->id . '/completed/partial-preview-thumb.jpg',
            'watermarked_storage_path' => 'shoots/' . $shoot->id . '/watermarked/partial-preview.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/partial-preview_web.jpg',
            'watermarked_thumbnail_path' => 'shoots/' . $shoot->id . '/watermarked/partial-preview_thumb.jpg',
            'watermarked_placeholder_path' => 'shoots/' . $shoot->id . '/watermarked/partial-preview_placeholder.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->web_path, 'web-preview');
        Storage::disk('public')->put($file->thumbnail_path, 'thumb-preview');
        Storage::disk('public')->put($file->watermarked_web_path, 'watermarked-web');
        Storage::disk('public')->put($file->watermarked_thumbnail_path, 'watermarked-thumb');
        Storage::disk('public')->put($file->watermarked_placeholder_path, 'watermarked-placeholder');

        Sanctum::actingAs($client);

        $payload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($payload['uses_watermark']);
        $this->assertNull($payload['path']);
        $this->assertNull($payload['web_path']);
        $this->assertNull($payload['thumbnail_path']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/watermarked/partial-preview_web.jpg', $payload['url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/watermarked/partial-preview_web.jpg', $payload['original_url']);
    }

    #[Test]
    public function admin_and_paid_clients_receive_non_watermarked_media_payloads(): void
    {
        $shoot = $this->createShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);
        $admin = $this->insertUser(['role' => 'admin']);

        $this->createShootFile($shoot, [
            'filename' => 'final.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/final.jpg',
            'web_path' => null,
            'thumbnail_path' => null,
            'placeholder_path' => null,
            'watermarked_storage_path' => 'shoots/' . $shoot->id . '/watermarked/final.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/final_web.jpg',
            'watermarked_thumbnail_path' => 'shoots/' . $shoot->id . '/watermarked/final_thumb.jpg',
            'watermarked_placeholder_path' => 'shoots/' . $shoot->id . '/watermarked/final_placeholder.jpg',
        ]);

        Sanctum::actingAs($client);
        $clientPayload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($clientPayload['uses_watermark']);
        $this->assertArrayNotHasKey('watermarked_web_path', $clientPayload);
        $this->assertSame($clientPayload['url'], $clientPayload['original_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final.jpg', $clientPayload['url']);

        $shoot->update(['payment_status' => 'unpaid']);

        Sanctum::actingAs($admin);
        $adminPayload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($adminPayload['uses_watermark']);
        $this->assertArrayNotHasKey('watermarked_web_path', $adminPayload);
        $this->assertSame($adminPayload['url'], $adminPayload['original_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final.jpg', $adminPayload['url']);
    }

    #[Test]
    public function non_watermarked_media_payload_prefers_web_preview_over_thumbnail(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'final-web.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/final-web-original.jpg',
            'web_path' => 'shoots/' . $shoot->id . '/completed/final-web.jpg',
            'thumbnail_path' => 'shoots/' . $shoot->id . '/completed/final-thumb.jpg',
            'placeholder_path' => 'shoots/' . $shoot->id . '/completed/final-placeholder.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->web_path, 'web-preview');
        Storage::disk('public')->put($file->thumbnail_path, 'thumb-preview');
        Storage::disk('public')->put($file->placeholder_path, 'placeholder-preview');

        Sanctum::actingAs($client);

        $payload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($payload['uses_watermark']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-web.jpg', $payload['url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-web.jpg', $payload['web_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-web.jpg', $payload['medium_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-thumb.jpg', $payload['thumb_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-web-original.jpg', $payload['original_url']);
    }

    #[Test]
    public function unpaid_client_preview_route_serves_watermarked_preview_file(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'locked-preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/locked-preview-original.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/locked-preview_web.jpg',
            'watermarked_thumbnail_path' => 'shoots/' . $shoot->id . '/watermarked/locked-preview_thumb.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->watermarked_web_path, 'watermarked-web');
        Storage::disk('public')->put($file->watermarked_thumbnail_path, 'watermarked-thumb');

        Sanctum::actingAs($client);

        $response = $this->get('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/preview');

        $response->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            'locked-preview_web.jpg',
            $response->baseResponse->getFile()->getFilename()
        );
    }

    #[Test]
    public function partial_client_preview_route_serves_watermarked_preview_file(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'partial',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'partial-locked-preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/partial-locked-preview-original.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/partial-locked-preview_web.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->watermarked_web_path, 'watermarked-web');

        Sanctum::actingAs($client);

        $response = $this->get('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/preview');

        $response->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            'partial-locked-preview_web.jpg',
            $response->baseResponse->getFile()->getFilename()
        );
    }

    #[Test]
    public function paid_client_and_admin_preview_route_serve_original_file(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);
        $admin = $this->insertUser(['role' => 'admin']);

        $file = $this->createShootFile($shoot, [
            'filename' => 'open-preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/open-preview-original.jpg',
            'watermarked_web_path' => 'shoots/' . $shoot->id . '/watermarked/open-preview_web.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->watermarked_web_path, 'watermarked-web');

        Sanctum::actingAs($client);
        $clientResponse = $this->get('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/preview');
        $clientResponse->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $clientResponse->baseResponse);
        $this->assertSame(
            'open-preview-original.jpg',
            $clientResponse->baseResponse->getFile()->getFilename()
        );

        $shoot->update(['payment_status' => 'unpaid']);

        Sanctum::actingAs($admin);
        $adminResponse = $this->get('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/preview');
        $adminResponse->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $adminResponse->baseResponse);
        $this->assertSame(
            'open-preview-original.jpg',
            $adminResponse->baseResponse->getFile()->getFilename()
        );
    }

    #[Test]
    public function locked_client_preview_route_queues_watermark_and_never_falls_back_to_original(): void
    {
        Storage::fake('public');
        Queue::fake([GenerateWatermarkedImageJob::class, GenerateShootMediaArchiveJob::class]);

        $shoot = $this->createShoot([
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'queued-preview.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/queued-preview-original.jpg',
            'watermarked_web_path' => null,
            'watermarked_thumbnail_path' => null,
            'watermarked_placeholder_path' => null,
        ]);

        Storage::disk('public')->put($file->path, 'original');

        Sanctum::actingAs($client);

        $this->getJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/preview')
            ->assertStatus(409)
            ->assertJsonPath('code', 'watermark_processing');

        Queue::assertPushed(GenerateWatermarkedImageJob::class);
    }

    #[Test]
    public function non_watermarked_media_payload_does_not_label_thumbnail_as_web_preview(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $file = $this->createShootFile($shoot, [
            'filename' => 'final-thumb-only.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/final-thumb-only-original.jpg',
            'web_path' => null,
            'thumbnail_path' => 'shoots/' . $shoot->id . '/completed/final-thumb-only-thumb.jpg',
            'placeholder_path' => 'shoots/' . $shoot->id . '/completed/final-thumb-only-placeholder.jpg',
        ]);

        Storage::disk('public')->put($file->path, 'original');
        Storage::disk('public')->put($file->thumbnail_path, 'thumb-preview');
        Storage::disk('public')->put($file->placeholder_path, 'placeholder-preview');

        Sanctum::actingAs($client);

        $payload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($payload['uses_watermark']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-thumb-only-thumb.jpg', $payload['url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-thumb-only-thumb.jpg', $payload['thumb_url']);
        $this->assertArrayNotHasKey('web_url', $payload);
        $this->assertArrayNotHasKey('medium_url', $payload);
        $this->assertArrayNotHasKey('large_url', $payload);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/final-thumb-only-original.jpg', $payload['original_url']);
    }

    #[Test]
    public function dropbox_backed_media_payload_generates_web_preview_instead_of_using_original_for_display(): void
    {
        Storage::fake('public');

        $shoot = $this->createShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
        ]);
        $client = User::query()->findOrFail($shoot->client_id);

        $tempSource = tempnam(sys_get_temp_dir(), 'shoot-preview-');
        file_put_contents($tempSource, 'dropbox-original');

        app()->instance(ImageProcessingService::class, new class extends ImageProcessingService {
            public function processImageFromPath(int $shootId, string $fileName, string $sourcePath): array
            {
                $paths = [
                    'thumbnail' => "shoots/{$shootId}/completed/generated-thumb.jpg",
                    'web' => "shoots/{$shootId}/completed/generated-web.jpg",
                    'placeholder' => "shoots/{$shootId}/completed/generated-placeholder.jpg",
                ];

                foreach ($paths as $path) {
                    Storage::disk('public')->put($path, 'generated-preview');
                }

                return $paths;
            }
        });

        $dropbox = \Mockery::mock(DropboxWorkflowService::class);
        $dropbox->shouldReceive('downloadToTemp')
            ->once()
            ->andReturn($tempSource);
        $dropbox->shouldReceive('getTemporaryLink')
            ->once()
            ->andReturn('https://dropbox.test/original/final.jpg');
        app()->instance(DropboxWorkflowService::class, $dropbox);

        $this->createShootFile($shoot, [
            'filename' => 'final.jpg',
            'path' => 'remote/final.jpg',
            'storage_path' => null,
            'dropbox_path' => '/shoots/' . $shoot->id . '/completed/final.jpg',
        ]);

        Sanctum::actingAs($client);

        $payload = $this->getJson('/api/shoots/' . $shoot->id . '/files?type=edited')
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($payload['uses_watermark']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/generated-web.jpg', $payload['url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/generated-web.jpg', $payload['web_url']);
        $this->assertStringContainsString('/storage/shoots/' . $shoot->id . '/completed/generated-thumb.jpg', $payload['thumb_url']);
        $this->assertSame('https://dropbox.test/original/final.jpg', $payload['original_url']);

        if (file_exists($tempSource)) {
            @unlink($tempSource);
        }
    }
}
