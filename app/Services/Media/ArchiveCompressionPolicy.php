<?php

namespace App\Services\Media;

use ZipArchive;

final class ArchiveCompressionPolicy
{
    private const STORED_EXTENSIONS = ['3fr', 'arw', 'cr2', 'cr3', 'crw', 'dcr', 'dng', 'erf', 'fff', 'iiq', 'kdc', 'mef', 'mos', 'mrw', 'nef', 'nrw', 'orf', 'pef', 'raf', 'raw', 'rw2', 'rwl', 'sr2', 'srf', 'srw', 'x3f', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif', 'gif', 'jxl', 'mp4', 'mov', 'm4v', 'avi', 'mkv', 'webm', 'mts', 'm2ts', 'mp3', 'aac', 'zip', 'gz', 'bz2', 'xz', '7z', 'rar'];

    public function addFile(ZipArchive $zip, string $path, string $entryName, ?int $shootId = null): void
    {
        if (! $zip->addFile($path, $entryName)) {
            throw new \RuntimeException('Failed to add archive entry');
        }

        $enabled = app(MediaStorage::class)->performanceEnabled('archive_store_compressed', $shootId);
        if (! $zip->setCompressionName($entryName, $this->methodFor($entryName, $enabled))) {
            throw new \RuntimeException('Failed to set archive compression');
        }
    }

    public function methodFor(string $entryName, bool $enabled): int
    {
        return $enabled && in_array(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)
            ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
    }
}
