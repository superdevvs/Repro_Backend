<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;

class FileUploadController extends Controller
{
    public function __construct(
        protected UploadShootFilesAction $uploadShootFilesAction,
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {}

    /**
     * Upload files from PC to shoot folder
     */
    public function uploadFromPC(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $shootServiceId = $request->filled('shoot_service_id')
            ? (int) $request->input('shoot_service_id')
            : null;
        $uploadType = (string) $request->input('upload_type', 'raw');

        if (! $this->shootAuthorizationSupport->canUploadShootMedia($shoot, $user, $uploadType, $shootServiceId)) {
            return $this->uploadForbiddenResponse();
        }

        // Keep this compatibility route, but make the canonical action its only
        // implementation so it shares provenance, idempotency, compensation,
        // partial-result, and serializer behavior with /shoots/{shoot}/upload.
        $result = $this->uploadShootFilesAction->execute($request, $shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    private function uploadForbiddenResponse()
    {
        return response()->json([
            'error_type' => 'forbidden',
            'message' => 'You do not have permission to upload media for this shoot.',
            'uploaded_files' => [],
            'errors' => [[
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to upload media for this shoot.',
                'retryable' => false,
            ]],
            'success_count' => 0,
            'error_count' => 1,
            'partial_success' => false,
        ], 403);
    }
}
