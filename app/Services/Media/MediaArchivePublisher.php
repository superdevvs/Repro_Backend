<?php

namespace App\Services\Media;

/** Publish on the destination filesystem so readers never observe a partial ZIP. */
final class MediaArchivePublisher
{
    public function __construct(private MediaStorage $media) {}

    public function publish(string $key, string $sourcePath): void
    {
        $key = $this->media->normalizeKey($key);
        if (! $key || preg_match('~(^|/)\.\.(/|$)~', $key)) {
            throw new \InvalidArgumentException('Invalid archive key');
        }

        if (! $this->media->r2Only()) {
            $disk = $this->media->localDisk($key);
            $temporaryKey = dirname($key).'/.'.basename($key).'.archive-'.bin2hex(random_bytes(16));
            try {
                if (! $this->linkCompletedLocalArchive($disk, $temporaryKey, $sourcePath)) {
                    $this->write($disk, $temporaryKey, $sourcePath);
                }
                // Recheck the originals mount immediately before the atomic rename.
                $this->media->writeDiskName($key);
                if (! $disk->move($temporaryKey, $key)) {
                    throw new \RuntimeException('Failed to publish archive');
                }
            } finally {
                $disk->delete($temporaryKey);
            }
        }

        // Object-store PUT becomes visible only after upload completion.
        if ($this->media->r2Only() || $this->media->dualWriteEnabled()) {
            $this->write($this->media->remoteDisk(), $key, $sourcePath);
        }
    }

    private function linkCompletedLocalArchive($disk, string $key, string $sourcePath): bool
    {
        if (! $disk instanceof \Illuminate\Filesystem\FilesystemAdapter
            || ! $disk->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter
            || ! is_file($sourcePath) || is_link($sourcePath)) {
            return false;
        }
        $disk->makeDirectory(dirname($key));
        // A hard link keeps the finished source available for remote mirroring,
        // avoids copying multi-GB bytes on the same volume, and is renamed below.
        // Cross-volume sources retain the stream-copy fallback.
        if (! @link($sourcePath, $disk->path($key))) {
            return false;
        }
        $disk->setVisibility($key, 'private');
        return true;
    }

    private function write($disk, string $key, string $sourcePath): void
    {
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to read generated archive');
        }
        try {
            if (! $disk->put($key, $stream)) {
                throw new \RuntimeException('Failed to store generated archive');
            }
        } finally {
            fclose($stream);
        }
    }
}
