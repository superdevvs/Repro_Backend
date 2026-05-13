<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Best-effort, per-file local cache of a finalized shoot file.
 *
 * Runs *after* finalize has already flipped the shoot to delivered. A failure
 * here NEVER reverts delivery state — the file remains accessible via its
 * existing path / dropbox_path through the read services, and the user-facing
 * client experience is unaffected.
 *
 * Idempotent: if the file is already on the public disk at its current path
 * (or already at `shoots/{id}/final/...`), the job no-ops.
 */
class CacheShootFinalToLocalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120, 300];
    public int $timeout = 600;

    public function __construct(public int $shootFileId)
    {
        $this->onQueue('media');
    }

    public function handle(DropboxWorkflowService $dropbox): void
    {
        /** @var ShootFile|null $file */
        $file = ShootFile::query()->find($this->shootFileId);
        if (!$file) {
            return;
        }

        $disk = Storage::disk('public');
        $currentPath = (string) $file->path;

        // Already cached locally and present on disk — nothing to do.
        if ($currentPath !== '' && $disk->exists($currentPath)) {
            return;
        }

        if (empty($file->dropbox_path)) {
            // No Dropbox source to fetch from. Without a remote source there's
            // nothing to cache; leave path alone and let the read pipeline
            // fall back to whatever it already does.
            Log::info('CacheShootFinalToLocalJob: no dropbox_path, skipping', [
                'shoot_file_id' => $file->id,
            ]);
            return;
        }

        try {
            // moveToFinal handles streaming download + STAGE_VERIFIED bookkeeping.
            $dropbox->moveToFinal($file, 0);
        } catch (\Throwable $e) {
            Log::warning('CacheShootFinalToLocalJob attempt failed', [
                'shoot_file_id' => $file->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // queue worker handles backoff/retries.
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('CacheShootFinalToLocalJob exhausted retries', [
            'shoot_file_id' => $this->shootFileId,
            'error' => $exception->getMessage(),
        ]);
    }
}
