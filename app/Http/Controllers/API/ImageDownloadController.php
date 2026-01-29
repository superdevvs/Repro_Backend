<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShootFile;
use App\Models\Shoot;
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
    public function downloadOriginal(Request $request, $fileId): StreamedResponse|JsonResponse
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
    public function downloadWeb(Request $request, $fileId): StreamedResponse|JsonResponse
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

            // Check if web version exists
            $webPath = $shootFile->web_path;
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
            $files = ShootFile::whereIn('id', $fileIds)->get();
            $downloadableFiles = [];

            // Check authorization for each file
            foreach ($files as $file) {
                if ($this->canDownloadFile($user, $file->shoot, $file)) {
                    if (Storage::disk('local')->exists($file->path)) {
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

            // Add files to ZIP
            foreach ($downloadableFiles as $file) {
                $filePath = Storage::disk('local')->path($file->path);
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, $file->filename);
                }
            }

            $zip->close();

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
        // Admin can download anything
        if ($user->role === 'admin') {
            return true;
        }

        // Photographer can download their own shoots
        if ($user->role === 'photographer' && $shoot->photographer_id == $user->id) {
            return true;
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
        // Admin can view anything
        if ($user->role === 'admin') {
            return true;
        }

        // Photographer can view their own shoots
        if ($user->role === 'photographer' && $shoot->photographer_id == $user->id) {
            return true;
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
