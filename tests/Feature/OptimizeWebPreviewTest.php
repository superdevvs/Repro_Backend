<?php
namespace Tests\Feature;

use App\Jobs\OptimizeWebPreview;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptimizeWebPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_versioned_idempotent_and_reversible_without_changing_original_or_grid(): void
    {
        Queue::fake(); Storage::fake('local'); Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false, 'media.r2_only' => false, 'media.read_from_r2' => false]);
        $shoot = Shoot::factory()->create();
        $source = UploadedFile::fake()->image('interior.jpg', 2400, 1600);
        $path = "shoots/{$shoot->id}/completed/source.jpg";
        Storage::disk('local')->put($path, file_get_contents($source->getRealPath()));
        Storage::disk('local')->put('old-web.jpg', 'old web');
        Storage::disk('local')->put('old-grid.jpg', 'old grid');
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'interior.jpg', 'stored_filename' => 'source.jpg',
            'uploaded_by' => $shoot->client_id, 'path' => $path, 'storage_path' => $path, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => $source->getSize(),
            'media_type' => 'edited', 'workflow_stage' => 'verified', 'scan_status' => 'clean', 'web_path' => 'old-web.jpg', 'grid_path' => 'old-grid.jpg']);
        $queuedBefore = Queue::pushedJobs();
        $originalHash = hash('sha256', Storage::disk('local')->get($path));
        app()->call([new OptimizeWebPreview($file->id), 'handle']);
        $file->refresh();
        $this->assertStringContainsString('/web-v2/', $file->web_path);
        $firstPath = $file->web_path;
        $size = getimagesize(Storage::disk('local')->path($firstPath));
        $this->assertSame([1500, 1000], [$size[0], $size[1]]);
        app()->call([new OptimizeWebPreview($file->id), 'handle']);
        $this->assertSame($firstPath, $file->fresh()->web_path);
        $this->assertSame($originalHash, hash('sha256', Storage::disk('local')->get($path)));
        $this->assertSame('old-grid.jpg', $file->grid_path);
        Storage::disk('local')->assertExists('old-web.jpg');
        $journals = Storage::disk('local')->files('performance/web-v2');
        $this->assertCount(1, $journals);
        $this->artisan('media:optimize-web', ['--rollback' => basename($journals[0])])->assertExitCode(0);
        $this->assertSame('old-web.jpg', $file->fresh()->web_path);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertSame($queuedBefore, Queue::pushedJobs());
    }

    public function test_backfill_excludes_nonphotographic_and_quarantined_media(): void
    {
        foreach (['raw', 'video', 'floorplan'] as $type) $this->assertFalse(OptimizeWebPreview::eligible(new ShootFile(['filename' => 'source.jpg', 'media_type' => $type])));
        $this->assertFalse(OptimizeWebPreview::eligible(new ShootFile(['filename' => 'source.jpg', 'media_type' => 'edited', 'scan_status' => 'quarantined'])));
    }
}
