<?php

namespace Tests\Unit\Scanning;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ProcessImageJob must withhold (skip) any file that has not cleared the virus
 * scan (Req 14.3 / 15.1 / 15.4): quarantined, infected, and failed files are
 * never processed; clean and legacy(null) files proceed.
 */
class ProcessImageJobScanGateTest extends TestCase
{
    private function file(?string $scanStatus): ShootFile
    {
        $file = new class extends ShootFile {
            public function save(array $options = []): bool
            {
                return true;
            }
        };

        $file->id = 555;
        $file->shoot_id = 9;
        $file->filename = 'photo.jpg';
        $file->scan_status = $scanStatus;
        // Pre-set processed markers so that, once the gate is passed, the clean
        // path short-circuits on "already processed" without touching storage.
        $file->processed_at = now();
        $file->thumbnail_path = 'thumbs/photo.jpg';
        $file->web_path = 'web/photo.jpg';

        return $file;
    }

    #[Test]
    public function non_clean_files_are_skipped_before_any_processing(): void
    {
        foreach ([
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_INFECTED,
            ShootFile::SCAN_STATUS_FAILED,
        ] as $status) {
            $imageService = $this->createMock(ImageProcessingService::class);
            $dropbox = $this->createMock(DropboxWorkflowService::class);

            // If the gate fails to withhold, the job would consult the image
            // service — assert it is never touched for a non-clean file.
            $imageService->expects($this->never())->method('needsPreviewRegeneration');
            $imageService->expects($this->never())->method('processImage');

            (new ProcessImageJob($this->file($status)))->handle($imageService, $dropbox, $this->createMock(MediaStorage::class));
        }
    }

    #[Test]
    public function clean_files_pass_the_gate_into_processing(): void
    {
        $imageService = $this->createMock(ImageProcessingService::class);
        $dropbox = $this->createMock(DropboxWorkflowService::class);
        $media = $this->createMock(MediaStorage::class);

        // Passing the gate means the job consults the image service; returning
        // false here keeps the "already processed" short-circuit cheap.
        $imageService->expects($this->once())
            ->method('needsPreviewRegeneration')
            ->willReturn(false);

        (new ProcessImageJob($this->file(ShootFile::SCAN_STATUS_CLEAN)))->handle($imageService, $dropbox, $media);
    }

    #[Test]
    public function legacy_null_status_files_pass_the_gate_into_processing(): void
    {
        $imageService = $this->createMock(ImageProcessingService::class);
        $dropbox = $this->createMock(DropboxWorkflowService::class);
        $media = $this->createMock(MediaStorage::class);

        $imageService->expects($this->once())
            ->method('needsPreviewRegeneration')
            ->willReturn(false);

        (new ProcessImageJob($this->file(null)))->handle($imageService, $dropbox, $media);
    }
}
