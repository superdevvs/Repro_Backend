<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RawPreviewService;
use App\Jobs\GenerateRawPreviewJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RawPreviewController extends Controller
{
    protected RawPreviewService $rawPreviewService;

    public function __construct(RawPreviewService $rawPreviewService)
    {
        $this->rawPreviewService = $rawPreviewService;
    }

    /**
     * Generate preview for a RAW file (synchronous)
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
            'output_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filePath = $request->input('file_path');
        $outputName = $request->input('output_name');

        // Security: Ensure path is within allowed directories
        if (!$this->isPathAllowed($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied to specified path',
            ], 403);
        }

        if (!file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found',
            ], 404);
        }

        if (!$this->rawPreviewService->isRawFile($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Not a supported RAW file format',
                'supported_formats' => $this->rawPreviewService->getSupportedFormats(),
            ], 400);
        }

        $result = $this->rawPreviewService->generatePreview($filePath, $outputName);

        if ($result) {
            return response()->json($result);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to generate preview',
        ], 500);
    }

    /**
     * Queue preview generation (asynchronous)
     */
    public function generateAsync(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
            'output_name' => 'nullable|string|max:255',
            'callback_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filePath = $request->input('file_path');

        if (!$this->isPathAllowed($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied to specified path',
            ], 403);
        }

        if (!$this->rawPreviewService->isRawFile($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Not a supported RAW file format',
            ], 400);
        }

        // Dispatch job
        GenerateRawPreviewJob::dispatch(
            $filePath,
            $request->input('output_name'),
            $request->input('callback_url')
        );

        return response()->json([
            'success' => true,
            'message' => 'Preview generation queued',
            'status' => 'processing',
        ], 202);
    }

    /**
     * Batch generate previews
     */
    public function generateBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_paths' => 'required|array|min:1|max:50',
            'file_paths.*' => 'required|string',
            'async' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $filePaths = $request->input('file_paths');
        $async = $request->input('async', true);

        // Filter to only allowed paths
        $allowedPaths = array_filter($filePaths, fn($path) => $this->isPathAllowed($path));

        if (empty($allowedPaths)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid file paths provided',
            ], 400);
        }

        if ($async) {
            foreach ($allowedPaths as $path) {
                if ($this->rawPreviewService->isRawFile($path)) {
                    GenerateRawPreviewJob::dispatch($path);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch preview generation queued',
                'queued_count' => count($allowedPaths),
            ], 202);
        }

        // Synchronous batch (not recommended for large batches)
        $results = $this->rawPreviewService->generateBatchPreviews($allowedPaths);

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Check if preview exists
     */
    public function check(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filename' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $filename = $request->input('filename');
        $exists = $this->rawPreviewService->previewExists($filename);
        $url = $exists ? $this->rawPreviewService->getPreviewUrl($filename) : null;

        return response()->json([
            'success' => true,
            'exists' => $exists,
            'previewUrl' => $url,
        ]);
    }

    /**
     * Get supported formats
     */
    public function formats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'formats' => $this->rawPreviewService->getSupportedFormats(),
        ]);
    }

    /**
     * Delete a preview
     */
    public function delete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filename' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $deleted = $this->rawPreviewService->deletePreview($request->input('filename'));

        return response()->json([
            'success' => $deleted,
            'message' => $deleted ? 'Preview deleted' : 'Preview not found',
        ]);
    }

    /**
     * Check if path is within allowed directories
     */
    protected function isPathAllowed(string $path): bool
    {
        $realPath = realpath($path);
        if (!$realPath) {
            return false;
        }

        // Define allowed base directories
        $allowedBases = [
            storage_path('app'),
            public_path('uploads'),
            // Add more allowed paths as needed
        ];

        foreach ($allowedBases as $base) {
            $realBase = realpath($base);
            if ($realBase && str_starts_with($realPath, $realBase)) {
                return true;
            }
        }

        return false;
    }
}
