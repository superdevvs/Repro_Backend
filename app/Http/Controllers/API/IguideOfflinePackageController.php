<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\IguideOfflinePackageService;
use App\Services\UploadValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class IguideOfflinePackageController extends Controller
{
    public function store(
        Request $request,
        Shoot $shoot,
        IguideOfflinePackageService $packages,
        UploadValidationService $uploadValidation,
        DropboxWorkflowService $dropbox
    ): JsonResponse {
        $request->validate([
            'package' => ['required', 'file', 'max:262144'],
        ], [
            'package.max' => 'The ZIP must be no larger than 256 MiB.',
        ]);

        $package = $request->file('package');
        if (! $package instanceof UploadedFile) {
            return response()->json([
                'message' => 'A valid iGUIDE offline ZIP is required.',
                'errors' => ['package' => ['A valid iGUIDE offline ZIP is required.']],
            ], 422);
        }

        $uploadValidation->validate($package, 'package', $request->user()?->role);
        $inspection = $packages->inspect($package);
        $lifecycle = $packages->beginUpload($shoot, $inspection, $request->user());

        try {
            $shootFile = $dropbox->uploadIguideOfflinePackage(
                $shoot,
                $package,
                $request->user()->getKey(),
                [
                    'kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND,
                    'upload_id' => $lifecycle['upload_id'],
                    'original_filename' => $inspection['original_filename'],
                    'size_bytes' => $inspection['size_bytes'],
                    'sha256' => $inspection['sha256'],
                    'entry_count' => $inspection['entry_count'],
                    'expanded_size_bytes' => $inspection['expanded_size_bytes'],
                    'wrapper_directory' => $inspection['wrapper_directory'],
                ]
            );

            $lifecycle = $packages->markScanning($shootFile)
                ?? $packages->currentLifecycle($shoot)
                ?? $lifecycle;

            return response()->json([
                'message' => ($lifecycle['status'] ?? null) === 'ready'
                    ? 'iGUIDE offline package is ready.'
                    : 'iGUIDE offline package accepted and queued for malware scanning.',
                'manual_offline_package' => $lifecycle,
                'iguide_data' => $shoot->fresh()?->iguide_data,
            ], ($lifecycle['status'] ?? null) === 'ready' ? 201 : 202);
        } catch (Throwable $exception) {
            Log::error('Unable to store iGUIDE offline package.', [
                'shoot_id' => $shoot->id,
                'upload_id' => $lifecycle['upload_id'],
                'error' => $exception->getMessage(),
            ]);

            $packages->markUploadFailed(
                (int) $shoot->id,
                (string) $lifecycle['upload_id'],
                'The package could not be stored. Please try again.'
            );

            return response()->json([
                'message' => 'The iGUIDE offline package could not be stored.',
                'manual_offline_package' => $packages->currentLifecycle($shoot),
            ], 500);
        }
    }
}
