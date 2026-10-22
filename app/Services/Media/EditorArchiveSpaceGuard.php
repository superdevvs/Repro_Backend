<?php

namespace App\Services\Media;

use App\Models\ShootFile;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Capacity checks belong only to regenerable editor RAW downloads. */
class EditorArchiveSpaceGuard
{
    public function __construct(
        private MediaStorage $media,
        private ShootFileAccessService $files,
        private ArchiveFilesystemSpace $filesystem,
    ) {}

    public function assertCanGenerate(Collection $files, string $destination): void
    {
        $this->assertEditorKey($destination);
        $inputBytes = 0;
        $remoteCopies = 0;
        foreach ($files as $file) {
            if (! $file instanceof ShootFile) {
                throw new \InvalidArgumentException('Invalid editor archive source.');
            }
            $local = $this->files->findLocalFilePath($file);
            if ($local !== null) {
                clearstatcache(true, $local);
                $size = is_file($local) ? @filesize($local) : false;
            } else {
                // The generator uses this same path precedence for its R2 download.
                $size = ($this->media->readFromR2Enabled() || $this->media->r2Only())
                    ? $this->media->remoteSize((string) ($file->path ?: $file->storage_path)) : null;
                if (is_int($size) && $size >= 0) {
                    $remoteCopies += $size;
                }
            }
            if (! is_int($size) || $size < 0) {
                throw new \RuntimeException('Unable to measure an editor archive source; no ZIP was started. Retry after the source is available.');
            }
            $inputBytes += $size;
        }
        if ($files->isEmpty()) {
            throw new \RuntimeException('No sources are available for the editor download.');
        }
        // Covers incompressible DEFLATE expansion, ZIP64/name metadata and libzip
        // scratch output. Remote sources are all retained until ZIP close.
        $zipBytes = (int) ceil($inputBytes * 1.05) + $files->count() * 65536 + 1048576;
        $requirements = [[sys_get_temp_dir(), 2 * $zipBytes + $remoteCopies]];
        if (! $this->media->r2Only()) {
            // Budget a complete copy even when publisher may use a hardlink.
            $requirements[] = [$this->destinationDirectory($destination), $zipBytes];
        }
        $this->assertRequirements($requirements);
    }

    public function assertCanPublish(string $destination, string $sourcePath): void
    {
        $this->assertEditorKey($destination);
        if ($this->media->r2Only()) {
            return; // Object-store upload does not allocate a local destination.
        }
        clearstatcache(true, $sourcePath);
        $size = is_file($sourcePath) ? @filesize($sourcePath) : false;
        if (! is_int($size) || $size <= 0) {
            throw new \RuntimeException('Unable to measure the completed editor archive before publication.');
        }
        $this->assertRequirements([[$this->destinationDirectory($destination), $size]]);
    }

    private function destinationDirectory(string $key): string
    {
        $disk = $this->media->localDisk($key);
        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new \RuntimeException('Unable to inspect the local editor archive filesystem.');
        }

        return dirname($disk->path($key));
    }

    private function assertEditorKey(string $key): void
    {
        if (! preg_match('~^editor-downloads/[1-9][0-9]*/[a-f0-9]{64}\.zip$~D', $key)) {
            throw new \InvalidArgumentException('Space checks require a versioned editor archive key.');
        }
    }

    private function assertRequirements(array $requirements): void
    {
        $devices = [];
        foreach ($requirements as [$directory, $bytes]) {
            $snapshot = $this->filesystem->snapshot($directory);
            $device = $snapshot['device'];
            if (! isset($devices[$device])) {
                $devices[$device] = ['bytes' => 0, 'free' => $snapshot['free'], 'path' => $snapshot['path']];
            }
            $devices[$device]['bytes'] += $bytes;
            $devices[$device]['free'] = min($devices[$device]['free'], $snapshot['free']);
        }
        $reserve = max(0, (int) config('media.editor_archive_reserve_bytes', 20 * 1024 ** 3));
        foreach ($devices as $device) {
            $required = $device['bytes'] + $reserve;
            if ($device['free'] < $required) {
                throw new \RuntimeException(sprintf(
                    'Editor ZIP preparation deferred: need %.2f GiB available, have %.2f GiB (%.2f GiB reserve) on %s. Retry after storage is freed.',
                    $required / 1024 ** 3, $device['free'] / 1024 ** 3, $reserve / 1024 ** 3, $device['path']
                ));
            }
        }
    }
}
