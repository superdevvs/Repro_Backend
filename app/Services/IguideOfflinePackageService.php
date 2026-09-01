<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Validates and tracks an opaque iGUIDE offline package.
 *
 * Packages are deliberately never extracted into public storage. A clean package
 * remains an authenticated ZIP download and may be streamed entry-by-entry only
 * through the short-lived signed offline viewer.
 */
class IguideOfflinePackageService
{
    public const MAX_COMPRESSED_BYTES = 256 * 1024 * 1024;

    public const MAX_EXPANDED_BYTES = 1024 * 1024 * 1024;

    public const MAX_ENTRY_BYTES = 256 * 1024 * 1024;

    public const MAX_FILES = 5000;

    public const MAX_COMPRESSION_RATIO = 200;

    /** @var list<string> */
    private const NESTED_ARCHIVE_EXTENSIONS = [
        '7z', 'bz2', 'cab', 'gz', 'iso', 'rar', 'tar', 'tgz', 'txz', 'xz', 'zip',
    ];

    /** @var list<string> */
    private const DANGEROUS_EXTENSIONS = [
        'asp', 'aspx', 'bat', 'bash', 'cgi', 'cmd', 'com', 'dll', 'exe', 'fish',
        'hta', 'jar', 'jsp', 'msi', 'phar', 'php', 'pht', 'phtml', 'pl', 'ps1',
        'py', 'rb', 'sh', 'war', 'zsh',
    ];

    /** @var list<string> */
    private const DANGEROUS_BASENAMES = ['.htaccess', '.user.ini', 'web.config'];

    /**
     * Perform a bounded structural inspection. Only small signature prefixes
     * are read; no archive member is written to disk or executed.
     *
     * @return array{original_filename:string,size_bytes:int,sha256:string,entry_count:int,expanded_size_bytes:int,wrapper_directory:?string,index_entry_path:string}
     */
    public function inspect(UploadedFile $package): array
    {
        $path = $package->getRealPath();
        $filename = $package->getClientOriginalName();
        $size = $package->getSize();

        if (strtolower($package->getClientOriginalExtension()) !== 'zip') {
            $this->invalid('The iGUIDE offline package must be a .zip file.');
        }

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            $this->invalid('The uploaded ZIP could not be read.');
        }

        if (! is_int($size)) {
            $size = (int) filesize($path);
        }
        if ($size < 1 || $size > self::MAX_COMPRESSED_BYTES) {
            $this->invalid('The ZIP must be no larger than 256 MiB.');
        }

        $magic = file_get_contents($path, false, null, 0, 4);
        if (! is_string($magic) || ! in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            $this->invalid('The uploaded file is not a valid ZIP archive.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            $this->invalid('The ZIP archive is malformed or inconsistent.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
                $this->invalid('The ZIP contains an unsafe number of entries.');
            }

            $fileCount = 0;
            $expandedBytes = 0;
            $compressedMemberBytes = 0;
            $seenPaths = [];
            $filePaths = [];
            $directoryPaths = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! isset($stat['name'])) {
                    $this->invalid('The ZIP contains an unreadable directory entry.');
                }

                $rawName = (string) $stat['name'];
                $isDirectory = str_ends_with($rawName, '/');
                $normalized = $this->validateEntryPath($rawName, $isDirectory);
                $folded = $this->foldPath($normalized);

                if (isset($seenPaths[$folded])) {
                    $this->invalid('The ZIP contains duplicate or case-colliding paths.');
                }
                $seenPaths[$folded] = true;

                $this->rejectSymlink($zip, $index);
                $this->rejectEncryptedEntry($zip, $index, $stat);

                if ($isDirectory) {
                    $this->rememberDirectoryPath($directoryPaths, $normalized);

                    continue;
                }

                $fileCount++;
                if ($fileCount > self::MAX_FILES) {
                    $this->invalid('The ZIP may contain at most 5,000 files.');
                }

                $this->rejectDangerousFilename($normalized);
                $this->rejectNestedArchiveContent($zip, $index);

                $expanded = max(0, (int) ($stat['size'] ?? 0));
                $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
                if ($expanded > self::MAX_ENTRY_BYTES) {
                    $this->invalid('A ZIP entry exceeds the 256 MiB per-file limit.');
                }
                if ($expanded > 0 && ($compressed === 0 || ($expanded / $compressed) > self::MAX_COMPRESSION_RATIO)) {
                    $this->invalid('The ZIP contains an entry with an unsafe compression ratio.');
                }

                $expandedBytes += $expanded;
                $compressedMemberBytes += $compressed;
                if ($expandedBytes > self::MAX_EXPANDED_BYTES) {
                    $this->invalid('The expanded ZIP may not exceed 1 GiB.');
                }

                $filePaths[] = $normalized;
                $filePathsByPart = explode('/', $normalized);
                array_pop($filePathsByPart);
                $prefix = '';
                foreach ($filePathsByPart as $part) {
                    $prefix = $prefix === '' ? $part : $prefix.'/'.$part;
                    $this->rememberDirectoryPath($directoryPaths, $prefix);
                }
            }

            if ($fileCount < 1) {
                $this->invalid('The ZIP must contain at least one file.');
            }
            if ($expandedBytes > 0 && ($compressedMemberBytes === 0 || ($expandedBytes / $compressedMemberBytes) > self::MAX_COMPRESSION_RATIO)) {
                $this->invalid('The ZIP has an unsafe overall compression ratio.');
            }

            foreach ($filePaths as $filePath) {
                if (isset($directoryPaths[$this->foldPath($filePath)])) {
                    $this->invalid('The ZIP contains a file/directory path collision.');
                }
            }

            [$wrapper, $relativePaths] = $this->stripOptionalWrapper($filePaths);
            $indexPaths = array_values(array_filter(
                $relativePaths,
                static fn (string $entry): bool => strtolower(basename($entry)) === 'index.html'
            ));
            if (count($indexPaths) !== 1 || strtolower($indexPaths[0]) !== 'index.html') {
                $this->invalid('The ZIP must contain exactly one root index.html, optionally inside one wrapper folder.');
            }

            $sha256 = hash_file('sha256', $path);
            if (! is_string($sha256) || $sha256 === '') {
                $this->invalid('The uploaded ZIP could not be fingerprinted.');
            }

            return [
                'original_filename' => $filename,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'entry_count' => $fileCount,
                'expanded_size_bytes' => $expandedBytes,
                'wrapper_directory' => $wrapper,
                // Preserve the archive's exact case. ZIP lookups are normally
                // case-sensitive even though validation intentionally accepts
                // Index.html and rejects case-colliding paths.
                'index_entry_path' => $wrapper === null
                    ? $indexPaths[0]
                    : $wrapper.'/'.$indexPaths[0],
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Record a new upload without discarding any provider data already stored in
     * iguide_data. A prior ready package remains addressable while this one scans.
     *
     * @param  array{original_filename:string,size_bytes:int,sha256:string,entry_count:int,expanded_size_bytes:int,wrapper_directory:?string,index_entry_path?:string}  $inspection
     * @return array<string,mixed>
     */
    public function beginUpload(Shoot $shoot, array $inspection, User $user, ?string $requestedUploadId = null): array
    {
        $uploadId = $requestedUploadId ?? (string) Str::uuid();

        return DB::transaction(function () use ($shoot, $inspection, $user, $uploadId, $requestedUploadId): array {
            $lockedShoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->getKey());
            $iguideData = is_array($lockedShoot->iguide_data) ? $lockedShoot->iguide_data : [];
            $current = $iguideData['manual_offline_package'] ?? null;
            $previousReady = null;

            if (is_array($current)) {
                $currentUploadId = $current['upload_id'] ?? $current['id'] ?? null;
                if ($requestedUploadId !== null && $currentUploadId === $requestedUploadId) {
                    return $current;
                }
                if ($requestedUploadId !== null
                    && in_array(($current['status'] ?? null), ['queued', 'scanning'], true)) {
                    $this->invalid('Another iGUIDE package is already being scanned for this shoot.');
                }
                if (($current['status'] ?? null) === 'ready' && ! empty($current['file_id'])) {
                    $previousReady = $current;
                } elseif (is_array($current['previous_ready'] ?? null)) {
                    $previousReady = $current['previous_ready'];
                }
            }

            $lifecycle = [
                'id' => $uploadId,
                'upload_id' => $uploadId,
                'status' => 'queued',
                'original_filename' => $inspection['original_filename'],
                'size_bytes' => $inspection['size_bytes'],
                'sha256' => $inspection['sha256'],
                'entry_count' => $inspection['entry_count'],
                'expanded_size_bytes' => $inspection['expanded_size_bytes'],
                'wrapper_directory' => $inspection['wrapper_directory'],
                'index_entry_path' => $inspection['index_entry_path']
                    ?? (($inspection['wrapper_directory'] ?? null) === null
                        ? 'index.html'
                        : $inspection['wrapper_directory'].'/index.html'),
                'uploaded_by' => $user->getKey(),
                'uploaded_at' => now()->toIso8601String(),
                'queued_at' => now()->toIso8601String(),
                'error' => null,
            ];
            if ($previousReady !== null) {
                $lifecycle['previous_ready'] = $previousReady;
            }

            $iguideData['manual_offline_package'] = $lifecycle;
            $lockedShoot->iguide_data = $iguideData;
            $lockedShoot->save();
            $shoot->setRawAttributes($lockedShoot->getAttributes(), true);

            return $lifecycle;
        });
    }

    /** @return array<string,mixed>|null */
    public function markScanning(ShootFile $file): ?array
    {
        $lifecycle = $this->mutateForFile($file, function (array $lifecycle): array {
            if (! in_array(($lifecycle['status'] ?? null), ['queued', 'scanning'], true)) {
                return $lifecycle;
            }

            $lifecycle['status'] = 'scanning';
            $lifecycle['scanning_at'] ??= now()->toIso8601String();
            $lifecycle['error'] = null;

            return $lifecycle;
        });
        app(IguideOfflineChunkUploadService::class)->syncLifecycle($lifecycle, $file);

        return $lifecycle;
    }

    /** @return array<string,mixed>|null */
    public function markReady(ShootFile $file): ?array
    {
        if (! $file->isIguideOfflinePackage() || $file->scan_status !== ShootFile::SCAN_STATUS_CLEAN) {
            return null;
        }

        $supersededFileId = null;
        $lifecycle = $this->mutateForFile($file, function (array $lifecycle) use ($file, &$supersededFileId): array {
            $previousFileId = data_get($lifecycle, 'previous_ready.file_id');
            $pendingCleanup = $lifecycle['last_superseded_package'] ?? null;
            if (is_numeric($previousFileId) && (int) $previousFileId !== (int) $file->getKey()) {
                $supersededFileId = (int) $previousFileId;
                $lifecycle['last_superseded_package'] = [
                    'file_id' => $supersededFileId,
                    'superseded_at' => now()->toIso8601String(),
                    'cleanup_status' => 'pending',
                ];
            } elseif (is_array($pendingCleanup)
                && in_array(($pendingCleanup['cleanup_status'] ?? null), ['pending', 'local_cleanup_failed', 'row_cleanup_failed'], true)
                && is_numeric($pendingCleanup['file_id'] ?? null)
                && (int) $pendingCleanup['file_id'] !== (int) $file->getKey()) {
                // Retrying finalization also retries an interrupted local cleanup.
                $supersededFileId = (int) $pendingCleanup['file_id'];
            }

            $lifecycle['status'] = 'ready';
            $lifecycle['file_id'] = (int) $file->getKey();
            $lifecycle['ready_at'] ??= now()->toIso8601String();
            $lifecycle['download_url'] = "/api/shoots/{$file->shoot_id}/media/{$file->getKey()}/download";
            $lifecycle['error'] = null;
            unset($lifecycle['failed_at'], $lifecycle['previous_ready']);

            return $lifecycle;
        });
        app(IguideOfflineChunkUploadService::class)->syncLifecycle($lifecycle, $file);

        if ($supersededFileId !== null) {
            $this->cleanupSupersededPackage($file, $supersededFileId);
            $lifecycle = $this->currentLifecycle((int) $file->shoot_id);
        }

        return $lifecycle;
    }

    /** @return array<string,mixed>|null */
    public function markFailed(ShootFile $file, ?string $reason = null): ?array
    {
        $message = Str::limit(trim((string) ($reason ?: 'The package could not be scanned.')), 500, '');
        $failedUploadId = data_get($file->metadata, 'upload_id');
        $lifecycle = $this->mutateForFile($file, function (array $lifecycle) use ($message, $file): array {
            if (($lifecycle['status'] ?? null) === 'ready') {
                return $lifecycle;
            }

            return $this->restorePreviousReadyAfterFailure($lifecycle, $message, (int) $file->getKey());
        });

        $resultUploadId = is_array($lifecycle) ? ($lifecycle['upload_id'] ?? $lifecycle['id'] ?? null) : null;
        $isLateFailureAfterReady = is_string($failedUploadId)
            && $resultUploadId === $failedUploadId
            && ($lifecycle['status'] ?? null) === 'ready';
        if (is_string($failedUploadId) && $failedUploadId !== '' && ! $isLateFailureAfterReady) {
            app(IguideOfflineChunkUploadService::class)->markUploadSessionFailed($failedUploadId, $message, $file);
        }

        return $lifecycle;
    }

    /** @return array<string,mixed>|null */
    public function markUploadFailed(int $shootId, string $uploadId, ?string $reason = null): ?array
    {
        $message = Str::limit(trim((string) ($reason ?: 'The package could not be stored.')), 500, '');
        $lifecycle = $this->mutate($shootId, $uploadId, function (array $lifecycle) use ($message): array {
            if (($lifecycle['status'] ?? null) === 'ready') {
                return $lifecycle;
            }

            return $this->restorePreviousReadyAfterFailure($lifecycle, $message);
        });

        $resultUploadId = is_array($lifecycle) ? ($lifecycle['upload_id'] ?? $lifecycle['id'] ?? null) : null;
        $isLateFailureAfterReady = $resultUploadId === $uploadId && ($lifecycle['status'] ?? null) === 'ready';
        if (! $isLateFailureAfterReady) {
            app(IguideOfflineChunkUploadService::class)->markUploadSessionFailed($uploadId, $message);
        }

        return $lifecycle;
    }

    /** @return array<string,mixed>|null */
    public function currentLifecycle(Shoot|int $shoot): ?array
    {
        $model = $shoot instanceof Shoot ? $shoot->fresh() : Shoot::find($shoot);
        $data = is_array($model?->iguide_data) ? $model->iguide_data : [];
        $lifecycle = $data['manual_offline_package'] ?? null;

        return is_array($lifecycle) ? $lifecycle : null;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback */
    private function mutateForFile(ShootFile $file, callable $callback): ?array
    {
        if (! $file->isIguideOfflinePackage()) {
            return null;
        }

        $uploadId = data_get($file->metadata, 'upload_id');
        if (! is_string($uploadId) || $uploadId === '') {
            return null;
        }

        return $this->mutate((int) $file->shoot_id, $uploadId, $callback);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback */
    private function mutate(int $shootId, string $uploadId, callable $callback): ?array
    {
        return DB::transaction(function () use ($shootId, $uploadId, $callback): ?array {
            $shoot = Shoot::query()->lockForUpdate()->find($shootId);
            if ($shoot === null) {
                return null;
            }

            $iguideData = is_array($shoot->iguide_data) ? $shoot->iguide_data : [];
            $lifecycle = $iguideData['manual_offline_package'] ?? null;
            if (! is_array($lifecycle) || ($lifecycle['upload_id'] ?? $lifecycle['id'] ?? null) !== $uploadId) {
                return null;
            }

            $updated = $callback($lifecycle);
            $iguideData['manual_offline_package'] = $updated;
            $shoot->iguide_data = $iguideData;
            $shoot->save();

            return $updated;
        });
    }

    private function validateEntryPath(string $rawName, bool $isDirectory): string
    {
        if ($rawName === '' || strlen($rawName) > 2048 || preg_match('//u', $rawName) !== 1) {
            $this->invalid('The ZIP contains an invalid path name.');
        }
        if (str_contains($rawName, '\\') || str_starts_with($rawName, '/') || preg_match('/^[A-Za-z]:/', $rawName)) {
            $this->invalid('The ZIP contains an absolute or non-portable path.');
        }
        if (str_contains($rawName, ':') || preg_match('/[\x00-\x1F\x7F]/u', $rawName)) {
            $this->invalid('The ZIP contains an unsafe path name.');
        }

        $normalized = $isDirectory ? rtrim($rawName, '/') : $rawName;
        if ($normalized === '') {
            $this->invalid('The ZIP contains an invalid root entry.');
        }

        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/u', $part)) {
                $this->invalid('The ZIP contains a traversal or ambiguous path.');
            }
        }

        return implode('/', $parts);
    }

    private function rejectSymlink(ZipArchive $zip, int $index): void
    {
        $opsys = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            $unixType = ($attributes >> 16) & 0xF000;
            if ($unixType === 0xA000) {
                $this->invalid('Symbolic links are not allowed in the ZIP.');
            }
        }
    }

    /** @param array<string,mixed> $stat */
    private function rejectEncryptedEntry(ZipArchive $zip, int $index, array $stat): void
    {
        $encryptionMethod = (int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE);
        if ($encryptionMethod !== ZipArchive::EM_NONE) {
            $this->invalid('Encrypted ZIP entries are not allowed.');
        }

        if (method_exists($zip, 'getEncryptionName')) {
            $name = $zip->getEncryptionName($index);
            if (is_string($name) && $name !== '' && strtolower($name) !== 'none') {
                $this->invalid('Encrypted ZIP entries are not allowed.');
            }
        }
    }

    private function rejectDangerousFilename(string $path): void
    {
        $basename = strtolower(basename($path));
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        if (in_array($basename, self::DANGEROUS_BASENAMES, true)) {
            $this->invalid('The ZIP contains a server configuration file.');
        }
        if (in_array($extension, self::NESTED_ARCHIVE_EXTENSIONS, true)) {
            $this->invalid('Nested archives are not allowed in the ZIP.');
        }
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            $this->invalid('The ZIP contains a server-executable or dangerous file type.');
        }
    }

    private function rejectNestedArchiveContent(ZipArchive $zip, int $index): void
    {
        $stream = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
        if (! is_resource($stream)) {
            $this->invalid('The ZIP contains an unreadable file entry.');
        }

        try {
            $prefix = fread($stream, 512);
        } finally {
            fclose($stream);
        }
        if (! is_string($prefix)) {
            $this->invalid('The ZIP contains an unreadable file entry.');
        }

        $archiveMagic = str_starts_with($prefix, "PK\x03\x04")
            || str_starts_with($prefix, "PK\x05\x06")
            || str_starts_with($prefix, "Rar!\x1A\x07")
            || str_starts_with($prefix, "7z\xBC\xAF\x27\x1C")
            || str_starts_with($prefix, "\x1F\x8B")
            || str_starts_with($prefix, 'BZh')
            || str_starts_with($prefix, "\xFD7zXZ\x00")
            || str_starts_with($prefix, 'MSCF')
            || substr($prefix, 257, 5) === 'ustar';

        if ($archiveMagic) {
            $this->invalid('Nested archives are not allowed in the ZIP.');
        }
    }

    /** @param array<string,string> $directories */
    private function rememberDirectoryPath(array &$directories, string $path): void
    {
        $folded = $this->foldPath($path);
        if (isset($directories[$folded]) && $directories[$folded] !== $path) {
            $this->invalid('The ZIP contains case-colliding directory paths.');
        }

        $directories[$folded] = $path;
    }

    private function foldPath(string $path): string
    {
        return Str::lower($path);
    }

    /**
     * @param  list<string>  $paths
     * @return array{0:?string,1:list<string>}
     */
    private function stripOptionalWrapper(array $paths): array
    {
        $firstSegments = array_map(static function (string $path): ?string {
            $separator = strpos($path, '/');

            return $separator === false ? null : substr($path, 0, $separator);
        }, $paths);
        $wrapper = $firstSegments[0] ?? null;

        if ($wrapper === null || in_array(null, $firstSegments, true)) {
            return [null, $paths];
        }
        foreach ($firstSegments as $segment) {
            if (strcasecmp((string) $segment, $wrapper) !== 0) {
                return [null, $paths];
            }
        }

        $prefixLength = strlen($wrapper) + 1;

        return [$wrapper, array_map(static fn (string $path): string => substr($path, $prefixLength), $paths)];
    }

    private function cleanupSupersededPackage(ShootFile $currentFile, int $supersededFileId): void
    {
        $uploadId = data_get($currentFile->metadata, 'upload_id');
        if (! is_string($uploadId) || $uploadId === '' || $supersededFileId === (int) $currentFile->getKey()) {
            return;
        }

        $currentLifecycle = $this->currentLifecycle((int) $currentFile->shoot_id);
        if (($currentLifecycle['status'] ?? null) !== 'ready'
            || (int) ($currentLifecycle['file_id'] ?? 0) !== (int) $currentFile->getKey()
            || $currentFile->fresh()?->scan_status !== ShootFile::SCAN_STATUS_CLEAN) {
            return;
        }

        $cleanup = [
            'file_id' => $supersededFileId,
            'superseded_at' => data_get($currentLifecycle, 'last_superseded_package.superseded_at')
                ?: now()->toIso8601String(),
            'cleanup_status' => 'pending',
            'local_blob_deleted' => false,
            'row_deleted' => false,
            'dropbox_mirror_retained' => false,
        ];

        try {
            $superseded = ShootFile::find($supersededFileId);
            if ($superseded === null) {
                $cleanup['cleanup_status'] = 'already_removed';
                $cleanup['local_blob_deleted'] = true;
                $cleanup['row_deleted'] = true;
            } elseif ((int) $superseded->shoot_id !== (int) $currentFile->shoot_id
                || ! $superseded->isIguideOfflinePackage()) {
                $cleanup['cleanup_status'] = 'invalid_reference_retained';
            } else {
                $supersededMetadata = is_array($superseded->metadata) ? $superseded->metadata : [];
                $supersededMetadata['superseded'] = [
                    'by_file_id' => (int) $currentFile->getKey(),
                    'at' => $cleanup['superseded_at'],
                ];
                $attributes = [
                    'metadata' => $supersededMetadata,
                    'scan_status' => ShootFile::SCAN_STATUS_FAILED,
                    'scan_result' => 'superseded',
                ];
                if (Schema::hasColumn('shoot_files', 'is_hidden')) {
                    $attributes['is_hidden'] = true;
                }
                $superseded->forceFill($attributes)->save();

                $cleanup['local_blob_deleted'] = $this->deleteSupersededLocalBlob($superseded);
                $hasDropboxMirror = filled($superseded->dropbox_path) || filled($superseded->dropbox_file_id);
                $cleanup['dropbox_mirror_retained'] = $hasDropboxMirror;

                if (! $cleanup['local_blob_deleted']) {
                    $cleanup['cleanup_status'] = 'local_cleanup_failed';
                } elseif ($hasDropboxMirror) {
                    // DropboxWorkflowService has no public, scoped, idempotent
                    // delete API. Retain a blocked tombstone row so the external
                    // object remains discoverable for safe future cleanup.
                    $cleanup['cleanup_status'] = 'external_mirror_retained';
                } else {
                    $superseded->delete();
                    $cleanup['row_deleted'] = ! ShootFile::query()->whereKey($supersededFileId)->exists();
                    $cleanup['cleanup_status'] = $cleanup['row_deleted'] ? 'removed' : 'row_cleanup_failed';
                }
            }
        } catch (Throwable $exception) {
            $cleanup['cleanup_status'] = 'local_cleanup_failed';
            Log::warning('Unable to clean up a superseded iGUIDE package.', [
                'shoot_file_id' => $supersededFileId,
                'superseded_by_file_id' => $currentFile->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        $this->mutate((int) $currentFile->shoot_id, $uploadId, function (array $lifecycle) use ($cleanup, $currentFile): array {
            if (($lifecycle['status'] ?? null) === 'ready'
                && (int) ($lifecycle['file_id'] ?? 0) === (int) $currentFile->getKey()) {
                $lifecycle['last_superseded_package'] = $cleanup;
            }

            return $lifecycle;
        });

        $freshCurrent = $currentFile->fresh();
        if ($freshCurrent !== null) {
            $metadata = is_array($freshCurrent->metadata) ? $freshCurrent->metadata : [];
            $metadata['last_superseded_package'] = $cleanup;
            $freshCurrent->forceFill(['metadata' => $metadata])->save();
        }
    }

    private function deleteSupersededLocalBlob(ShootFile $file): bool
    {
        $prefix = "secure/iguide-packages/{$file->shoot_id}/";
        $paths = array_values(array_unique(array_filter([
            $file->path,
            $file->storage_path,
        ], static fn ($path): bool => is_string($path) && trim($path) !== '')));

        foreach ($paths as $path) {
            $normalized = str_replace('\\', '/', ltrim((string) $path, '/\\'));
            if (! str_starts_with($normalized, $prefix) || str_contains($normalized, '../')) {
                Log::warning('Refused to delete an unexpected superseded iGUIDE path.', [
                    'shoot_file_id' => $file->getKey(),
                    'path' => $path,
                ]);

                return false;
            }

            foreach (['local', 'public'] as $diskName) {
                $disk = Storage::disk($diskName);
                if ($disk->exists($normalized) && ! $disk->delete($normalized)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string,mixed> $lifecycle */
    private function restorePreviousReadyAfterFailure(
        array $lifecycle,
        string $message,
        ?int $failedFileId = null
    ): array {
        $failedAt = now()->toIso8601String();
        $failure = array_filter([
            'id' => $lifecycle['upload_id'] ?? $lifecycle['id'] ?? null,
            'upload_id' => $lifecycle['upload_id'] ?? $lifecycle['id'] ?? null,
            'file_id' => $failedFileId,
            'original_filename' => $lifecycle['original_filename'] ?? null,
            'size_bytes' => $lifecycle['size_bytes'] ?? null,
            'uploaded_by' => $lifecycle['uploaded_by'] ?? null,
            'uploaded_at' => $lifecycle['uploaded_at'] ?? null,
            'failed_at' => $failedAt,
            'error' => $message,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $previousReady = $lifecycle['previous_ready'] ?? null;
        if (is_array($previousReady)
            && ($previousReady['status'] ?? null) === 'ready'
            && ! empty($previousReady['file_id'])) {
            $previousReady['status'] = 'ready';
            $previousReady['error'] = null;
            $previousReady['last_replacement_failure'] = $failure;
            unset($previousReady['failed_at'], $previousReady['previous_ready']);

            return $previousReady;
        }

        $lifecycle['status'] = 'failed';
        $lifecycle['failed_at'] = $failedAt;
        $lifecycle['error'] = $message;

        return $lifecycle;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }
}
