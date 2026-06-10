<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ) {
    }

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
}
