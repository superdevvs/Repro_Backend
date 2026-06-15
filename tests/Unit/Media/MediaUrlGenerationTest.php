<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaStorage;
use Tests\TestCase;

class MediaUrlGenerationTest extends TestCase
{
    private function configureR2Disk(): void
    {
        config()->set('filesystems.disks.media', [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'repro-media',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'url' => 'https://cdn.example.com',
            'use_path_style_endpoint' => false,
        ]);
        config()->set('media.remote_disk', 'media');
    }

    public function test_public_url_uses_cdn_custom_domain_when_reads_flipped(): void
    {
        $this->configureR2Disk();
        config()->set('media.read_from_r2', true);

        $url = (new MediaStorage())->publicUrl('shoots/1/web/a.jpg');

        $this->assertSame('https://cdn.example.com/shoots/1/web/a.jpg', $url);
    }

    public function test_temporary_url_is_presigned_against_r2_endpoint(): void
    {
        $this->configureR2Disk();
        config()->set('media.read_from_r2', true);

        $url = (new MediaStorage())->temporaryUrl('shoots/1/todo/raw.cr2', 600);

        $this->assertStringContainsString('repro-media', $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
        $this->assertStringContainsString('shoots/1/todo/raw.cr2', $url);
    }

    public function test_public_url_falls_back_to_local_when_reads_not_flipped(): void
    {
        $this->configureR2Disk();
        config()->set('media.read_from_r2', false);
        config()->set('media.r2_only', false);
        config()->set('media.local_disk', 'public');
        config()->set('filesystems.disks.public.url', 'http://localhost/storage');

        $url = (new MediaStorage())->publicUrl('shoots/1/web/a.jpg');

        $this->assertStringContainsString('/storage/shoots/1/web/a.jpg', $url);
        $this->assertStringNotContainsString('cdn.example.com', $url);
    }
}
