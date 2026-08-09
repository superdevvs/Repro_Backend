<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShootFile;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageDownloadController extends Controller
{
    /**
     * Download original image file
     */
    public function downloadOriginal(Request $request, $fileId): StreamedResponse|JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $validator = Validator::make(['file_id' => $fileId], [
            'file_id' => 'required|integer|exists:shoot_files,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid file ID',
                'details' => $validator->errors()
            ], 404);
        }

        try {
            $user = Auth::user();
            $shootFile = ShootFile::findOrFail($fileId);
            $shoot = $shootFile->shoot;

            // Authorization check
            if (!$this->canDownloadFile($user, $shoot, $shootFile)) {
                return response()->json([
                    'error' => 'Unauthorized to download this file'
                ], 403);
            }

            // Infected files are blocked from download (Req 15.7).
            if ($shootFile->isBlockedFromDelivery()) {
                return response()->json([
                    'error' => 'This file was flagged as infected by a virus scan and cannot be downloaded.'
                ], 403);
            }

            // R2-first: serve the original via a short-lived presigned URL once
            // reads are flipped (raw originals are never exposed via the CDN).
            $media = app(\App\Services\Media\MediaStorage::class);
            if ($media->readFromR2Enabled() || $media->r2Only()) {
                $key = $media->normalizeKey($shootFile->path);
                if ($key && $media->existsOnR2($key)) {
                    Log::info('File download (R2 presigned)', [
                        'file_id' => $fileId,
                        'user_id' => $user->id,
                        'shoot_id' => $shoot->id,
                    ]);

                    return redirect($media->temporaryUrl($key));
                }
            }

            // Check if file exists
            if (!Storage::disk('local')->exists($shootFile->path)) {
                Log::warning("File not found for download", [
                    'file_id' => $fileId,
                    'path' => $shootFile->path
                ]);

                // Try to fetch from Dropbox if available
                if ($shootFile->dropbox_file_id && $shootFile->dropbox_path) {
                    return $this->downloadFromDropbox($shootFile);
                }

                return response()->json([
                    'error' => 'File not available'
                ], 404);
            }

            // Get file info
            $fileName = $shootFile->filename;
            $mimeType = $shootFile->mime_type ?? 'application/octet-stream';
            $fileSize = Storage::disk('local')->size($shootFile->path);

            // Log download
            Log::info("File downloaded", [
                'file_id' => $fileId,
                'filename' => $fileName,
                'user_id' => $user->id,
                'user_role' => $user->role,
                'shoot_id' => $shoot->id
            ]);

            // Return file as download
            return Storage::disk('local')->download($shootFile->path, $fileName, [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Cache-Control' => 'private, max-age=86400', // Cache for 1 day
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"'
            ]);

        } catch (\Exception $e) {
            Log::error("Error downloading file", [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to download file',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Download web-sized image (for preview)
     */
    public function downloadWeb(Request $request, $fileId): StreamedResponse|JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $validator = Validator::make(['file_id' => $fileId], [
            'file_id' => 'required|integer|exists:shoot_files,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid file ID',
                'details' => $validator->errors()
            ], 404);
        }

        try {
            $user = Auth::user();
            $shootFile = ShootFile::findOrFail($fileId);
            $shoot = $shootFile->shoot;

            // Authorization check
            if (!$this->canViewFile($user, $shoot, $shootFile)) {
                return response()->json([
                    'error' => 'Unauthorized to view this file'
                ], 403);
            }

            // Infected files are blocked from preview (Req 15.7).
            if ($shootFile->isBlockedFromDelivery()) {
                return response()->json([
                    'error' => 'This file was flagged as infected by a virus scan and cannot be previewed.'
                ], 403);
            }

            // Check if web version exists
            $webPath = $shootFile->web_path;

            // R2-first: web previews are public/delivered assets — redirect to the
            // CDN once reads are flipped.
            $media = app(\App\Services\Media\MediaStorage::class);
            if ($webPath && ($media->readFromR2Enabled() || $media->r2Only())) {
                $key = $media->normalizeKey($webPath);
                if ($key && $media->existsOnR2($key)) {
                    return redirect($media->publicUrl($key));
                }
            }

            if (!$webPath || !Storage::disk('public')->exists($webPath)) {
                return response()->json([
                    'error' => 'Web version not available'
                ], 404);
            }

            // Get file info
            $fileName = pathinfo($shootFile->filename, PATHINFO_FILENAME) . '_web.jpg';
            $mimeType = 'image/jpeg';
            $fileSize = Storage::disk('public')->size($webPath);

            // Return file
            return Storage::disk('public')->download($webPath, $fileName, [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Cache-Control' => 'public, max-age=31536000', // Cache for 1 year
                'Content-Disposition' => 'inline; filename="' . $fileName . '"' // Inline for preview
            ]);

        } catch (\Exception $e) {
            Log::error("Error downloading web image", [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to download image',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Download multiple files as ZIP
     */
    public function downloadMultiple(Request $request): JsonResponse|StreamedResponse
    {
        $validator = Validator::make($request->all(), [
            'file_ids' => 'required|array',
            'file_ids.*' => 'integer|exists:shoot_files,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid file IDs',
                'details' => $validator->errors()
            ], 400);
        }

        try {
            $user = Auth::user();
            $fileIds = $request->input('file_ids');
            // Delivery order so the numbered ZIP entries below come out in the
            // curated sequence rather than primary-key order.
            $files = ShootFile::whereIn('id', $fileIds)->inDeliveryOrder()->get();
            $downloadableFiles = [];
            $media = app(\App\Services\Media\MediaStorage::class);
            $r2Reads = $media->readFromR2Enabled() || $media->r2Only();

            // Check authorization for each file
            foreach ($files as $file) {
                // Infected files are blocked from download (Req 15.7).
                if ($file->isBlockedFromDelivery()) {
                    continue;
                }
                if ($this->canDownloadFile($user, $file->shoot, $file)) {
                    $onR2 = $r2Reads && ($key = $media->normalizeKey($file->path)) && $media->existsOnR2($key);
                    if (Storage::disk('local')->exists($file->path) || $onR2) {
                        $downloadableFiles[] = $file;
                    }
                }
            }

            if (empty($downloadableFiles)) {
                return response()->json([
                    'error' => 'No files available for download'
                ], 404);
            }

            // Create ZIP file
            $zipFileName = 'images_' . date('Y-m-d_H-i-s') . '.zip';
            $zipPath = tempnam(sys_get_temp_dir(), 'download_') . '.zip';

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
                return response()->json([
                    'error' => 'Failed to create ZIP file'
                ], 500);
            }

            // Add files to ZIP, sourcing from local when present and otherwise
            // pulling from R2 into a temp file (cleaned up after the archive is built).
            $tempSources = [];
            // Entry names carry their position because no unzip tool preserves
            // ZIP ordering on extraction. Stored filenames are not modified.
            $formatter = app(\App\Services\Shoots\DeliveryFilenameFormatter::class);
            $total = count($downloadableFiles);
            $position = 1;
            $usedNames = [];

            foreach ($downloadableFiles as $file) {
                $entryName = $formatter->deduplicate(
                    $formatter->formatForFile($file, $position, $total, basename((string) $file->path)),
                    $usedNames
                );

                $filePath = Storage::disk('local')->path($file->path);
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, $entryName);
                    $position++;
                    continue;
                }

                if ($r2Reads) {
                    $key = $media->normalizeKey($file->path);
                    $contents = $key ? $media->get($key) : null;
                    if ($contents !== null) {
                        $tmp = tempnam(sys_get_temp_dir(), 'r2zip_');
                        file_put_contents($tmp, $contents);
                        $tempSources[] = $tmp;
                        $zip->addFile($tmp, $entryName);
                        $position++;
                    }
                }
            }

            $zip->close();

            foreach ($tempSources as $tmp) {
                @unlink($tmp);
            }

            // Log bulk download
            Log::info("Bulk download", [
                'file_count' => count($downloadableFiles),
                'user_id' => $user->id,
                'user_role' => $user->role
            ]);

            // Return ZIP file
            return response()->download($zipPath, $zipFileName, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, max-age=3600', // Cache for 1 hour
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error("Error in bulk download", [
                'error' => $e->getMessage(),
                'file_ids' => $request->input('file_ids')
            ]);

            return response()->json([
                'error' => 'Failed to create download',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if user can download file
     */
    protected function canDownloadFile($user, Shoot $shoot, ShootFile $file): bool
    {
        // Admin/superadmin/editing_manager can download anything
        if (in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($user->role === 'photographer') {
            return app(ShootAuthorizationSupport::class)->canPhotographerAccessFile($shoot, $file, $user);
        }

        // Editor can download completed shoots
        if ($user->role === 'editor' && in_array($shoot->status, ['completed', 'delivered'])) {
            return true;
        }

        // Client can download their own shoots
        if ($user->role === 'client' && $shoot->client_id == $user->id) {
            // Only if shoot is completed or delivered
            return in_array($shoot->status, ['completed', 'delivered']);
        }

        return false;
    }

    /**
     * Check if user can view file
     */
    protected function canViewFile($user, Shoot $shoot, ShootFile $file): bool
    {
        // Admin/superadmin/editing_manager can view anything
        if (in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($user->role === 'photographer') {
            return app(ShootAuthorizationSupport::class)->canPhotographerAccessFile($shoot, $file, $user);
        }

        // Editor can view completed shoots
        if ($user->role === 'editor') {
            return true;
        }

        // Client can view their own shoots
        if ($user->role === 'client' && $shoot->client_id == $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Download file from Dropbox as fallback
     */
    protected function downloadFromDropbox(ShootFile $shootFile): JsonResponse
    {
        try {
            // This would integrate with your Dropbox service
            // For now, return error
            return response()->json([
                'error' => 'File not available locally. Please contact support.'
            ], 404);

        } catch (\Exception $e) {
            Log::error("Error downloading from Dropbox", [
                'file_id' => $shootFile->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to download file from backup'
            ], 500);
        }
    }
}
