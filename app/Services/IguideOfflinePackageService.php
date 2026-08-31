<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Validates and tracks an opaque iGUIDE offline package.
 *
 * Packages are deliberately never extracted or served as a web application.
 * A clean package remains an authenticated ZIP download attached to a ShootFile.
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
     * @return array{original_filename:string,size_bytes:int,sha256:string,entry_count:int,expanded_size_bytes:int,wrapper_directory:?string}
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
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Record a new upload without discarding any provider data already stored in
     * iguide_data. A prior ready package remains addressable while this one scans.
     *
     * @param  array{original_filename:string,size_bytes:int,sha256:string,entry_count:int,expanded_size_bytes:int,wrapper_directory:?string}  $inspection
     * @return array<string,mixed>
     */
    public function beginUpload(Shoot $shoot, array $inspection, User $user): array
    {
        $uploadId = (string) Str::uuid();

        return DB::transaction(function () use ($shoot, $inspection, $user, $uploadId): array {
            $lockedShoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->getKey());
            $iguideData = is_array($lockedShoot->iguide_data) ? $lockedShoot->iguide_data : [];
            $current = $iguideData['manual_offline_package'] ?? null;
            $previousReady = null;

            if (is_array($current)) {
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
        return $this->mutateForFile($file, function (array $lifecycle): array {
            if (! in_array(($lifecycle['status'] ?? null), ['queued', 'scanning'], true)) {
                return $lifecycle;
            }

            $lifecycle['status'] = 'scanning';
            $lifecycle['scanning_at'] ??= now()->toIso8601String();
            $lifecycle['error'] = null;

            return $lifecycle;
        });
    }

    /** @return array<string,mixed>|null */
    public function markReady(ShootFile $file): ?array
    {
        if (! $file->isIguideOfflinePackage() || $file->scan_status !== ShootFile::SCAN_STATUS_CLEAN) {
            return null;
        }

        return $this->mutateForFile($file, function (array $lifecycle) use ($file): array {
            $lifecycle['status'] = 'ready';
            $lifecycle['file_id'] = (int) $file->getKey();
            $lifecycle['ready_at'] ??= now()->toIso8601String();
            $lifecycle['download_url'] = "/api/shoots/{$file->shoot_id}/media/{$file->getKey()}/download";
            $lifecycle['error'] = null;
            unset($lifecycle['failed_at'], $lifecycle['previous_ready']);

            return $lifecycle;
        });
    }

    /** @return array<string,mixed>|null */
    public function markFailed(ShootFile $file, ?string $reason = null): ?array
    {
        return $this->mutateForFile($file, function (array $lifecycle) use ($reason): array {
            if (($lifecycle['status'] ?? null) === 'ready') {
                return $lifecycle;
            }

            $lifecycle['status'] = 'failed';
            $lifecycle['failed_at'] = now()->toIso8601String();
            $lifecycle['error'] = Str::limit(trim((string) ($reason ?: 'The package could not be scanned.')), 500, '');

            return $lifecycle;
        });
    }

    /** @return array<string,mixed>|null */
    public function markUploadFailed(int $shootId, string $uploadId, ?string $reason = null): ?array
    {
        return $this->mutate($shootId, $uploadId, function (array $lifecycle) use ($reason): array {
            if (($lifecycle['status'] ?? null) === 'ready') {
                return $lifecycle;
            }

            $lifecycle['status'] = 'failed';
            $lifecycle['failed_at'] = now()->toIso8601String();
            $lifecycle['error'] = Str::limit(trim((string) ($reason ?: 'The package could not be stored.')), 500, '');

            return $lifecycle;
        });
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

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }
}
