<?php

namespace App\Services;

use App\Exceptions\IguideOfflineUploadException;
use App\Jobs\AssembleIguideOfflinePackageJob;
use App\Jobs\FinalizeIguideOfflinePackageJob;
use App\Models\IguideOfflineUploadChunk;
use App\Models\IguideOfflineUploadSession;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IguideOfflineChunkUploadService
{
    private const STAGING_ROOT = 'secure/iguide-upload-sessions';

    /** @return array{session:IguideOfflineUploadSession,created:bool} */
    public function initiate(
        Shoot $shoot,
        User $user,
        string $idempotencyKey,
        string $filename,
        int $sizeBytes,
        ?string $expectedSha256
    ): array {
        $this->validateFilename($filename);

        $maxBytes = (int) config('iguide.offline_upload.max_size_bytes', IguideOfflinePackageService::MAX_COMPRESSED_BYTES);
        if ($sizeBytes < 1 || $sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'size_bytes' => 'The ZIP must be no larger than 256 MiB.',
            ]);
        }

        $expectedSha256 = $expectedSha256 !== null ? strtolower(trim($expectedSha256)) : null;
        if ($expectedSha256 === '') {
            $expectedSha256 = null;
        }

        $this->expireStaleUploadsForShoot((int) $shoot->getKey());

        return DB::transaction(function () use (
            $shoot,
            $user,
            $idempotencyKey,
            $filename,
            $sizeBytes,
            $expectedSha256
        ): array {
            $lockedShoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->getKey());
            $existing = IguideOfflineUploadSession::query()
                ->where('shoot_id', $lockedShoot->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $sameRequest = $existing->original_filename === $filename
                    && (int) $existing->size_bytes === $sizeBytes
                    && strtolower((string) ($existing->expected_sha256 ?? '')) === strtolower((string) ($expectedSha256 ?? ''));

                if (! $sameRequest) {
                    throw new IguideOfflineUploadException(
                        'This Idempotency-Key was already used for a different iGUIDE package.',
                        409,
                        'idempotency_conflict',
                        $existing
                    );
                }

                return ['session' => $existing, 'created' => false];
            }

            $active = IguideOfflineUploadSession::query()
                ->where('shoot_id', $lockedShoot->getKey())
                ->whereIn('status', [
                    IguideOfflineUploadSession::STATUS_UPLOADING,
                    IguideOfflineUploadSession::STATUS_ASSEMBLING,
                ])
                ->oldest('created_at')
                ->first();

            if ($active !== null) {
                throw new IguideOfflineUploadException(
                    'Another iGUIDE package upload is already in progress for this shoot.',
                    409,
                    'upload_in_progress',
                    $active
                );
            }

            $iguideData = is_array($lockedShoot->iguide_data) ? $lockedShoot->iguide_data : [];
            $lifecycle = $iguideData['manual_offline_package'] ?? null;
            if (is_array($lifecycle) && in_array(($lifecycle['status'] ?? null), ['queued', 'scanning'], true)) {
                $lifecycleSession = IguideOfflineUploadSession::find(
                    $lifecycle['upload_id'] ?? $lifecycle['id'] ?? null
                );

                throw new IguideOfflineUploadException(
                    'The current iGUIDE package is still being scanned. Wait for it to finish before replacing it.',
                    409,
                    'scan_in_progress',
                    $lifecycleSession
                );
            }

            $chunkSize = (int) config('iguide.offline_upload.chunk_size_bytes', 5 * 1024 * 1024);
            $now = now();
            $session = IguideOfflineUploadSession::create([
                'id' => (string) Str::uuid(),
                'shoot_id' => $lockedShoot->getKey(),
                'user_id' => $user->getKey(),
                'idempotency_key' => $idempotencyKey,
                'original_filename' => $filename,
                'size_bytes' => $sizeBytes,
                'expected_sha256' => $expectedSha256,
                'chunk_size_bytes' => $chunkSize,
                'total_chunks' => (int) ceil($sizeBytes / $chunkSize),
                'received_bytes' => 0,
                'status' => IguideOfflineUploadSession::STATUS_UPLOADING,
                'error' => null,
                'retryable' => false,
                'last_activity_at' => $now,
                'expires_at' => $now->copy()->addHours((int) config('iguide.offline_upload.inactive_ttl_hours', 24)),
            ]);

            return ['session' => $session, 'created' => true];
        });
    }

    /**
     * @param  resource  $input
     * @return array{session:IguideOfflineUploadSession,created:bool}
     */
    public function storeChunk(
        Shoot $shoot,
        IguideOfflineUploadSession $session,
        int $index,
        string $contentRange,
        string $chunkSha256,
        $input,
        ?int $contentLength,
        string $contentType
    ): array {
        $this->ensureBelongsToShoot($shoot, $session);

        if (! is_resource($input)) {
            throw new IguideOfflineUploadException('The chunk body could not be read.', 422, 'invalid_chunk_body', $session);
        }
        if (strtolower(trim(explode(';', $contentType)[0] ?? '')) !== 'application/octet-stream') {
            throw new IguideOfflineUploadException(
                'Chunks must use application/octet-stream.',
                415,
                'invalid_content_type',
                $session
            );
        }

        $chunkSha256 = strtolower(trim($chunkSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $chunkSha256) !== 1) {
            throw new IguideOfflineUploadException('X-Chunk-SHA256 must be a SHA-256 digest.', 422, 'invalid_chunk_hash', $session);
        }

        [$start, $end, $total] = $this->parseContentRange($contentRange, $session);
        if ($index < 0 || $index >= (int) $session->total_chunks) {
            throw new IguideOfflineUploadException('The chunk index is outside this upload.', 416, 'invalid_chunk_index', $session);
        }

        $expectedStart = $index * (int) $session->chunk_size_bytes;
        $expectedLength = min(
            (int) $session->chunk_size_bytes,
            (int) $session->size_bytes - $expectedStart
        );
        $expectedEnd = $expectedStart + $expectedLength - 1;
        if ($total !== (int) $session->size_bytes || $start !== $expectedStart || $end !== $expectedEnd) {
            throw new IguideOfflineUploadException(
                'Content-Range does not match the requested chunk.',
                416,
                'invalid_content_range',
                $session,
                ['expected_content_range' => "bytes {$expectedStart}-{$expectedEnd}/{$session->size_bytes}"]
            );
        }
        if ($contentLength !== null && $contentLength !== $expectedLength) {
            throw new IguideOfflineUploadException('Content-Length does not match this chunk.', 422, 'invalid_chunk_length', $session);
        }

        $disk = Storage::disk('local');
        $base = $this->stagingDirectory($session);
        $incomingDirectory = $base.'/incoming';
        $disk->makeDirectory($incomingDirectory);
        $temporaryPath = $incomingDirectory.'/'.Str::uuid().'.part';
        $temporaryAbsolutePath = $disk->path($temporaryPath);
        $output = fopen($temporaryAbsolutePath, 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Could not create private chunk storage.');
        }

        $bytesWritten = 0;
        $hash = hash_init('sha256');
        try {
            while (! feof($input)) {
                $buffer = fread($input, 1024 * 1024);
                if ($buffer === false) {
                    throw new IguideOfflineUploadException('The chunk body could not be read.', 422, 'invalid_chunk_body', $session);
                }
                if ($buffer === '') {
                    break;
                }

                $bytesWritten += strlen($buffer);
                if ($bytesWritten > $expectedLength) {
                    throw new IguideOfflineUploadException('The chunk is larger than expected.', 422, 'invalid_chunk_length', $session);
                }

                hash_update($hash, $buffer);
                $this->writeAll($output, $buffer);
            }
        } catch (Throwable $exception) {
            fclose($output);
            $disk->delete($temporaryPath);
            throw $exception;
        }
        fclose($output);

        $actualHash = hash_final($hash);
        if ($bytesWritten !== $expectedLength) {
            $disk->delete($temporaryPath);
            throw new IguideOfflineUploadException('The chunk is shorter than expected.', 422, 'invalid_chunk_length', $session);
        }
        if (! hash_equals($chunkSha256, $actualHash)) {
            $disk->delete($temporaryPath);
            throw new IguideOfflineUploadException('The chunk checksum did not match.', 422, 'chunk_hash_mismatch', $session);
        }

        $created = false;
        try {
            $result = DB::transaction(function () use (
                $session,
                $index,
                $expectedStart,
                $expectedLength,
                $actualHash,
                $temporaryPath,
                $base,
                $disk,
                &$created
            ): IguideOfflineUploadSession {
                $locked = IguideOfflineUploadSession::query()->lockForUpdate()->findOrFail($session->getKey());
                $this->assertChunkUploadable($locked);

                $existing = IguideOfflineUploadChunk::query()
                    ->where('upload_session_id', $locked->getKey())
                    ->where('chunk_index', $index)
                    ->first();
                if ($existing !== null) {
                    if ((int) $existing->size_bytes !== $expectedLength || ! hash_equals((string) $existing->sha256, $actualHash)) {
                        throw new IguideOfflineUploadException(
                            'This chunk index was already uploaded with different bytes.',
                            409,
                            'chunk_conflict',
                            $locked
                        );
                    }

                    if (! $disk->exists($existing->storage_path)) {
                        if (! $disk->move($temporaryPath, $existing->storage_path)) {
                            throw new RuntimeException('Could not restore the private chunk file.');
                        }
                    }

                    return $locked;
                }

                $finalDirectory = $base.'/chunks';
                $finalPath = $finalDirectory.'/'.$index.'.part';
                $disk->makeDirectory($finalDirectory);
                if ($disk->exists($finalPath)) {
                    $disk->delete($finalPath);
                }
                if (! $disk->move($temporaryPath, $finalPath)) {
                    throw new RuntimeException('Could not store the private chunk file.');
                }

                IguideOfflineUploadChunk::create([
                    'upload_session_id' => $locked->getKey(),
                    'chunk_index' => $index,
                    'offset_bytes' => $expectedStart,
                    'size_bytes' => $expectedLength,
                    'sha256' => $actualHash,
                    'storage_path' => $finalPath,
                ]);

                $locked->forceFill([
                    'received_bytes' => (int) $locked->received_bytes + $expectedLength,
                    'last_activity_at' => now(),
                    'expires_at' => $this->nextExpiry($locked),
                    'error' => null,
                    'retryable' => false,
                ])->save();
                $created = true;

                return $locked;
            });
        } finally {
            if ($disk->exists($temporaryPath)) {
                $disk->delete($temporaryPath);
            }
        }

        return ['session' => $result->fresh(), 'created' => $created];
    }

    /** @return array{session:IguideOfflineUploadSession,http_status:int} */
    public function complete(Shoot $shoot, IguideOfflineUploadSession $session): array
    {
        $this->ensureBelongsToShoot($shoot, $session);
        $dispatch = false;

        $locked = DB::transaction(function () use ($session, &$dispatch): IguideOfflineUploadSession {
            $upload = IguideOfflineUploadSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ($upload->status === IguideOfflineUploadSession::STATUS_ASSEMBLING) {
                return $upload;
            }
            if (in_array($upload->status, [
                IguideOfflineUploadSession::STATUS_SCANNING,
                IguideOfflineUploadSession::STATUS_READY,
                IguideOfflineUploadSession::STATUS_FAILED,
                IguideOfflineUploadSession::STATUS_CANCELLED,
                IguideOfflineUploadSession::STATUS_EXPIRED,
            ], true)) {
                return $upload;
            }

            $this->assertChunkUploadable($upload);
            $received = IguideOfflineUploadChunk::query()
                ->where('upload_session_id', $upload->getKey())
                ->orderBy('chunk_index')
                ->pluck('chunk_index')
                ->map(static fn ($value): int => (int) $value)
                ->all();
            $missing = array_values(array_diff(range(0, (int) $upload->total_chunks - 1), $received));
            if ($missing !== [] || (int) $upload->received_bytes !== (int) $upload->size_bytes) {
                throw new IguideOfflineUploadException(
                    'The upload is incomplete.',
                    409,
                    'upload_incomplete',
                    $upload,
                    ['missing_chunk_indexes' => $missing]
                );
            }

            $upload->forceFill([
                'status' => IguideOfflineUploadSession::STATUS_ASSEMBLING,
                'processing_started_at' => now(),
                'last_activity_at' => now(),
                'error' => null,
                'retryable' => false,
            ])->save();
            $dispatch = true;

            return $upload;
        });

        if ($dispatch) {
            try {
                AssembleIguideOfflinePackageJob::dispatch((string) $locked->getKey());
            } catch (Throwable $exception) {
                IguideOfflineUploadSession::query()
                    ->whereKey($locked->getKey())
                    ->where('status', IguideOfflineUploadSession::STATUS_ASSEMBLING)
                    ->update([
                        'status' => IguideOfflineUploadSession::STATUS_UPLOADING,
                        'error' => 'The package could not be queued for assembly. Please retry.',
                        'retryable' => true,
                        'processing_started_at' => null,
                        'updated_at' => now(),
                    ]);

                throw new IguideOfflineUploadException(
                    'The package could not be queued for assembly. Please retry.',
                    503,
                    'assembly_queue_unavailable',
                    $locked->fresh()
                );
            }
        }

        $locked = $locked->fresh();
        $status = $locked->status === IguideOfflineUploadSession::STATUS_ASSEMBLING ? 202 : 200;

        return ['session' => $locked, 'http_status' => $status];
    }

    public function cancel(Shoot $shoot, IguideOfflineUploadSession $session): void
    {
        $this->ensureBelongsToShoot($shoot, $session);

        $cleanup = DB::transaction(function () use ($session): bool {
            $upload = IguideOfflineUploadSession::query()->lockForUpdate()->findOrFail($session->getKey());
            if (in_array($upload->status, [
                IguideOfflineUploadSession::STATUS_CANCELLED,
                IguideOfflineUploadSession::STATUS_EXPIRED,
                IguideOfflineUploadSession::STATUS_FAILED,
            ], true)) {
                return true;
            }
            if ($upload->status !== IguideOfflineUploadSession::STATUS_UPLOADING) {
                throw new IguideOfflineUploadException(
                    'This upload can no longer be cancelled.',
                    409,
                    'upload_not_cancellable',
                    $upload
                );
            }

            $upload->forceFill([
                'status' => IguideOfflineUploadSession::STATUS_CANCELLED,
                'error' => null,
                'retryable' => false,
                'completed_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            return true;
        });

        if ($cleanup) {
            $this->deleteStaging($session);
        }
    }

    /** @return array<string,mixed> */
    public function payload(IguideOfflineUploadSession $session): array
    {
        $session = $session->fresh();
        $shoot = $session->shoot()->first();
        $receivedChunks = IguideOfflineUploadChunk::query()
            ->where('upload_session_id', $session->getKey())
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'size_bytes', 'sha256'])
            ->map(static fn (IguideOfflineUploadChunk $chunk): array => [
                'index' => (int) $chunk->chunk_index,
                'size_bytes' => (int) $chunk->size_bytes,
                'sha256' => (string) $chunk->sha256,
            ])
            ->all();
        $received = array_column($receivedChunks, 'index');
        if ($received === []
            && (int) $session->received_bytes === (int) $session->size_bytes
            && (int) $session->total_chunks > 0) {
            // Chunk rows are removed once the opaque ZIP is safely adopted by a
            // ShootFile. Preserve the completed progress contract for later GETs.
            $received = range(0, (int) $session->total_chunks - 1);
        }

        $payload = [
            'upload' => [
                'id' => (string) $session->getKey(),
                'status' => (string) $session->status,
                'filename' => (string) $session->original_filename,
                'size_bytes' => (int) $session->size_bytes,
                'chunk_size_bytes' => (int) $session->chunk_size_bytes,
                'total_chunks' => (int) $session->total_chunks,
                'received_bytes' => (int) $session->received_bytes,
                'received_chunk_indexes' => $received,
                'received_chunks' => $receivedChunks,
                'expires_at' => $session->expires_at?->toIso8601String(),
                'error' => IguideDataVisibilityService::publicOfflineFailure($session->error),
                'retryable' => (bool) $session->retryable,
            ],
        ];

        $iguideData = app(IguideDataVisibilityService::class)->operatorData(
            is_array($shoot?->iguide_data) ? $shoot->iguide_data : [],
        );
        $lifecycle = $iguideData['manual_offline_package'] ?? null;
        $lifecycleId = is_array($lifecycle) ? ($lifecycle['upload_id'] ?? $lifecycle['id'] ?? null) : null;
        if ($lifecycleId === (string) $session->getKey()) {
            $payload['manual_offline_package'] = $lifecycle;
            $payload['iguide_data'] = $iguideData;
        }

        return $payload;
    }

    public function finalizeAssembly(
        string $sessionId,
        UploadValidationService $uploadValidation,
        IguideOfflinePackageService $packages,
        ShootMediaStorageService $mediaStorageService
    ): void {
        $session = IguideOfflineUploadSession::with(['shoot', 'user'])->find($sessionId);
        if ($session === null || $session->status !== IguideOfflineUploadSession::STATUS_ASSEMBLING) {
            return;
        }

        [$assembledPath, $assembledSha256] = $this->assemble($session);
        $absolutePath = Storage::disk('local')->path($assembledPath);
        $upload = new UploadedFile(
            $absolutePath,
            (string) $session->original_filename,
            'application/zip',
            null,
            true
        );

        $uploadValidation->validate($upload, 'package', $session->user?->role);
        $inspection = $packages->inspect($upload);
        if ($session->expected_sha256 !== null && ! hash_equals((string) $session->expected_sha256, $assembledSha256)) {
            throw ValidationException::withMessages([
                'package' => 'The assembled ZIP checksum did not match the expected file.',
            ]);
        }

        $session->refresh();
        if ($session->status !== IguideOfflineUploadSession::STATUS_ASSEMBLING) {
            return;
        }

        $shoot = $session->shoot()->firstOrFail();
        $user = $session->user()->firstOrFail();
        $shootFile = ShootFile::query()
            ->where('shoot_id', $shoot->getKey())
            ->where('media_type', ShootFile::MEDIA_TYPE_IGUIDE)
            ->get()
            ->first(static fn (ShootFile $file): bool => data_get($file->metadata, 'upload_id') === (string) $session->getKey());

        if ($shootFile === null) {
            $lifecycle = $packages->beginUpload($shoot, $inspection, $user, (string) $session->getKey());
            $shootFile = $mediaStorageService->uploadIguideOfflinePackage(
                $shoot,
                $upload,
                $user->getKey(),
                [
                    'kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND,
                    'upload_id' => $lifecycle['upload_id'],
                    'upload_session_id' => (string) $session->getKey(),
                    'original_filename' => $inspection['original_filename'],
                    'size_bytes' => $inspection['size_bytes'],
                    'sha256' => $inspection['sha256'],
                    'entry_count' => $inspection['entry_count'],
                    'expanded_size_bytes' => $inspection['expanded_size_bytes'],
                    'wrapper_directory' => $inspection['wrapper_directory'],
                    'index_entry_path' => $inspection['index_entry_path'],
                ]
            );
        }

        $lifecycle = $packages->markScanning($shootFile)
            ?? $packages->currentLifecycle($shoot)
            ?? [];
        $effectiveStatus = match ($lifecycle['status'] ?? null) {
            'ready' => IguideOfflineUploadSession::STATUS_READY,
            'failed' => IguideOfflineUploadSession::STATUS_FAILED,
            default => IguideOfflineUploadSession::STATUS_SCANNING,
        };

        IguideOfflineUploadSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [
                IguideOfflineUploadSession::STATUS_ASSEMBLING,
                IguideOfflineUploadSession::STATUS_SCANNING,
                IguideOfflineUploadSession::STATUS_READY,
                IguideOfflineUploadSession::STATUS_FAILED,
            ])
            ->update([
                'status' => $effectiveStatus,
                'shoot_file_id' => $shootFile->getKey(),
                'error' => $effectiveStatus === IguideOfflineUploadSession::STATUS_FAILED
                    ? ($lifecycle['error'] ?? 'The package could not be scanned.')
                    : null,
                'retryable' => false,
                'last_activity_at' => now(),
                'completed_at' => in_array($effectiveStatus, [
                    IguideOfflineUploadSession::STATUS_READY,
                    IguideOfflineUploadSession::STATUS_FAILED,
                ], true) ? now() : null,
                'updated_at' => now(),
            ]);

        if ($shootFile->scan_status === ShootFile::SCAN_STATUS_CLEAN) {
            FinalizeIguideOfflinePackageJob::dispatch((int) $shootFile->getKey());
        }

        $this->deleteStaging($session);
    }

    public function markValidationFailed(string $sessionId, string $message): void
    {
        $this->markTerminalFailure($sessionId, $message, false);
        $session = IguideOfflineUploadSession::find($sessionId);
        if ($session !== null) {
            $this->deleteStaging($session);
        }
    }

    public function markAssemblyFailed(string $sessionId, string $message): void
    {
        $this->markTerminalFailure($sessionId, $message, false);
        $session = IguideOfflineUploadSession::find($sessionId);
        if ($session !== null) {
            app(IguideOfflinePackageService::class)->markUploadFailed(
                (int) $session->shoot_id,
                $sessionId,
                $message
            );
            $this->deleteStaging($session);
        }
    }

    public function claimAssembly(string $sessionId, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return IguideOfflineUploadSession::query()
            ->whereKey($sessionId)
            ->where('status', IguideOfflineUploadSession::STATUS_ASSEMBLING)
            ->where(function ($query) {
                $query->whereNull('assembly_token')
                    ->orWhere('assembly_lease_expires_at', '<=', now());
            })
            ->update([
                'assembly_token' => $token,
                'assembly_lease_expires_at' => now()->addMinutes(45),
                'updated_at' => now(),
            ]) === 1;
    }

    public function assemblyClaimRetryAfter(string $sessionId): ?int
    {
        $session = IguideOfflineUploadSession::find($sessionId);
        if ($session === null
            || $session->status !== IguideOfflineUploadSession::STATUS_ASSEMBLING
            || $session->assembly_token === null
            || $session->assembly_lease_expires_at === null
            || $session->assembly_lease_expires_at->isPast()) {
            return null;
        }

        return max(5, min(2700, now()->diffInSeconds($session->assembly_lease_expires_at, false) + 5));
    }

    public function releaseAssembly(string $sessionId, string $token): void
    {
        IguideOfflineUploadSession::query()
            ->whereKey($sessionId)
            ->where('assembly_token', $token)
            ->update([
                'assembly_token' => null,
                'assembly_lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function markUploadSessionFailed(
        string $sessionId,
        string $message,
        ?ShootFile $file = null
    ): void {
        if ($sessionId === '' || ! Schema::hasTable('iguide_offline_upload_sessions')) {
            return;
        }

        $updates = [
            'status' => IguideOfflineUploadSession::STATUS_FAILED,
            'error' => IguideDataVisibilityService::publicOfflineFailure($message),
            'retryable' => false,
            'last_activity_at' => now(),
            'expires_at' => now()->addDays((int) config('iguide.offline_upload.terminal_retention_days', 7)),
            'completed_at' => now(),
            'updated_at' => now(),
        ];
        if ($file !== null) {
            $updates['shoot_file_id'] = $file->getKey();
        }

        IguideOfflineUploadSession::query()
            ->whereKey($sessionId)
            ->whereIn('status', [
                IguideOfflineUploadSession::STATUS_UPLOADING,
                IguideOfflineUploadSession::STATUS_ASSEMBLING,
                IguideOfflineUploadSession::STATUS_SCANNING,
                IguideOfflineUploadSession::STATUS_FAILED,
            ])
            ->update($updates);
    }

    /** @param array<string,mixed>|null $lifecycle */
    public function syncLifecycle(?array $lifecycle, ?ShootFile $file = null): void
    {
        if (! is_array($lifecycle) || ! Schema::hasTable('iguide_offline_upload_sessions')) {
            return;
        }

        $sessionId = $lifecycle['upload_id'] ?? $lifecycle['id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            return;
        }

        $status = match ($lifecycle['status'] ?? null) {
            'queued', 'scanning' => IguideOfflineUploadSession::STATUS_SCANNING,
            'ready' => IguideOfflineUploadSession::STATUS_READY,
            'failed' => IguideOfflineUploadSession::STATUS_FAILED,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $updates = [
            'status' => $status,
            'error' => $status === IguideOfflineUploadSession::STATUS_FAILED ? ($lifecycle['error'] ?? null) : null,
            'retryable' => false,
            'last_activity_at' => now(),
            'expires_at' => now()->addDays((int) config('iguide.offline_upload.terminal_retention_days', 7)),
            'updated_at' => now(),
        ];
        if ($file !== null) {
            $updates['shoot_file_id'] = $file->getKey();
        } elseif (! empty($lifecycle['file_id'])) {
            $updates['shoot_file_id'] = (int) $lifecycle['file_id'];
        }
        if (in_array($status, [IguideOfflineUploadSession::STATUS_READY, IguideOfflineUploadSession::STATUS_FAILED], true)) {
            $updates['completed_at'] = now();
        }

        IguideOfflineUploadSession::query()
            ->whereKey($sessionId)
            ->whereNotIn('status', [
                IguideOfflineUploadSession::STATUS_CANCELLED,
                IguideOfflineUploadSession::STATUS_EXPIRED,
            ])
            ->update($updates);
    }

    /** @return array{expired:int,requeued:int,scan_reconciled:int,scan_failed:int,pruned:int,orphan_pruned:int} */
    public function prune(): array
    {
        $expired = 0;
        IguideOfflineUploadSession::query()
            ->where('status', IguideOfflineUploadSession::STATUS_UPLOADING)
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->each(function (string $id) use (&$expired): void {
                $session = DB::transaction(function () use ($id): ?IguideOfflineUploadSession {
                    $upload = IguideOfflineUploadSession::query()->lockForUpdate()->find($id);
                    if ($upload === null
                        || $upload->status !== IguideOfflineUploadSession::STATUS_UPLOADING
                        || $upload->expires_at?->isFuture()) {
                        return null;
                    }

                    $upload->forceFill([
                        'status' => IguideOfflineUploadSession::STATUS_EXPIRED,
                        'error' => 'The resumable upload expired before it was completed.',
                        'retryable' => false,
                        'completed_at' => now(),
                    ])->save();

                    return $upload;
                });
                if ($session !== null) {
                    $this->deleteStaging($session);
                    $expired++;
                }
            });

        $requeued = 0;
        IguideOfflineUploadSession::query()
            ->where('status', IguideOfflineUploadSession::STATUS_ASSEMBLING)
            ->where('updated_at', '<=', now()->subMinutes((int) config('iguide.offline_upload.stale_assembly_minutes', 45)))
            ->pluck('id')
            ->each(function (string $id) use (&$requeued): void {
                AssembleIguideOfflinePackageJob::dispatch($id);
                $requeued++;
            });

        $scanReconciled = 0;
        $scanFailed = 0;
        IguideOfflineUploadSession::query()
            ->where('status', IguideOfflineUploadSession::STATUS_SCANNING)
            ->where('updated_at', '<=', now()->subMinutes((int) config('iguide.offline_upload.stale_scan_minutes', 90)))
            ->get()
            ->each(function (IguideOfflineUploadSession $session) use (&$scanReconciled, &$scanFailed): void {
                $file = $session->shoot_file_id !== null
                    ? ShootFile::find($session->shoot_file_id)
                    : null;
                $file ??= ShootFile::query()
                    ->where('shoot_id', $session->shoot_id)
                    ->where('media_type', ShootFile::MEDIA_TYPE_IGUIDE)
                    ->get()
                    ->first(static fn (ShootFile $candidate): bool => data_get($candidate->metadata, 'upload_id') === (string) $session->getKey());

                if ($file === null || ! $file->isIguideOfflinePackage()) {
                    app(IguideOfflinePackageService::class)->markUploadFailed(
                        (int) $session->shoot_id,
                        (string) $session->getKey(),
                        'The stored package could not be found for malware scanning.'
                    );
                    $scanFailed++;

                    return;
                }

                if ($file->scan_status === ShootFile::SCAN_STATUS_CLEAN) {
                    // No queue dependency is needed to reconcile an already-clean
                    // file; markReady remains idempotent and fail-closed.
                    app(IguideOfflinePackageService::class)->markReady($file);
                    $scanReconciled++;

                    return;
                }

                if ($file->scan_status === ShootFile::SCAN_STATUS_INFECTED) {
                    app(IguideOfflinePackageService::class)->markFailed(
                        $file,
                        $file->scan_result ?: 'The package failed malware scanning.'
                    );
                    $scanFailed++;

                    return;
                }

                // Never create a second scanner for a stale session. The normal
                // scan job owns its retry chain; redispatching here could race a
                // late infected verdict with a stale clean verdict. A session
                // with no terminal verdict by the recovery cutoff is failed
                // closed, which also restores any previous ready package.
                $file->forceFill([
                    'scan_status' => ShootFile::SCAN_STATUS_FAILED,
                    'scan_result' => 'scan_recovery_timeout',
                    'scanned_at' => now(),
                ])->save();
                app(IguideOfflinePackageService::class)->markFailed(
                    $file,
                    'The package malware scan did not complete within the recovery window.'
                );
                $scanFailed++;
            });

        $pruned = 0;
        IguideOfflineUploadSession::query()
            ->whereIn('status', [
                IguideOfflineUploadSession::STATUS_READY,
                IguideOfflineUploadSession::STATUS_FAILED,
                IguideOfflineUploadSession::STATUS_CANCELLED,
                IguideOfflineUploadSession::STATUS_EXPIRED,
            ])
            ->where('updated_at', '<=', now()->subDays((int) config('iguide.offline_upload.terminal_retention_days', 7)))
            ->get()
            ->each(function (IguideOfflineUploadSession $session) use (&$pruned): void {
                $this->deleteStaging($session);
                $session->delete();
                $pruned++;
            });

        $orphanPruned = $this->pruneOrphanedStagingDirectories();

        return [
            'expired' => $expired,
            'requeued' => $requeued,
            'scan_reconciled' => $scanReconciled,
            'scan_failed' => $scanFailed,
            'pruned' => $pruned,
            'orphan_pruned' => $orphanPruned,
        ];
    }

    private function validateFilename(string $filename): void
    {
        if ($filename === ''
            || mb_strlen($filename) > 255
            || basename($filename) !== $filename
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $filename)
            || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip') {
            throw ValidationException::withMessages([
                'filename' => 'A valid .zip filename is required.',
            ]);
        }
    }

    private function expireStaleUploadsForShoot(int $shootId): void
    {
        $expiredIds = DB::transaction(function () use ($shootId): array {
            $sessions = IguideOfflineUploadSession::query()
                ->where('shoot_id', $shootId)
                ->where('status', IguideOfflineUploadSession::STATUS_UPLOADING)
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->forceFill([
                    'status' => IguideOfflineUploadSession::STATUS_EXPIRED,
                    'error' => 'The resumable upload expired before it was completed.',
                    'retryable' => false,
                    'completed_at' => now(),
                    'last_activity_at' => now(),
                ])->save();
            }

            return $sessions->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        });

        foreach ($expiredIds as $expiredId) {
            $session = IguideOfflineUploadSession::find($expiredId);
            if ($session !== null && $session->status === IguideOfflineUploadSession::STATUS_EXPIRED) {
                $this->deleteStaging($session);
            }
        }
    }

    private function ensureBelongsToShoot(Shoot $shoot, IguideOfflineUploadSession $session): void
    {
        if ((int) $session->shoot_id !== (int) $shoot->getKey()) {
            abort(404);
        }
    }

    private function assertChunkUploadable(IguideOfflineUploadSession $session): void
    {
        if ($session->status !== IguideOfflineUploadSession::STATUS_UPLOADING) {
            throw new IguideOfflineUploadException(
                'This upload is no longer accepting chunks.',
                409,
                'upload_not_writable',
                $session
            );
        }
        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            throw new IguideOfflineUploadException(
                'This resumable upload has expired.',
                410,
                'upload_expired',
                $session
            );
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function parseContentRange(string $contentRange, IguideOfflineUploadSession $session): array
    {
        if (preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', trim($contentRange), $matches) !== 1) {
            throw new IguideOfflineUploadException('A valid Content-Range header is required.', 416, 'invalid_content_range', $session);
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }

    /** @param resource $output */
    private function writeAll($output, string $buffer): void
    {
        $offset = 0;
        $length = strlen($buffer);
        while ($offset < $length) {
            $written = fwrite($output, substr($buffer, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the private chunk file.');
            }
            $offset += $written;
        }
    }

    private function nextExpiry(IguideOfflineUploadSession $session)
    {
        $inactive = now()->addHours((int) config('iguide.offline_upload.inactive_ttl_hours', 24));
        $hard = ($session->created_at ?? now())->copy()->addDays((int) config('iguide.offline_upload.hard_ttl_days', 7));

        return $inactive->lessThan($hard) ? $inactive : $hard;
    }

    private function stagingDirectory(IguideOfflineUploadSession $session): string
    {
        return self::STAGING_ROOT.'/'.(string) $session->getKey();
    }

    private function deleteStaging(IguideOfflineUploadSession $session): void
    {
        Storage::disk('local')->deleteDirectory($this->stagingDirectory($session));
        IguideOfflineUploadChunk::query()->where('upload_session_id', $session->getKey())->delete();
    }

    private function pruneOrphanedStagingDirectories(): int
    {
        $disk = Storage::disk('local');
        $pruned = 0;

        foreach ($disk->directories(self::STAGING_ROOT) as $directory) {
            $normalized = str_replace('\\', '/', trim($directory, '/\\'));
            $sessionId = basename($normalized);

            // Limit recursive deletion to the exact first-level UUID directory
            // shape created by this service. Unexpected content is left alone.
            if ($normalized !== self::STAGING_ROOT.'/'.$sessionId || ! Str::isUuid($sessionId)) {
                continue;
            }
            if (IguideOfflineUploadSession::query()->whereKey($sessionId)->exists()) {
                continue;
            }

            if ($disk->deleteDirectory($normalized)) {
                $pruned++;
            }
        }

        return $pruned;
    }

    /** @return array{0:string,1:string} */
    private function assemble(IguideOfflineUploadSession $session): array
    {
        $chunks = IguideOfflineUploadChunk::query()
            ->where('upload_session_id', $session->getKey())
            ->orderBy('chunk_index')
            ->get();
        if ($chunks->count() !== (int) $session->total_chunks) {
            throw new RuntimeException('The resumable upload lost one or more chunks before assembly.');
        }

        $disk = Storage::disk('local');
        $base = $this->stagingDirectory($session);
        $building = $base.'/assembled-'.Str::uuid().'.part';
        $assembled = $base.'/assembled.zip';
        $disk->makeDirectory($base);
        $output = fopen($disk->path($building), 'wb');
        if (! is_resource($output)) {
            throw new RuntimeException('Could not create the private assembled package.');
        }

        $fullHash = hash_init('sha256');
        $totalBytes = 0;
        try {
            foreach ($chunks as $expectedIndex => $chunk) {
                if ((int) $chunk->chunk_index !== $expectedIndex || ! $disk->exists($chunk->storage_path)) {
                    throw new RuntimeException('The resumable upload chunk set is inconsistent.');
                }

                $input = fopen($disk->path($chunk->storage_path), 'rb');
                if (! is_resource($input)) {
                    throw new RuntimeException('A resumable upload chunk could not be read.');
                }

                $chunkHash = hash_init('sha256');
                $chunkBytes = 0;
                try {
                    while (! feof($input)) {
                        $buffer = fread($input, 1024 * 1024);
                        if ($buffer === false) {
                            throw new RuntimeException('A resumable upload chunk could not be read.');
                        }
                        if ($buffer === '') {
                            break;
                        }
                        $chunkBytes += strlen($buffer);
                        $totalBytes += strlen($buffer);
                        hash_update($chunkHash, $buffer);
                        hash_update($fullHash, $buffer);
                        $this->writeAll($output, $buffer);
                    }
                } finally {
                    fclose($input);
                }

                $actualChunkHash = hash_final($chunkHash);
                if ($chunkBytes !== (int) $chunk->size_bytes || ! hash_equals((string) $chunk->sha256, $actualChunkHash)) {
                    throw new RuntimeException('A resumable upload chunk failed its assembly checksum.');
                }
            }
        } catch (Throwable $exception) {
            fclose($output);
            $disk->delete($building);
            throw $exception;
        }
        fclose($output);

        if ($totalBytes !== (int) $session->size_bytes) {
            $disk->delete($building);
            throw new RuntimeException('The assembled package size did not match the upload session.');
        }
        $sha256 = hash_final($fullHash);

        if ($disk->exists($assembled)) {
            $disk->delete($assembled);
        }
        if (! $disk->move($building, $assembled)) {
            throw new RuntimeException('Could not finalize the private assembled package.');
        }

        return [$assembled, $sha256];
    }

    private function markTerminalFailure(string $sessionId, string $message, bool $retryable): void
    {
        IguideOfflineUploadSession::query()
            ->whereKey($sessionId)
            ->whereIn('status', [
                IguideOfflineUploadSession::STATUS_UPLOADING,
                IguideOfflineUploadSession::STATUS_ASSEMBLING,
                IguideOfflineUploadSession::STATUS_SCANNING,
            ])
            ->update([
                'status' => IguideOfflineUploadSession::STATUS_FAILED,
                'error' => IguideDataVisibilityService::publicOfflineFailure($message),
                'retryable' => $retryable,
                'completed_at' => now(),
                'last_activity_at' => now(),
                'expires_at' => now()->addDays((int) config('iguide.offline_upload.terminal_retention_days', 7)),
                'updated_at' => now(),
            ]);
    }
}
