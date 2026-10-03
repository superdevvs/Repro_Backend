<?php

/**
 * Read-only source benchmark. Repeats supplied real files under unique entry names.
 * php scripts/benchmark-media-archives.php /fixture-one /fixture-two
 * ARCHIVE_BENCHMARK_BYTES=3000000000,11000000000; ZIPs use system temp and are deleted.
 * Compression wall time includes addFile + close; integrity verification is outside timing.
 */
require_once __DIR__.'/../app/Services/Media/ArchiveCompressionPolicy.php';

use App\Services\Media\ArchiveCompressionPolicy;

$sources = [];
foreach (array_slice($argv, 1) as $directory) {
    if (! is_dir($directory)) {
        throw new RuntimeException('Fixture directory missing');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && ! $file->isLink() && $file->getSize() > 0) {
            $sources[] = ['path' => $file->getPathname(), 'name' => $file->getFilename(), 'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
        }
    }
}
usort($sources, fn ($a, $b) => strcmp($a['name'], $b['name']));
if ($sources === []) {
    throw new RuntimeException('No fixture files supplied');
}
$policy = new ArchiveCompressionPolicy;
$results = [];
foreach (explode(',', getenv('ARCHIVE_BENCHMARK_BYTES') ?: '3000000000,11000000000') as $targetBytes) {
    $entries = [];
    $bytes = 0;
    for ($index = 0; $bytes < (int) $targetBytes; $index++) {
        $source = $sources[$index % count($sources)];
        $source['entry'] = sprintf('%04d_', $index + 1).$source['name'];
        $entries[] = $source;
        $bytes += $source['bytes'];
    }
    $measurements = [];
    foreach (['deflate' => false, 'store_compressed' => true] as $variant => $enabled) {
        $path = tempnam(sys_get_temp_dir(), 'archive-benchmark-');
        if ($path === false) {
            throw new RuntimeException('No temporary archive path');
        }
        try {
            $start = hrtime(true);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create benchmark archive');
            }
            foreach ($entries as $entry) {
                if (! $zip->addFile($entry['path'], $entry['entry'])
                    || ! $zip->setCompressionName($entry['entry'], $policy->methodFor($entry['entry'], $enabled))) {
                    throw new RuntimeException('Could not add benchmark entry');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not close benchmark archive');
            }
            $seconds = (hrtime(true) - $start) / 1e9;
            $zipBytes = filesize($path);
            if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($entries)) {
                throw new RuntimeException('Archive count mismatch');
            }
            foreach ($entries as $index => $entry) {
                if ($zip->getNameIndex($index) !== $entry['entry']) {
                    throw new RuntimeException('Archive order mismatch');
                }
                $stream = $zip->getStream($entry['entry']);
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new RuntimeException('Archive extracted bytes mismatch');
                }
            }
            $zip->close();
            $measurements[$variant] = ['seconds' => round($seconds, 3), 'archive_bytes' => $zipBytes, 'sha256_and_order_verified' => true];
            fwrite(STDERR, json_encode(['target_bytes' => (int) $targetBytes, 'variant' => $variant] + $measurements[$variant]).PHP_EOL);
        } finally {
            @unlink($path);
        }
    }
    $gain = 100 * (1 - $measurements['store_compressed']['seconds'] / $measurements['deflate']['seconds']);
    $results[] = ['fixture' => 'repeated supplied real files', 'unique_source_files' => count($sources), 'entries' => count($entries), 'source_bytes' => $bytes, 'variants' => $measurements, 'preparation_reduction_percent' => round($gain, 2), 'target_met' => $gain >= 50];
}
echo json_encode(['environment' => ['php' => PHP_VERSION, 'libzip' => ZipArchive::LIBZIP_VERSION, 'os' => PHP_OS_FAMILY], 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
