<?php

namespace App\Services\Scanning;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records virus-scan verdicts on a {@see ShootFile} and performs the resulting
 * quarantine state transitions (Req 14.4, 15.1, 15.3, 15.6).
 *
 * This service is the single place that translates a scan verdict into a
 * persisted {@see ShootFile::$scan_status} and decides whether a file may leave
 * Quarantine for downstream processing. The actual scanning, retry/backoff, and
 * the terminal `failed` state are owned by the Scan_Job (ScanShootFileJob); this
 * service only records results and transitions state / notifies admins.
 *
 * Invariant: a file is released for downstream processing *only* once it has a
 * recorded clean result (Req 15.4). {@see release()} refuses to release anything
 * whose scan_status is not {@see ShootFile::SCAN_STATUS_CLEAN}.
 */
class FileScanService
{
    /**
     * Persist a scan verdict on the file: record the raw result + scan time and
     * set scan_status to clean or infected accordingly (Req 15.3).
     *
     * Accepts either a {@see ClamAvScanResult} or a plain verdict string
     * (`clean` / `infected`).
     *
     * @param ClamAvScanResult|string $verdict
     */
    public function recordResult(ShootFile $file, $verdict): ShootFile
    {
        [$isClean, $scanResult] = $this->normalizeVerdict($verdict);

        $file->scan_status = $isClean
            ? ShootFile::SCAN_STATUS_CLEAN
            : ShootFile::SCAN_STATUS_INFECTED;
        $file->scan_result = $scanResult;
        $file->scanned_at = now();
        $file->save();

        return $file;
    }

    /**
     * Release a file from Quarantine for downstream processing — but only when it
     * has been determined clean (Req 14.4). A file that is quarantined, infected,
     * or failed is never released; the method is a no-op and returns false so the
     * withholding invariant cannot be violated.
     */
    public function release(ShootFile $file): bool
    {
        if ($file->scan_status !== ShootFile::SCAN_STATUS_CLEAN) {
            Log::warning('FileScanService::release refused — file is not clean.', [
                'file_id' => $file->id,
                'scan_status' => $file->scan_status,
            ]);

            return false;
        }

        // Single dispatch point for downstream per-file processing now that the
        // file has cleared Quarantine.
        ProcessImageJob::dispatch($file);

        return true;
    }

    /**
     * Flag a file as infected: persist the infected status and the scan result,
     * withholding it from all downstream processing and delivery (Req 15.1, 15.3).
     *
     * @param ClamAvScanResult|string|null $verdict Optional verdict carrying the
     *        signature/reason to record. When omitted, an existing scan_result is
     *        preserved (or a generic reason is recorded).
     */
    public function flagInfected(ShootFile $file, $verdict = null): ShootFile
    {
        $scanResult = $file->scan_result;

        if ($verdict !== null) {
            [, $scanResult] = $this->normalizeVerdict($verdict);
        }

        $file->scan_status = ShootFile::SCAN_STATUS_INFECTED;
        $file->scan_result = $scanResult ?: 'infected';
        $file->scanned_at = $file->scanned_at ?: now();
        $file->save();

        return $file;
    }

    /**
     * Mark a file as `failed` after the Scan_Job exhausts its retries (Req 15.2).
     *
     * `failed` is a recoverable terminal state: the file was never determined
     * clean *or* infected (typically because ClamAV stayed unavailable across all
     * retries), so it remains withheld from downstream processing but is eligible
     * for a manual re-scan via the rescan endpoint (Req 15.8). The transition
     * only fires from `quarantined`/`failed` so an already-clean or already-
     * infected file is never demoted by a stale failed() handler.
     */
    public function flagFailed(ShootFile $file, ?string $reason = null): ShootFile
    {
        if (! in_array($file->scan_status, [
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_FAILED,
        ], true)) {
            Log::info('FileScanService::flagFailed skipped — file is no longer quarantined.', [
                'file_id' => $file->id,
                'scan_status' => $file->scan_status,
            ]);

            return $file;
        }

        $file->scan_status = ShootFile::SCAN_STATUS_FAILED;
        $file->scan_result = $reason !== null && $reason !== ''
            ? $reason
            : 'scan_unavailable';
        $file->scanned_at = now();
        $file->save();

        return $file;
    }

    /**
     * Notify admins that an infected file was found (Req 15.6).
     *
     * Reuses the established in-app admin notification convention (see
     * ShootEditorDownloadService::notifyAdminsOfEditorDownload): admins are looked
     * up by role and an in-app Notification record is created when that
     * infrastructure is present. The action is always logged so the event is
     * captured even where the optional notifications table is unavailable.
     */
    public function notifyAdminInfected(ShootFile $file): void
    {
        Log::warning('Infected upload detected and withheld from delivery.', [
            'file_id' => $file->id,
            'shoot_id' => $file->shoot_id,
            'filename' => $file->filename,
            'scan_result' => $file->scan_result,
        ]);

        try {
            if (! class_exists(\App\Models\Notification::class) || ! Schema::hasTable('notifications')) {
                return;
            }

            $admins = User::query()
                ->whereIn('role', ['admin', 'superadmin'])
                ->get();

            foreach ($admins as $admin) {
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'infected_file',
                    'title' => 'Infected File Detected',
                    'message' => sprintf(
                        'An infected file (%s) was detected on shoot #%s and has been withheld from delivery.',
                        $file->filename ?? "file #{$file->id}",
                        $file->shoot_id ?? '—'
                    ),
                    'data' => [
                        'shoot_file_id' => $file->id,
                        'shoot_id' => $file->shoot_id,
                        'filename' => $file->filename,
                        'scan_result' => $file->scan_result,
                    ],
                    'read' => false,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins of infected file: ' . $e->getMessage(), [
                'file_id' => $file->id,
            ]);
        }
    }

    /**
     * Reduce a verdict to a [isClean, scanResult] pair.
     *
     * @param ClamAvScanResult|string $verdict
     * @return array{0: bool, 1: string}
     */
    private function normalizeVerdict($verdict): array
    {
        if ($verdict instanceof ClamAvScanResult) {
            $result = $verdict->isClean()
                ? $verdict->raw()
                : ($verdict->signature() ?? $verdict->raw());

            return [$verdict->isClean(), $result];
        }

        $normalized = strtolower(trim((string) $verdict));
        $isClean = $normalized === ShootFile::SCAN_STATUS_CLEAN;

        return [$isClean, $normalized !== '' ? (string) $verdict : ($isClean ? 'clean' : 'infected')];
    }
}
