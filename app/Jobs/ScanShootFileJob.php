<?php

namespace App\Jobs;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * Submits a quarantined {@see ShootFile} to the self-hosted ClamAV engine and
 * records the verdict (Req 14.2, 14.4, 15.1, 15.3).
 *
 * The job is constructed with a {@see ShootFile} primary key (rather than the
 * model itself) so it stays small on the queue and survives a model that has
 * not yet been persisted on the same connection the worker uses. Callers
 * therefore dispatch it as `ScanShootFileJob::dispatch($shootFile->id)`.
 *
 * Flow:
 *   1. Resolve the {@see ShootFile}; skip if missing or already determined.
 *   2. Submit it to {@see ClamAvClient::scan()}.
 *   3. Hand the verdict to {@see FileScanService}:
 *        - clean    -> recordResult() + release() (dispatches downstream)
 *        - infected -> flagInfected() + notifyAdminInfected()
 *   4. On {@see ClamAvUnavailable}, re-throw so the queue retries with the
 *      configured backoff; the file stays {@see ShootFile::SCAN_STATUS_QUARANTINED}.
 *   5. If retries are exhausted because clamd stayed down, {@see failed()}
 *      re-queues a fresh job and leaves the file quarantined. Other terminal
 *      errors still flip the file to {@see ShootFile::SCAN_STATUS_FAILED}
 *      (still withheld, but re-scannable via the retry-scan endpoint, Req 15.8).
 *
 * The job is the *only* place in the system that transitions a file to `clean`,
 * which preserves the invariant: a file is never released from Quarantine
 * without an explicit clean verdict (Req 15.4 — enforced jointly with
 * {@see FileScanService::release()}).
 */
class ScanShootFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Total attempts including the first. After exhausting these, {@see failed()}
     * runs and the file is transitioned to `failed`.
     */
    public int $tries = 8;

    /**
     * Backoff in seconds between retries (30s -> 30m). Tuned for ClamAV being
     * temporarily unavailable (clamd restart, transient network blip).
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120, 300, 600, 900, 1800];

    /**
     * Per-attempt timeout. Scanning a large file should never tie up a worker
     * indefinitely.
     */
    public int $timeout = 1800;

    public function __construct(public readonly int $shootFileId)
    {
        // Use the default queue so it lines up with running workers.
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     *
     * Dependencies are auto-resolved out of the container by Laravel.
     *
     * @throws ClamAvUnavailable Re-thrown so the queue retries with backoff.
     */
    public function handle(ClamAvClient $clamAv, FileScanService $fileScan): void
    {
        $file = ShootFile::find($this->shootFileId);
        if ($file === null) {
            // Missing rows can occur after a hard delete; nothing to scan.
            Log::info('ScanShootFileJob: ShootFile not found, skipping.', [
                'shoot_file_id' => $this->shootFileId,
            ]);

            return;
        }

        // Idempotent / re-scannable: only process files that are still
        // quarantined or were marked failed and re-enqueued via the rescan
        // endpoint. Already clean/infected verdicts are terminal and must
        // never be silently overwritten by a stale or replayed job.
        if (! in_array($file->scan_status, [
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_FAILED,
        ], true)) {
            Log::info('ScanShootFileJob: file already determined, skipping.', [
                'shoot_file_id' => $file->id,
                'scan_status' => $file->scan_status,
            ]);

            return;
        }

        // A file marked `failed` and re-enqueued by the rescan endpoint should
        // restart from `quarantined` so a successful verdict is recorded
        // cleanly by FileScanService::recordResult.
        if ($file->scan_status === ShootFile::SCAN_STATUS_FAILED) {
            $file->scan_status = ShootFile::SCAN_STATUS_QUARANTINED;
            $file->save();
        }

        $sourcePath = $this->resolveLocalSourcePath($file);
        if ($sourcePath === null) {
            // No local copy could be resolved — log and leave the file
            // quarantined. This is rare (a Dropbox-only row whose download
            // failed) and is recoverable: a future rescan will run once the
            // file is locally available.
            Log::warning('ScanShootFileJob: no local source path for file, leaving quarantined.', [
                'shoot_file_id' => $file->id,
                'path' => $file->path,
                'storage_path' => $file->storage_path,
                'dropbox_path' => $file->dropbox_path,
            ]);

            return;
        }

        try {
            $result = $file->isIguideOfflinePackage()
                ? $this->scanIguidePackageMembers($sourcePath, $clamAv)
                : $clamAv->scan($sourcePath);
        } catch (ClamAvUnavailable $e) {
            // Req 15.2: keep the file quarantined and let the queue retry with
            // backoff. The terminal `failed` transition only happens once
            // $tries is exhausted, in which case Laravel calls failed() below.
            Log::channel('uploads')->warning('ScanShootFileJob: ClamAV unavailable, retrying.', [
                'shoot_file_id' => $file->id,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanupTempSource($sourcePath);
        }

        $fileScan->recordResult($file, $result);

        if ($result->isClean()) {
            // release() is the single dispatch point for ProcessImageJob now
            // that the file has cleared Quarantine (Req 14.4).
            $fileScan->release($file);

            return;
        }

        // Infected: persist the signature and notify admins; the file is now
        // permanently withheld from downstream processing/delivery (Req 15.1, 15.6).
        $fileScan->flagInfected($file, $result);
        $fileScan->notifyAdminInfected($file);
    }

    /**
     * Final-failure handler: invoked by Laravel after $tries is exhausted.
     *
     * ClamAV unavailability is retried with a fresh job so a clamd blip cannot
     * permanently fail a quarantined file. Other errors still mark the file
     * `failed` via {@see FileScanService::flagFailed()} so they stay withheld
     * but re-scannable (Req 15.8). That transition only fires from
     * `quarantined`/`failed`, so a stale failed() never demotes a file that is
     * already clean or infected.
     */
    public function failed(?Throwable $e = null): void
    {
        try {
            $file = ShootFile::find($this->shootFileId);
            if ($file === null) {
                Log::info('ScanShootFileJob::failed: file no longer exists.', [
                    'shoot_file_id' => $this->shootFileId,
                ]);

                return;
            }

            if ($e instanceof ClamAvUnavailable) {
                if (in_array($file->scan_status, [
                    ShootFile::SCAN_STATUS_QUARANTINED,
                    ShootFile::SCAN_STATUS_FAILED,
                ], true)) {
                    if ($file->scan_status === ShootFile::SCAN_STATUS_FAILED) {
                        $file->scan_status = ShootFile::SCAN_STATUS_QUARANTINED;
                        $file->save();
                    }

                    Log::channel('uploads')->warning('ScanShootFileJob: ClamAV still unavailable after retries; re-queueing.', [
                        'shoot_file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);

                    static::dispatch($this->shootFileId)->delay(now()->addMinutes(15));
                }

                return;
            }

            $reason = $e !== null
                ? 'scan_failed: '.$e->getMessage()
                : 'scan_unavailable';

            app(FileScanService::class)->flagFailed($file, $reason);

            Log::channel('uploads')->warning('ScanShootFileJob::failed: file transitioned to failed.', [
                'shoot_file_id' => $file->id,
                'reason' => $reason,
            ]);
        } catch (Throwable $t) {
            // The failed() hook must never itself throw — that would mask the
            // original failure in the queue worker logs.
            Log::error('ScanShootFileJob::failed handler error.', [
                'shoot_file_id' => $this->shootFileId,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a {@see ShootFile} to an absolute filesystem path that
     * {@see ClamAvClient::scan()} can stream from.
     *
     * Shares the HDD/NVMe/legacy resolution used by image processing. R2-only
     * uploads are staged into the NVMe temporary directory for scanning.
     */
    private function resolveLocalSourcePath(ShootFile $file): ?string
    {
        $media = app(\App\Services\Media\MediaStorage::class);
        foreach ([$file->path, $file->storage_path] as $candidate) {
            if ($candidate && ($path = $media->absolutePath($candidate))) {
                return $path;
            }
        }
        if ($media->readFromR2Enabled() || $media->r2Only()) {
            foreach ([$file->path, $file->storage_path] as $candidate) {
                if ($candidate && ($path = $media->downloadToTemp($candidate))) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Scan every uncompressed archive member without extracting it. This keeps
     * each ClamAV INSTREAM request below the structural validator's 256 MiB
     * per-entry bound and avoids clamd's commonly-smaller whole-stream limit.
     */
    private function scanIguidePackageMembers(string $sourcePath, ClamAvClient $clamAv): ClamAvScanResult
    {
        $zip = new ZipArchive;
        $opened = $zip->open($sourcePath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new ClamAvUnavailable('The quarantined iGUIDE ZIP could not be opened for member scanning.');
        }

        $scanned = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! isset($stat['name']) || str_ends_with((string) $stat['name'], '/')) {
                    continue;
                }

                $stream = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_resource($stream)) {
                    throw new ClamAvUnavailable('An iGUIDE ZIP member could not be opened for malware scanning.');
                }

                try {
                    $result = $clamAv->scan($stream);
                } finally {
                    fclose($stream);
                }

                $scanned++;
                if ($result->isInfected()) {
                    return $result;
                }
            }
        } finally {
            $zip->close();
        }

        if ($scanned === 0) {
            throw new ClamAvUnavailable('The quarantined iGUIDE ZIP contained no scannable files.');
        }

        return ClamAvScanResult::clean("{$scanned} iGUIDE package members scanned clean");
    }

    /**
     * Remove temp files created by {@see resolveLocalSourcePath()} for Dropbox
     * downloads. Real disk paths are left untouched — only files in the system
     * temp directory matching the scan-temp prefix are cleaned up.
     */
    private function cleanupTempSource(string $path): void
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        if (dirname($path) !== $tempDir) {
            return;
        }

        if (! str_starts_with(basename($path), 'r2src_') && ! str_starts_with(basename($path), 'dbx-scan-') && ! str_starts_with(basename($path), 'dbx-')) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
