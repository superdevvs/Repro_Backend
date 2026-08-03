<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\ImageProcessingService;
use App\Services\DropboxWorkflowService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [5, 10, 30]; // Retry after 5s, 10s, then 30s
    public $timeout = 120; // 2 minutes timeout

    protected ShootFile $shootFile;

    /**
     * Create a new job instance.
     */
    public function __construct(ShootFile $shootFile)
    {
        $this->shootFile = $shootFile;
        
        // Use default queue (matches running workers)
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(ImageProcessingService $imageService, DropboxWorkflowService $dropboxService, \App\Services\Media\MediaStorage $media): void
    {
        // Quarantine gate (Req 14.3 / 15.1 / 15.4): downstream image processing is
        // withheld unless the file has cleared the virus scan (clean, or a legacy
        // file with no scan row). Quarantined/infected/failed files are skipped so
        // unscanned or unsafe media is never processed for delivery. ProcessImageJob
        // is normally dispatched only by FileScanService::release once clean, but
        // this guard makes the invariant hold regardless of the dispatch path.
        if (! $this->shootFile->isClearedForProcessing()) {
            Log::info('ProcessImageJob skipped — file not cleared from quarantine.', [
                'file_id' => $this->shootFile->id,
                'scan_status' => $this->shootFile->scan_status,
            ]);

            return;
        }

        try {
            $needsPreviewRegeneration = $imageService->needsPreviewRegeneration($this->shootFile);

            if (
                $this->shootFile->processed_at
                && $this->shootFile->thumbnail_path
                && $this->shootFile->web_path
                && $this->shootFile->grid_path
                && !$needsPreviewRegeneration
            ) {
                Log::info("Image already processed, skipping", [
                    'file_id' => $this->shootFile->id,
                    'filename' => $this->shootFile->filename,
                ]);
                return;
            }

            Log::info("Processing image job started", [
                'file_id' => $this->shootFile->id,
                'filename' => $this->shootFile->filename,
                'regenerating_preview' => $needsPreviewRegeneration,
            ]);

            $tempPath = null;
            $sourcePath = null;

            if ($this->shootFile->path && Storage::disk('local')->exists($this->shootFile->path)) {
                $sourcePath = Storage::disk('local')->path($this->shootFile->path);
            } elseif ($this->shootFile->path && Storage::disk('public')->exists($this->shootFile->path)) {
                $sourcePath = Storage::disk('public')->path($this->shootFile->path);
            } elseif ($this->shootFile->storage_path && Storage::disk('public')->exists($this->shootFile->storage_path)) {
                $sourcePath = Storage::disk('public')->path($this->shootFile->storage_path);
            } elseif (($media->readFromR2Enabled() || $media->r2Only())
                && ($r2Key = $media->normalizeKey($this->shootFile->path ?: $this->shootFile->storage_path))
                && ($tempPath = $media->downloadToTemp($r2Key))) {
                // Source the original from R2 when the local copy is gone (post-prune).
                $sourcePath = $tempPath;
            } elseif ($this->shootFile->dropbox_path || $this->shootFile->storage_path) {
                $tempPath = $dropboxService->downloadToTemp($this->shootFile->dropbox_path ?: $this->shootFile->storage_path);
                $sourcePath = $tempPath;
            }

            $success = $sourcePath
                ? $imageService->processImage($this->shootFile, $sourcePath)
                : false;

            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            if (!$success) {
                Log::error("Image processing failed", [
                    'file_id' => $this->shootFile->id,
                    'filename' => $this->shootFile->filename
                ]);
                
                // Mark as failed but don't fail the job
                $this->shootFile->update([
                    'processing_failed_at' => now(),
                    'processing_error' => 'Failed to process image'
                ]);
            } elseif (config('media.dual_write') || config('media.r2_only')) {
                // Mirror the freshly generated derived assets (thumbnail/web/
                // placeholder) to R2 during the dual-write/R2-only cutover.
                try {
                    SyncShootFileToR2Job::dispatch($this->shootFile->id);
                } catch (\Throwable $e) {
                    Log::warning('R2 sync dispatch failed after image processing', [
                        'file_id' => $this->shootFile->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error("Image processing job failed", [
                'file_id' => $this->shootFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark as failed
            $this->shootFile->update([
                'processing_failed_at' => now(),
                'processing_error' => $e->getMessage()
            ]);

            // Re-throw to trigger job retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Image processing job failed permanently", [
            'file_id' => $this->shootFile->id,
            'filename' => $this->shootFile->filename,
            'error' => $exception->getMessage()
        ]);

        // Mark as permanently failed
        $this->shootFile->update([
            'processing_failed_at' => now(),
            'processing_error' => 'Processing failed after retries: ' . $exception->getMessage()
        ]);
    }
}
