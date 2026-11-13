<?php

namespace Tests\Feature;

use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectMediaDownloadTest extends TestCase
{
    public function test_signed_download_preserves_attachment_name_and_bytes(): void
    {
        Storage::fake('local');
        config(['media.download_origin' => 'https://downloads.reprodashboard.com',
            'media.local_disk' => 'local', 'media.download_offload' => false,
            'media.read_from_r2' => false, 'media.r2_only' => false]);
        $key = 'shoots/5999/todo/original.raf';
        Storage::disk('local')->put($key, 'original-file-bytes');
        $url = app(MediaStorage::class)->signedAppUrl($key, 60, 'Property 001.raf');
        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringContainsString('Property 001.raf', $response->headers->get('Content-Disposition'));
        $this->assertSame('original-file-bytes', $response->streamedContent());
        $this->get(str_replace('original.raf', 'another.raf', $url))->assertForbidden();
        $this->get(str_replace('Property%20001.raf', 'Other.raf', $url))->assertForbidden();
    }

    public function test_direct_origin_does_not_make_non_media_paths_public(): void
    {
        Storage::fake('local');
        config(['media.download_origin' => 'https://downloads.reprodashboard.com', 'media.local_disk' => 'local']);
        Storage::disk('local')->put('secrets/test.txt', 'secret');
        $this->get(app(MediaStorage::class)->signedAppUrl('secrets/test.txt', 60, 'test.txt'))->assertNotFound();
    }
}
