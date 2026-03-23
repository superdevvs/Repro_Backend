<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Jobs\SyncShootFileToDropboxJob;
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
use Illuminate\Contracts\Console\Kernel;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootFilesTest extends TestCase
{
    protected ?string $testDatabasePath = null;

    public function createApplication()
    {
        $databaseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database';
        $source = $databaseDir . DIRECTORY_SEPARATOR . 'database.sqlite';
        $copy = $databaseDir . DIRECTORY_SEPARATOR . 'test-shoot-files-' . uniqid('', true) . '.sqlite';
        copy($source, $copy);

        $this->testDatabasePath = $copy;

        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=' . $copy);
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $copy;
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = $copy;

        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();

        if ($this->testDatabasePath && file_exists($this->testDatabasePath)) {
            try {
                @unlink($this->testDatabasePath);
            } catch (\Throwable $exception) {
                // Ignore temp DB cleanup issues on Windows file handles.
            }
        }
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
        ]);
        Config::set('services.dropbox.enabled', false);
        Config::set('services.dropbox.access_token', null);
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
        $service = app(DropboxWorkflowService::class);

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
}
