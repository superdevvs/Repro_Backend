<?php

namespace App\Services\Media;

use RuntimeException;

/** Verify the configured drive before constructing an adapter that can create its root. */
class OriginalsStorageGuard
{
    public function assertAvailable(bool $forWrite = true): void
    {
        $disk = (string) config('media.originals_disk', 'media_originals');
        $root = rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
        $mount = rtrim((string) config('media.originals_mount'), '/');
        $uuid = (string) config('media.originals_uuid');

        if ($root === '' || $mount === '' || $mount === '/' || ! str_starts_with($root, $mount.'/')
            || preg_match('/^[a-zA-Z0-9-]+$/D', $uuid) !== 1) {
            throw new RuntimeException('Originals storage requires a dedicated mount, root beneath it, and filesystem UUID.');
        }

        // Never accept a root redirected through a symlink to the OS filesystem.
        if ($this->resolvePath($mount) !== $mount || $this->resolvePath($root) !== $root) {
            throw new RuntimeException('Originals storage directories are missing or resolve outside the configured drive.');
        }
        $mountDevice = $this->filesystemDevice($mount);
        if ($mountDevice === null || $this->filesystemDevice($root) !== $mountDevice) {
            throw new RuntimeException('Originals storage root is on a different filesystem from the configured mount.');
        }

        $device = $this->resolvePath('/dev/disk/by-uuid/'.$uuid);
        $mountInfo = $this->readMountInfo();
        $mounted = false;

        foreach (explode("\n", $mountInfo) as $line) {
            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $fields = explode(' ', $parts[0]);
            $filesystem = explode(' ', $parts[1]);
            if (count($fields) < 6 || count($filesystem) < 3) {
                continue;
            }
            $mountedPath = $this->unescapeMountPath($fields[4]);
            if ($mountedPath !== $mount) {
                continue;
            }
            $source = $this->unescapeMountPath($filesystem[1]);
            $writable = ! in_array('ro', explode(',', $fields[5]), true)
                && ! in_array('ro', explode(',', $filesystem[2]), true);
            $mounted = $device !== false && $this->resolvePath($source) === $device
                && (! $forWrite || $writable);
        }

        if (! $mounted || ($forWrite && ! $this->rootIsWritable($root))) {
            throw new RuntimeException('Originals drive is unavailable, has the wrong filesystem UUID, or is not writable.');
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->assertAvailable(false);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    protected function readMountInfo(): string
    {
        return @file_get_contents('/proc/self/mountinfo') ?: '';
    }

    protected function resolvePath(string $path): string|false
    {
        clearstatcache(true, $path);

        return realpath($path);
    }

    protected function rootIsWritable(string $root): bool
    {
        return is_dir($root) && is_writable($root);
    }

    protected function filesystemDevice(string $path): ?int
    {
        $stat = @stat($path);

        return $stat === false ? null : (int) $stat['dev'];
    }

    private function unescapeMountPath(string $path): string
    {
        return preg_replace_callback('/\\\\([0-7]{3})/', fn (array $match) => chr(octdec($match[1])), $path);
    }
}
