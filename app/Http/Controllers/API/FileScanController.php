<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\AuditLogService;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Admin-facing controls for the virus-scan lifecycle of a {@see ShootFile}.
 *
 * The only public action today is {@see rescan()}: when a {@see ShootFile}
 * has stayed in the recoverable terminal `failed` state — typically because
 * ClamAV was unavailable across every retry of the original
 * {@see ScanShootFileJob} — an admin can re-enqueue the scan from the UI.
 * The file is reset to `quarantined` so it remains withheld from downstream
 * processing/delivery until the new scan records a clean result. Files that
 * are already `clean`, `infected`, or still `quarantined` are *not* eligible
 * for re-scan from this endpoint and are rejected with `409 Conflict` —
 * release-on-clean / withhold-on-infected are terminal verdicts that must
 * not be silently overwritten (Req 15.4 / 15.8).
 */
class FileScanController extends Controller
{
    public function __construct(
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected ShootFileAccessService $fileAccess,
        protected MediaStorage $mediaStorage,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Re-enqueue a virus scan for a {@see ShootFile} whose status is `failed`.
     *
     * Mirrors the existing `/shoots/{shoot}/files/{file}/...` admin actions
     * (e.g. verify, move-to-completed): the shoot id is the authoritative
     * scope, the file id must belong to that shoot, and only admin-tier
     * roles reach the underlying state mutation.
     *
     * Behaviour:
     *  - 200 with the updated `scan_status` (`quarantined`) when a `failed`
     *    file is reset and a fresh {@see ScanShootFileJob} is dispatched.
     *  - 409 with the current `scan_status` when the file is in any other
     *    state (already determined clean/infected, or still scanning):
     *    only `failed` files are eligible for retry from the UI (Req 15.8).
     */
    public function rescan(Request $request, Shoot $shoot, ShootFile $file): JsonResponse
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);

        if ($file->scan_status !== ShootFile::SCAN_STATUS_FAILED) {
            return response()->json([
                'message' => 'Only files whose scan failed can be re-scanned.',
                'scan_status' => $file->scan_status,
            ], 409);
        }

        // Reset to quarantined so the file stays withheld until the fresh
        // scan records a clean verdict (Req 15.4 — never released without
        // a recorded clean result). The retry attempt counter is implicit
        // in the new job, so the file gets the full backoff window again.
        $file->scan_status = ShootFile::SCAN_STATUS_QUARANTINED;
        $file->scan_result = null;
        $file->scanned_at = null;
        $file->save();

        ScanShootFileJob::dispatch($file->id);

        return response()->json([
            'message' => 'Scan re-enqueued.',
            'scan_status' => $file->scan_status,
        ]);
    }

    /**
     * Download the untouched original after an infrastructure-level scan failure.
     *
     * This is deliberately separate from the ordinary media download route. The
     * ordinary route stays fail-closed for every non-clean file, while this narrow
     * recovery route is available only to a superadmin and only for `failed` (not
     * infected or still-quarantined) files. The response is always an attachment,
     * uses an inert MIME type, cannot be cached, and is audited.
     */
    public function downloadFailedOriginal(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        $this->shootAuthorizationSupport->ensureRole(
            ['superadmin'],
            $request->user(),
            'Only a superadmin may download a scan-failed original.'
        );

        if ($file->scan_status !== ShootFile::SCAN_STATUS_FAILED) {
            return response()->json([
                'message' => 'Recovery download is available only when the virus scan failed.',
                'scan_status' => $file->scan_status,
            ], 409);
        }

        $filename = basename((string) ($file->filename ?: $file->stored_filename ?: 'scan-failed-file'));
        $asciiFallback = Str::ascii($filename) ?: ('scan-failed-file.'.pathinfo($filename, PATHINFO_EXTENSION));
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $asciiFallback
            ),
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $response = null;
        $temporaryPath = null;

        $localPath = $this->fileAccess->findLocalFilePath($file);
        if ($localPath && file_exists($localPath)) {
            $response = response()->download($localPath, $filename, $headers);
        } else {
            foreach ([$file->path, $file->storage_path] as $candidate) {
                $key = $this->mediaStorage->normalizeKey($candidate);
                if ($key && $this->mediaStorage->exists($key)) {
                    $response = $this->mediaStorage->streamResponse($key, 'application/octet-stream');
                    foreach ($headers as $name => $value) {
                        $response->headers->set($name, $value);
                    }
                    break;
                }
            }

            if (! $response) {
                $temporaryPath = $this->fileAccess->downloadFromDropbox($file);
                if ($temporaryPath && file_exists($temporaryPath)) {
                    $response = response()
                        ->download($temporaryPath, $filename, $headers)
                        ->deleteFileAfterSend(true);
                }
            }
        }

        if (! $response) {
            return response()->json(['message' => 'Original file is not available.'], 404);
        }

        $this->auditLog->record('shoot_file.scan_failed_original_downloaded', $request->user(), $file, [
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'scan_result' => $file->scan_result,
        ]);

        return $response;
    }

    /** Queue a fresh RAW preview build without weakening the virus-scan gate. */
    public function rebuildPreview(Request $request, Shoot $shoot, ShootFile $file): JsonResponse
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        $this->shootAuthorizationSupport->ensureRole(
            ['superadmin'],
            $request->user(),
            'Only a superadmin may rebuild a RAW preview.'
        );

        if (! $this->shootAuthorizationSupport->isRawCameraFile($file)) {
            return response()->json(['message' => 'Preview rebuild is available only for RAW camera files.'], 422);
        }

        if (! $file->isClearedForProcessing()) {
            return response()->json([
                'message' => 'The file must pass its virus scan before a preview can be rebuilt.',
                'scan_status' => $file->scan_status,
            ], 409);
        }

        // Clearing processed_at bypasses ProcessImageJob's already-processed
        // short circuit. Existing renditions remain in place until replacements
        // are generated, so a failed repair cannot erase a working fallback.
        $file->forceFill([
            'processed_at' => null,
            'processing_failed_at' => null,
            'processing_error' => null,
        ])->save();

        ProcessImageJob::dispatch($file->fresh());

        $this->auditLog->record('shoot_file.raw_preview_rebuild_requested', $request->user(), $file, [
            'shoot_id' => $shoot->id,
            'filename' => $file->filename,
        ]);

        return response()->json([
            'message' => 'RAW preview rebuild queued.',
            'file_id' => $file->id,
        ], 202);
    }
}
