<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Shoots\ShootMediaChunkUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShootMediaChunkUploadController extends Controller
{
    public function initiate(
        Request $request,
        Shoot $shoot,
        ShootMediaChunkUploadService $chunks,
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'upload_type' => ['nullable', 'string', 'in:raw,edited'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string'],
        ]);

        $fields = [];
        foreach (($validated['fields'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $fields[$key] = $value;
            }
        }
        if (! isset($fields['upload_type'])) {
            $fields['upload_type'] = (string) ($validated['upload_type'] ?? 'raw');
        }

        try {
            $session = $chunks->initiate(
                $shoot,
                $user,
                (string) $validated['filename'],
                (int) $validated['size_bytes'],
                (string) ($validated['upload_type'] ?? $fields['upload_type'] ?? 'raw'),
                $fields,
            );
        } catch (HttpException $exception) {
            return response()->json([
                'error_type' => $exception->getStatusCode() === 403 ? 'forbidden' : 'invalid_file',
                'message' => $exception->getMessage(),
                'success_count' => 0,
                'error_count' => 1,
            ], $exception->getStatusCode());
        }

        return response()->json($session, 201);
    }

    public function storeChunk(
        Request $request,
        Shoot $shoot,
        string $session,
        int $index,
        ShootMediaChunkUploadService $chunks,
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $contentLengthHeader = $request->header('Content-Length');
        $contentLength = is_string($contentLengthHeader) && ctype_digit($contentLengthHeader)
            ? (int) $contentLengthHeader
            : null;

        try {
            $result = $chunks->storeChunk(
                $shoot,
                $user,
                $session,
                $index,
                $request->getContent(true),
                $contentLength,
            );
        } catch (HttpException $exception) {
            return response()->json([
                'error_type' => 'invalid_chunk',
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return response()->json($result);
    }

    public function complete(
        Request $request,
        Shoot $shoot,
        string $session,
        ShootMediaChunkUploadService $chunks,
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        try {
            $result = $chunks->complete($shoot, $user, $session);
        } catch (HttpException $exception) {
            return response()->json([
                'error_type' => 'invalid_file',
                'message' => $exception->getMessage(),
                'success_count' => 0,
                'error_count' => 1,
                'uploaded_files' => [],
            ], $exception->getStatusCode());
        }

        return response()->json($result['payload'], $result['status']);
    }
}
