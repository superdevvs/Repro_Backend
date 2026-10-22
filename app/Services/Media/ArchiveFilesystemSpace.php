<?php

namespace App\Services\Media;

/** Read free blocks on the actual existing parent filesystem without creating it. */
class ArchiveFilesystemSpace
{
    public function snapshot(string $directory): array
    {
        clearstatcache();
        $existing = $directory;
        while (! is_dir($existing)) {
            if (file_exists($existing) || is_link($existing) || dirname($existing) === $existing) {
                throw new \RuntimeException('Unable to inspect the editor archive destination filesystem.');
            }
            $existing = dirname($existing);
        }
        $resolved = realpath($existing);
        $info = $resolved === false ? false : @stat($resolved);
        $free = $resolved === false ? false : @disk_free_space($resolved);
        if ($info === false || $free === false || $free < 0 || ! isset($info['dev'])) {
            throw new \RuntimeException('Unable to measure free space for the editor download; retry after storage is checked.');
        }

        return ['device' => (string) $info['dev'], 'free' => (int) $free, 'path' => $resolved];
    }
}
