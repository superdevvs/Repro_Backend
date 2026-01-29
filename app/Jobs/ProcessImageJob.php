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
        
        // Set queue name for image processing
        $this->onQueue('image-processing');
    }

    /**
     * Execute the job.
     */
    public function handle(ImageProcessingService $imageService, DropboxWorkflowService $dropboxService): void
    {
        try {
            if ($this->shootFile->processed_at && $this->shootFile->thumbnail_path) {
                Log::info("Image already processed, skipping", [
                    'file_id' => $this->shootFile->id,
                    'filename' => $this->shootFile->filename,
                ]);
                return;
            }

            Log::info("Processing image job started", [
                'file_id' => $this->shootFile->id,
                'filename' => $this->shootFile->filename
            ]);

            $tempPath = null;
            $sourcePath = null;

            if ($this->shootFile->path && Storage::disk('local')->exists($this->shootFile->path)) {
                $sourcePath = Storage::disk('local')->path($this->shootFile->path);
            } elseif ($this->shootFile->path && Storage::disk('public')->exists($this->shootFile->path)) {
                $sourcePath = Storage::disk('public')->path($this->shootFile->path);
            } elseif ($this->shootFile->storage_path && Storage::disk('public')->exists($this->shootFile->storage_path)) {
                $sourcePath = Storage::disk('public')->path($this->shootFile->storage_path);
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
