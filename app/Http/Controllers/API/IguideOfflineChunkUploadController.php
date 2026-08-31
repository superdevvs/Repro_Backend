<?php

namespace App\Http\Controllers\API;

use App\Exceptions\IguideOfflineUploadException;
use App\Http\Controllers\Controller;
use App\Models\IguideOfflineUploadSession;
use App\Models\Shoot;
use App\Services\IguideOfflineChunkUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IguideOfflineChunkUploadController extends Controller
{
    public function store(Request $request, Shoot $shoot, IguideOfflineChunkUploadService $uploads): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:268435456'],
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A UUID Idempotency-Key header is required.',
            ]);
        }

        try {
            $result = $uploads->initiate(
                $shoot,
                $request->user(),
                $idempotencyKey,
                (string) $validated['filename'],
                (int) $validated['size_bytes'],
                isset($validated['sha256']) ? (string) $validated['sha256'] : null
            );

            return response()->json(
                $uploads->payload($result['session']),
                $result['created'] ? 201 : 200
            );
        } catch (IguideOfflineUploadException $exception) {
            return $this->uploadError($uploads, $exception);
        }
    }

    public function show(
        Shoot $shoot,
        IguideOfflineUploadSession $upload,
        IguideOfflineChunkUploadService $uploads
    ): JsonResponse {
        $this->ensureBelongsToShoot($shoot, $upload);

        return response()->json($uploads->payload($upload));
    }

    public function storeChunk(
        Request $request,
        Shoot $shoot,
        IguideOfflineUploadSession $upload,
        int $index,
        IguideOfflineChunkUploadService $uploads
    ): JsonResponse {
        $this->ensureBelongsToShoot($shoot, $upload);
        $contentLengthHeader = $request->header('Content-Length');
        $contentLength = is_string($contentLengthHeader) && ctype_digit($contentLengthHeader)
            ? (int) $contentLengthHeader
            : null;

        try {
            $result = $uploads->storeChunk(
                $shoot,
                $upload,
                $index,
                (string) $request->header('Content-Range', ''),
                (string) $request->header('X-Chunk-SHA256', ''),
                $request->getContent(true),
                $contentLength,
                (string) $request->header('Content-Type', '')
            );

            return response()->json(
                $uploads->payload($result['session']),
                $result['created'] ? 201 : 200
            );
        } catch (IguideOfflineUploadException $exception) {
            return $this->uploadError($uploads, $exception);
        }
    }

    public function complete(
        Shoot $shoot,
        IguideOfflineUploadSession $upload,
        IguideOfflineChunkUploadService $uploads
    ): JsonResponse {
        $this->ensureBelongsToShoot($shoot, $upload);

        try {
            $result = $uploads->complete($shoot, $upload);

            return response()->json(
                $uploads->payload($result['session']),
                $result['http_status']
            );
        } catch (IguideOfflineUploadException $exception) {
            return $this->uploadError($uploads, $exception);
        }
    }

    public function destroy(
        Shoot $shoot,
        IguideOfflineUploadSession $upload,
        IguideOfflineChunkUploadService $uploads
    ) {
        $this->ensureBelongsToShoot($shoot, $upload);

        try {
            $uploads->cancel($shoot, $upload);

            return response()->noContent();
        } catch (IguideOfflineUploadException $exception) {
            return $this->uploadError($uploads, $exception);
        }
    }

    private function ensureBelongsToShoot(Shoot $shoot, IguideOfflineUploadSession $upload): void
    {
        if ((int) $upload->shoot_id !== (int) $shoot->getKey()) {
            abort(404);
        }
    }

    private function uploadError(
        IguideOfflineChunkUploadService $uploads,
        IguideOfflineUploadException $exception
    ): JsonResponse {
        $payload = $exception->uploadSession !== null
            ? $uploads->payload($exception->uploadSession)
            : ['upload' => null];
        $payload['message'] = $exception->getMessage();
        $payload['error_type'] = $exception->errorType;

        return response()->json(array_merge($payload, $exception->details), $exception->httpStatus);
    }
}
