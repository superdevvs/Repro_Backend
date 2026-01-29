<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ImageProcessingController extends Controller
{
    /**
     * Process an existing image file
     */
    public function processFile(Request $request, $fileId): JsonResponse
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
            if (!$this->canProcessFile($user, $shoot, $shootFile)) {
                return response()->json([
                    'error' => 'Unauthorized to process this file'
                ], 403);
            }

            // Check if file is an image
            if ($shootFile->media_type !== 'image') {
                return response()->json([
                    'error' => 'File is not an image'
                ], 400);
            }

            // Check if already processed
            if ($shootFile->processed_at) {
                return response()->json([
                    'message' => 'File already processed',
                    'data' => [
                        'thumbnail_path' => $shootFile->thumbnail_path,
                        'web_path' => $shootFile->web_path,
                        'placeholder_path' => $shootFile->placeholder_path,
                        'processed_at' => $shootFile->processed_at,
                    ]
                ]);
            }

            // Dispatch processing job
            ProcessImageJob::dispatch($shootFile);

            Log::info("Image processing job dispatched", [
                'file_id' => $fileId,
                'filename' => $shootFile->filename,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Image processing started',
                'data' => [
                    'file_id' => $fileId,
                    'status' => 'processing'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error starting image processing", [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to start image processing',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Process multiple image files
     */
    public function processMultiple(Request $request): JsonResponse
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
            $processedCount = 0;
            $skippedCount = 0;
            $failedCount = 0;

            foreach ($fileIds as $fileId) {
                try {
                    $shootFile = ShootFile::findOrFail($fileId);
                    $shoot = $shootFile->shoot;

                    // Authorization check
                    if (!$this->canProcessFile($user, $shoot, $shootFile)) {
                        $failedCount++;
                        continue;
                    }

                    // Check if file is an image and not processed
                    if ($shootFile->media_type !== 'image' || $shootFile->processed_at) {
                        $skippedCount++;
                        continue;
                    }

                    // Dispatch processing job
                    ProcessImageJob::dispatch($shootFile);
                    $processedCount++;

                } catch (\Exception $e) {
                    Log::error("Error processing file in batch", [
                        'file_id' => $fileId,
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
            }

            return response()->json([
                'message' => 'Batch processing completed',
                'data' => [
                    'processed' => $processedCount,
                    'skipped' => $skippedCount,
                    'failed' => $failedCount,
                    'total' => count($fileIds)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error in batch image processing", [
                'error' => $e->getMessage(),
                'file_ids' => $request->input('file_ids')
            ]);

            return response()->json([
                'error' => 'Failed to process images',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get processing status of a file
     */
    public function getStatus(Request $request, $fileId): JsonResponse
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

            $status = 'pending';
            if ($shootFile->processed_at) {
                $status = 'completed';
            } elseif ($shootFile->processing_failed_at) {
                $status = 'failed';
            }

            return response()->json([
                'data' => [
                    'file_id' => $fileId,
                    'filename' => $shootFile->filename,
                    'status' => $status,
                    'thumbnail_path' => $shootFile->thumbnail_path,
                    'web_path' => $shootFile->web_path,
                    'placeholder_path' => $shootFile->placeholder_path,
                    'processed_at' => $shootFile->processed_at,
                    'processing_failed_at' => $shootFile->processing_failed_at,
                    'processing_error' => $shootFile->processing_error,
                    'is_raw' => ImageProcessingService::isRawFile($shootFile->filename)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error getting image processing status", [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get status',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Reprocess a file (regenerate all sizes)
     */
    public function reprocess(Request $request, $fileId): JsonResponse
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
            if (!$this->canProcessFile($user, $shoot, $shootFile)) {
                return response()->json([
                    'error' => 'Unauthorized to process this file'
                ], 403);
            }

            // Clear existing processed files
            if ($shootFile->thumbnail_path) {
                Storage::disk('public')->delete($shootFile->thumbnail_path);
            }
            if ($shootFile->web_path) {
                Storage::disk('public')->delete($shootFile->web_path);
            }
            if ($shootFile->placeholder_path) {
                Storage::disk('public')->delete($shootFile->placeholder_path);
            }

            // Reset processing status
            $shootFile->update([
                'thumbnail_path' => null,
                'web_path' => null,
                'placeholder_path' => null,
                'processed_at' => null,
                'processing_failed_at' => null,
                'processing_error' => null,
            ]);

            // Dispatch processing job
            ProcessImageJob::dispatch($shootFile);

            Log::info("Image reprocessing job dispatched", [
                'file_id' => $fileId,
                'filename' => $shootFile->filename,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Image reprocessing started',
                'data' => [
                    'file_id' => $fileId,
                    'status' => 'processing'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error starting image reprocessing", [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to start image reprocessing',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if user can process file
     */
    protected function canProcessFile($user, $shoot, ShootFile $file): bool
    {
        // Admin can process anything
        if ($user->role === 'admin') {
            return true;
        }

        // Photographer can process their own shoots
        if ($user->role === 'photographer' && $shoot->photographer_id == $user->id) {
            return true;
        }

        // Editor can process completed shoots
        if ($user->role === 'editor' && in_array($shoot->status, ['completed', 'delivered'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can view file
     */
    protected function canViewFile($user, $shoot, ShootFile $file): bool
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
}
