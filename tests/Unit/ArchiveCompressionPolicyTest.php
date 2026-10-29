<?php

namespace Tests\Unit;

use App\Services\Media\ArchiveCompressionPolicy;
use Tests\TestCase;
use ZipArchive;

class ArchiveCompressionPolicyTest extends TestCase
{
    public function test_uncompressed_raws_shrink_losslessly_while_compressed_raws_keep_the_fast_path(): void
    {
        config(['media.archive_store_compressed' => true, 'media.performance_shoot_ids' => []]);
        $sources = [
            'uncompressed.RAF' => str_repeat(random_bytes(256).str_repeat("\0", 128), 4096),
            'compressed.CR3' => random_bytes(1500000),
            // Compressible headers alone must not classify sensor data as compressible.
            'padded.NEF' => str_repeat("\0", 100000).random_bytes(1300000).str_repeat("\0", 100000),
            'photo.jpg' => random_bytes(4096),
        ];
        $paths = [];
        $archive = tempnam(sys_get_temp_dir(), 'compression-zip-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archive, ZipArchive::OVERWRITE));
            foreach ($sources as $name => $bytes) {
                $path = tempnam(sys_get_temp_dir(), 'compression-source-');
                $paths[] = $path;
                file_put_contents($path, $bytes);
                app(ArchiveCompressionPolicy::class)->addFile($zip, $path, $name, 1);
            }
            $this->assertTrue($zip->close());
            $this->assertTrue($zip->open($archive, ZipArchive::RDONLY));
            $this->assertSame(ZipArchive::CM_DEFLATE, $zip->statName('uncompressed.RAF')['comp_method']);
            $this->assertLessThan(strlen($sources['uncompressed.RAF']) * 0.9, $zip->statName('uncompressed.RAF')['comp_size']);
            foreach (['compressed.CR3', 'padded.NEF', 'photo.jpg'] as $name) {
                $this->assertSame(ZipArchive::CM_STORE, $zip->statName($name)['comp_method']);
            }
            foreach ($sources as $name => $bytes) {
                $this->assertSame(hash('sha256', $bytes), hash('sha256', $zip->getFromName($name)));
            }
            $this->assertSame(array_keys($sources), array_map(fn ($i) => $zip->getNameIndex($i), range(0, 3)));
            $zip->close();
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
            unlink($archive);
        }
    }

    public function test_disabled_optimization_preserves_default_compression_and_does_not_probe_sources(): void
    {
        $policy = new ArchiveCompressionPolicy;
        $this->assertSame([ZipArchive::CM_DEFLATE, 0], $policy->settingsForFile('/missing', 'capture.RAF', false));
        $this->assertSame([ZipArchive::CM_STORE, 0], $policy->settingsForFile('/missing', 'video.mp4', true));
        $this->assertSame([ZipArchive::CM_DEFLATE, 0], $policy->settingsForFile('/missing', 'notes.xmp', true));
    }
}
