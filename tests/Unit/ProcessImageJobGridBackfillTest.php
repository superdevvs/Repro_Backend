<?php

namespace Tests\Unit;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: the `grid` derivative (1000px desktop tile, added 26 Jul 2026)
 * must be backfilled for files that predate it. Such files already carry a
 * thumbnail and web rendition and a `processed_at` timestamp, so the
 * "already processed" short-circuit in ProcessImageJob used to skip them and
 * the grid rendition was never generated — leaving `grid_path` null forever
 * and `images:process-existing` a no-op. The gate must therefore also require
 * `grid_path` before deciding a file is fully processed.
 */
class ProcessImageJobGridBackfillTest extends TestCase
{
    private function processedFile(?string $gridPath): ShootFile
    {
        $file = new class extends ShootFile {
            public function save(array $options = []): bool
            {
                return true;
            }
        };

        $file->id = 4242;
        $file->shoot_id = 7;
        $file->filename = 'listing-photo.jpg';
        $file->scan_status = ShootFile::SCAN_STATUS_CLEAN;
        $file->path = 'originals/listing-photo.jpg';
        $file->processed_at = now();
        $file->thumbnail_path = 'thumbs/listing-photo.jpg';
        $file->web_path = 'web/listing-photo.jpg';
        $file->grid_path = $gridPath;

        return $file;
    }

    #[Test]
    public function a_processed_file_missing_its_grid_rendition_is_reprocessed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('originals/listing-photo.jpg', 'binary');

        $imageService = $this->createMock(ImageProcessingService::class);
        $dropbox = $this->createMock(DropboxWorkflowService::class);
        $media = $this->createMock(MediaStorage::class);

        $imageService->method('needsPreviewRegeneration')->willReturn(false);
        // The gate must NOT short-circuit: the missing grid rendition has to be
        // generated, so the image service is asked to (re)process the file.
        $imageService->expects($this->once())
            ->method('processImage')
            ->willReturn(true);

        (new ProcessImageJob($this->processedFile(null)))->handle($imageService, $dropbox, $media);
    }

    #[Test]
    public function a_fully_processed_file_with_a_grid_rendition_is_left_alone(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('originals/listing-photo.jpg', 'binary');

        $imageService = $this->createMock(ImageProcessingService::class);
        $dropbox = $this->createMock(DropboxWorkflowService::class);
        $media = $this->createMock(MediaStorage::class);

        $imageService->method('needsPreviewRegeneration')->willReturn(false);
        // Everything (thumbnail, web, grid) already exists — the job must keep
        // its idempotent short-circuit and never reprocess.
        $imageService->expects($this->never())->method('processImage');

        (new ProcessImageJob($this->processedFile('grid/listing-photo.jpg')))->handle($imageService, $dropbox, $media);
    }
}
