<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\FinalizeProgressTracker;
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

    /**
     * `$shootId` is only carried so this file's outcome can be counted against
     * the shoot's finalize progress document; it is not used for any lookup.
     */
    public function __construct(public int $shootFileId, public ?int $shootId = null)
    {
        $this->onQueue('media');
    }

    public function handle(DropboxWorkflowService $dropbox, ?FinalizeProgressTracker $progress = null): void
    {
        $progress ??= app(FinalizeProgressTracker::class);

        /** @var ShootFile|null $file */
        $file = ShootFile::query()->find($this->shootFileId);
        if (!$file) {
            $this->countTowardsFinalizeProgress($progress, null);
            return;
        }

        $disk = Storage::disk('public');
        $currentPath = (string) $file->path;

        // Already cached locally and present on disk — nothing to do.
        if ($currentPath !== '' && $disk->exists($currentPath)) {
            $this->countTowardsFinalizeProgress($progress, $file);
            return;
        }

        if (empty($file->dropbox_path)) {
            // No Dropbox source to fetch from. Without a remote source there's
            // nothing to cache; leave path alone and let the read pipeline
            // fall back to whatever it already does.
            Log::info('CacheShootFinalToLocalJob: no dropbox_path, skipping', [
                'shoot_file_id' => $file->id,
            ]);
            $this->countTowardsFinalizeProgress($progress, $file);
            return;
        }

        try {
            // moveToFinal handles streaming download + STAGE_VERIFIED bookkeeping.
            $dropbox->moveToFinal($file, 0);
            $this->countTowardsFinalizeProgress($progress, $file);
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

        // Retries are exhausted, so this file will never report in: count it
        // anyway, otherwise the finalize progress bar hangs on this stage.
        $this->countTowardsFinalizeProgress(app(FinalizeProgressTracker::class), null);
    }

    private function countTowardsFinalizeProgress(FinalizeProgressTracker $progress, ?ShootFile $file): void
    {
        $shootId = $this->shootId ?? ($file ? (int) $file->shoot_id : null);
        if (!$shootId) {
            return;
        }

        $progress->stageAdvanced($shootId, FinalizeProgressTracker::STAGE_LOCAL_CACHE);
    }
}
