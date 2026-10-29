<?php

namespace App\Services\Media;

use ZipArchive;

final class ArchiveCompressionPolicy
{
    private const RAW_EXTENSIONS = ['3fr', 'arw', 'cr2', 'cr3', 'crw', 'dcr', 'dng', 'erf', 'fff', 'iiq', 'kdc', 'mef', 'mos', 'mrw', 'nef', 'nrw', 'orf', 'pef', 'raf', 'raw', 'rw2', 'rwl', 'sr2', 'srf', 'srw', 'x3f'];
    private const STORED_EXTENSIONS = ['3fr', 'arw', 'cr2', 'cr3', 'crw', 'dcr', 'dng', 'erf', 'fff', 'iiq', 'kdc', 'mef', 'mos', 'mrw', 'nef', 'nrw', 'orf', 'pef', 'raf', 'raw', 'rw2', 'rwl', 'sr2', 'srf', 'srw', 'x3f', 'jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif', 'gif', 'jxl', 'mp4', 'mov', 'm4v', 'avi', 'mkv', 'webm', 'mts', 'm2ts', 'mp3', 'aac', 'zip', 'gz', 'bz2', 'xz', '7z', 'rar'];

    public function addFile(ZipArchive $zip, string $path, string $entryName, ?int $shootId = null): void
    {
        if (! $zip->addFile($path, $entryName)) {
            throw new \RuntimeException('Failed to add archive entry');
        }

        $enabled = app(MediaStorage::class)->performanceEnabled('archive_store_compressed', $shootId);
        [$method, $level] = $this->settingsForFile($path, $entryName, $enabled);
        if (! $zip->setCompressionName($entryName, $method, $level)) {
            throw new \RuntimeException('Failed to set archive compression');
        }
    }

    public function methodFor(string $entryName, bool $enabled): int
    {
        return $enabled && in_array(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)
            ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
    }

    /** Camera extensions can contain either compressed or uncompressed sensor data. */
    public function settingsForFile(string $path, string $entryName, bool $enabled): array
    {
        $method = $this->methodFor($entryName, $enabled);
        if (! $enabled || ! in_array(strtolower(pathinfo($entryName, PATHINFO_EXTENSION)), self::RAW_EXTENSIONS, true)) {
            return [$method, 0];
        }

        // Probe at most 192 KiB, away from embedded previews and padding at the
        // ends of large RAWs. This happens in the archive worker, never on polls.
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot read archive source');
        }
        try {
            $size = fstat($stream)['size'];
            $samples = $size <= 196608 ? [[0, $size]] : [
                [(int) ($size / 4), 65536], [(int) ($size / 2), 65536], [(int) ($size * 3 / 4), 65536],
            ];
            $sampleBytes = $compressedBytes = 0;
            foreach ($samples as [$offset, $length]) {
                if ($length === 0) {
                    continue;
                }
                if (fseek($stream, $offset) !== 0 || ($sample = fread($stream, $length)) === false || strlen($sample) !== $length) {
                    throw new \RuntimeException('Cannot sample archive source');
                }
                $compressed = gzdeflate($sample, 1);
                if ($compressed === false) {
                    throw new \RuntimeException('Cannot evaluate archive compression');
                }
                $sampleBytes += strlen($sample);
                $compressedBytes += strlen($compressed);
            }
            // Fast lossless compression retains most savings without the long
            // preparation time of the old default compression level.
            return $sampleBytes > 0 && $compressedBytes < $sampleBytes * 0.9
                ? [ZipArchive::CM_DEFLATE, 1] : [ZipArchive::CM_STORE, 0];
        } finally {
            fclose($stream);
        }
    }
}
