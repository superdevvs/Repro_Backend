<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Chunked shoot-media intake for files that exceed Cloudflare's ~100MB
 * single-request body limit. Chunks stay under that ceiling; complete()
 * reassembles one temp file and reuses UploadShootFilesAction.
 */
class ShootMediaChunkUploadService
{
    public const LOG_CHANNEL = 'uploads';

    /** Stay under Cloudflare's free/pro 100MB upload cap with multipart headroom. */
    public const CHUNK_SIZE_BYTES = 50 * 1024 * 1024;

    public const SESSION_TTL_SECONDS = 86_400;

    public function __construct(
        protected ShootAuthorizationSupport $authorization,
        protected UploadShootFilesAction $uploadShootFilesAction,
    ) {}

    /**
     * @param  array<string, string>  $fields
     * @return array{session_id: string, chunk_size_bytes: int, total_chunks: int, expires_at: string}
     */
    public function initiate(
        Shoot $shoot,
        User $user,
        string $filename,
        int $sizeBytes,
        string $uploadType,
        array $fields,
    ): array {
        $uploadType = $uploadType !== '' ? $uploadType : 'raw';
        $shootServiceId = isset($fields['shoot_service_id']) && $fields['shoot_service_id'] !== ''
            ? (int) $fields['shoot_service_id']
            : null;

        if (! $this->authorization->canUploadShootMedia($shoot, $user, $uploadType, $shootServiceId)) {
            throw new HttpException(403, 'You do not have permission to upload media for this shoot.');
        }

        if ($sizeBytes < 1 || $sizeBytes > UploadLimit::maxBytes()) {
            throw new HttpException(422, 'File exceeds the '.UploadLimit::label().' upload limit.');
        }

        $filename = basename(str_replace(["\0", '\\'], '', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new HttpException(422, 'A valid filename is required.');
        }

        $sessionId = (string) Str::uuid();
        $chunkSize = self::CHUNK_SIZE_BYTES;
        $totalChunks = (int) ceil($sizeBytes / $chunkSize);
        $expiresAt = now()->addSeconds(self::SESSION_TTL_SECONDS);

        $dir = $this->sessionDirectory($sessionId);
        if (! is_dir($dir) && ! mkdir($dir, 02770, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create private chunk session storage.');
        }

        $meta = [
            'id' => $sessionId,
            'shoot_id' => (int) $shoot->id,
            'user_id' => (int) $user->id,
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'chunk_size_bytes' => $chunkSize,
            'total_chunks' => $totalChunks,
            'upload_type' => $uploadType,
            'fields' => $fields,
            'received' => [],
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        $this->writeMeta($sessionId, $meta);

        Log::channel(self::LOG_CHANNEL)->info('Chunked upload session started.', [
            'shoot_id' => $shoot->id,
            'actor_id' => $user->id,
            'session_id' => $sessionId,
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'total_chunks' => $totalChunks,
            'upload_type' => $uploadType,
        ]);

        return [
            'session_id' => $sessionId,
            'chunk_size_bytes' => $chunkSize,
            'total_chunks' => $totalChunks,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @param  resource  $body
     * @return array{received_chunks: int, total_chunks: int}
     */
    public function storeChunk(
        Shoot $shoot,
        User $user,
        string $sessionId,
        int $index,
        $body,
        ?int $contentLength,
    ): array {
        $meta = $this->loadMeta($sessionId);
        $this->assertSessionOwner($meta, $shoot, $user);
        $this->assertNotExpired($meta);

        $totalChunks = (int) $meta['total_chunks'];
        if ($index < 0 || $index >= $totalChunks) {
            throw new HttpException(416, 'The chunk index is outside this upload.');
        }

        $chunkSize = (int) $meta['chunk_size_bytes'];
        $sizeBytes = (int) $meta['size_bytes'];
        $expectedStart = $index * $chunkSize;
        $expectedLength = min($chunkSize, $sizeBytes - $expectedStart);
        if ($expectedLength < 1) {
            throw new HttpException(416, 'The chunk index is outside this upload.');
        }

        if ($contentLength !== null && $contentLength !== $expectedLength) {
            throw new HttpException(422, 'Content-Length does not match this chunk.');
        }

        $path = $this->chunkPath($sessionId, $index);
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not open chunk storage for writing.');
        }

        $written = 0;
        try {
            while (! feof($body)) {
                $buffer = fread($body, 1024 * 1024);
                if ($buffer === false) {
                    throw new HttpException(422, 'The chunk body could not be read.');
                }
                if ($buffer === '') {
                    break;
                }
                $written += strlen($buffer);
                if ($written > $expectedLength) {
                    throw new HttpException(422, 'The chunk is larger than expected.');
                }
                if (fwrite($out, $buffer) === false) {
                    throw new RuntimeException('Could not store the chunk file.');
                }
            }
        } finally {
            fclose($out);
            if (is_resource($body)) {
                fclose($body);
            }
        }

        if ($written !== $expectedLength) {
            @unlink($path);
            throw new HttpException(422, 'The chunk is shorter than expected.');
        }

        $received = array_values(array_unique(array_map('intval', $meta['received'] ?? [])));
        if (! in_array($index, $received, true)) {
            $received[] = $index;
            sort($received);
            $meta['received'] = $received;
            $this->writeMeta($sessionId, $meta);
        }

        return [
            'received_chunks' => count($received),
            'total_chunks' => $totalChunks,
        ];
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function complete(Shoot $shoot, User $user, string $sessionId): array
    {
        $meta = $this->loadMeta($sessionId);
        $this->assertSessionOwner($meta, $shoot, $user);
        $this->assertNotExpired($meta);

        $totalChunks = (int) $meta['total_chunks'];
        $received = array_map('intval', $meta['received'] ?? []);
        $missing = array_values(array_diff(range(0, $totalChunks - 1), $received));
        if ($missing !== []) {
            throw new HttpException(422, 'Not all chunks were received before completion.');
        }

        $assembled = $this->sessionDirectory($sessionId).'/assembled.bin';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not create the assembled upload file.');
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkPath = $this->chunkPath($sessionId, $index);
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    throw new RuntimeException('A stored chunk could not be read.');
                }
                try {
                    stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }
            }
        } finally {
            fclose($out);
        }

        clearstatcache(true, $assembled);
        $assembledSize = filesize($assembled);
        if ($assembledSize !== (int) $meta['size_bytes']) {
            @unlink($assembled);
            throw new HttpException(422, 'Assembled file size did not match the declared upload size.');
        }

        $filename = (string) $meta['filename'];
        $mime = mime_content_type($assembled) ?: 'application/octet-stream';
        $uploaded = new UploadedFile($assembled, $filename, $mime, null, true);

        $fields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
        $fields['upload_type'] = (string) ($meta['upload_type'] ?? ($fields['upload_type'] ?? 'raw'));

        $request = Request::create(
            '/api/shoots/'.$shoot->id.'/upload',
            'POST',
            $fields,
            [],
            ['files' => [$uploaded]]
        );
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(static fn () => $user);

        try {
            $result = $this->uploadShootFilesAction->execute($request, $shoot, $user);
        } finally {
            $this->cleanupSession($sessionId);
        }

        Log::channel(self::LOG_CHANNEL)->info('Chunked upload session completed.', [
            'shoot_id' => $shoot->id,
            'actor_id' => $user->id,
            'session_id' => $sessionId,
            'filename' => $filename,
            'size_bytes' => $meta['size_bytes'],
            'status' => $result['status'] ?? null,
            'success_count' => $result['payload']['success_count'] ?? null,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMeta(string $sessionId): array
    {
        $path = $this->metaPath($sessionId);
        if (! is_file($path)) {
            throw new HttpException(404, 'Upload session not found or expired.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new HttpException(404, 'Upload session not found or expired.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeMeta(string $sessionId, array $meta): void
    {
        $path = $this->metaPath($sessionId);
        $json = json_encode($meta, JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not persist chunk session metadata.');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function assertSessionOwner(array $meta, Shoot $shoot, User $user): void
    {
        if ((int) ($meta['shoot_id'] ?? 0) !== (int) $shoot->id) {
            throw new HttpException(404, 'Upload session not found or expired.');
        }
        if ((int) ($meta['user_id'] ?? 0) !== (int) $user->id) {
            throw new HttpException(403, 'This upload session belongs to another user.');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function assertNotExpired(array $meta): void
    {
        $expiresAt = strtotime((string) ($meta['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            if (isset($meta['id'])) {
                $this->cleanupSession((string) $meta['id']);
            }
            throw new HttpException(410, 'This upload session has expired. Start the upload again.');
        }
    }

    private function sessionDirectory(string $sessionId): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $sessionId)) {
            throw new HttpException(404, 'Upload session not found or expired.');
        }

        return storage_path('app/private/shoot-media-chunks/'.$sessionId);
    }

    private function metaPath(string $sessionId): string
    {
        return $this->sessionDirectory($sessionId).'/meta.json';
    }

    private function chunkPath(string $sessionId, int $index): string
    {
        return $this->sessionDirectory($sessionId).'/chunk-'.$index.'.part';
    }

    private function cleanupSession(string $sessionId): void
    {
        $dir = $this->sessionDirectory($sessionId);
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
