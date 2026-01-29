<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupToDropboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 30, 60]; // Retry after 10s, 30s, then 60s
    public $timeout = 300; // 5 minutes timeout

    /**
     * Execute the job.
     */
    public function handle(DropboxService $dropboxService): void
    {
        try {
            Log::info("Starting Dropbox backup job");

            // Get all files that haven't been backed up
            $filesToBackup = ShootFile::whereNull('dropbox_file_id')
                ->whereNotNull('path')
                ->where('media_type', 'image')
                ->limit(100) // Process in batches to avoid timeouts
                ->get();

            $backedUpCount = 0;
            $failedCount = 0;

            foreach ($filesToBackup as $shootFile) {
                try {
                    $this->backupFile($shootFile, $dropboxService);
                    $backedUpCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to backup file to Dropbox", [
                        'file_id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
            }

            Log::info("Dropbox backup job completed", [
                'backed_up' => $backedUpCount,
                'failed' => $failedCount
            ]);

            // If there are more files to backup, dispatch another job
            if ($filesToBackup->count() === 100) {
                self::dispatch()->delay(now()->addMinutes(5));
            }

        } catch (\Exception $e) {
            Log::error("Dropbox backup job failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Backup a single file to Dropbox
     */
    protected function backupFile(ShootFile $shootFile, DropboxService $dropboxService): void
    {
        // Check if file exists locally
        if (!Storage::disk('local')->exists($shootFile->path)) {
            Log::warning("File not found locally, skipping backup", [
                'file_id' => $shootFile->id,
                'path' => $shootFile->path
            ]);
            return;
        }

        // Create Dropbox path
        $dropboxPath = $this->generateDropboxPath($shootFile);

        // Get file contents
        $fileContents = Storage::disk('local')->get($shootFile->path);

        // Upload to Dropbox
        $dropboxFileId = $dropboxService->uploadFile($dropboxPath, $fileContents, $shootFile->mime_type);

        if ($dropboxFileId) {
            // Update shoot file with Dropbox info
            $shootFile->update([
                'dropbox_path' => $dropboxPath,
                'dropbox_file_id' => $dropboxFileId,
            ]);

            Log::info("File backed up to Dropbox", [
                'file_id' => $shootFile->id,
                'filename' => $shootFile->filename,
                'dropbox_path' => $dropboxPath
            ]);
        } else {
            throw new \Exception("Failed to upload file to Dropbox");
        }
    }

    /**
     * Generate Dropbox path for the file
     */
    protected function generateDropboxPath(ShootFile $shootFile): string
    {
        // Organize by shoot and date
        $shoot = $shootFile->shoot;
        $dateFolder = $shoot->scheduled_date ? $shoot->scheduled_date->format('Y-m-d') : 'unknown-date';
        
        // Use original files folder for backup
        return "/shoots/{$dateFolder}/{$shootFile->shoot_id}/originals/{$shootFile->filename}";
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Dropbox backup job failed permanently", [
            'error' => $exception->getMessage()
        ]);
    }
}
