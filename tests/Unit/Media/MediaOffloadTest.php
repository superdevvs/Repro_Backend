<?php

namespace Tests\Unit\Media;

use App\Services\Media\LocalMediaDelivery;
use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MediaOffloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('media');
        config()->set([
            'media.local_disk' => 'local', 'media.legacy_public_disk' => 'public',
            'media.remote_disk' => 'media', 'media.tiered_storage_enabled' => false,
            'media.download_offload' => true, 'media.performance_shoot_ids' => [],
            'media.read_from_r2' => false, 'media.r2_only' => false,
        ]);
    }

    public function test_private_delivery_is_empty_with_encoded_internal_uri_and_safe_headers(): void
    {
        $key = 'shoots/12/web/été photo #1%.jpg';
        Storage::disk('local')->put($key, 'private photo');
        $response = app(MediaStorage::class)->downloadResponse($key, 'été photo.jpg');

        $this->assertSame('', $response->getContent());
        $this->assertSame('/_repro-media/private/shoots/12/web/%C3%A9t%C3%A9%20photo%20%231%25.jpg', $response->headers->get('X-Accel-Redirect'));
        $this->assertStringContainsString("filename*=utf-8''", $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    public function test_legacy_backing_disk_and_canary_are_resolved_per_file(): void
    {
        Storage::disk('public')->put('shoots/12/web/legacy.jpg', 'old');
        config()->set('media.performance_shoot_ids', [12]);
        $media = app(MediaStorage::class);
        $this->assertSame('/_repro-media/legacy/shoots/12/web/legacy.jpg', $media->streamResponse('shoots/12/web/legacy.jpg')->headers->get('X-Accel-Redirect'));
        Storage::disk('local')->put('shoots/13/web/other.jpg', 'new');
        $this->assertInstanceOf(StreamedResponse::class, $media->streamResponse('shoots/13/web/other.jpg'));
        $this->assertFalse($media->performanceEnabled('download_offload', key: 'share-links/random/file.zip'));
    }

    public function test_disabled_offloading_preserves_php_stream_and_remote_selection_never_offloads_retained_copy(): void
    {
        $key = 'shoots/12/web/a.jpg';
        Storage::disk('local')->put($key, 'local');
        config()->set('media.download_offload', false);
        $this->assertInstanceOf(StreamedResponse::class, app(MediaStorage::class)->streamResponse($key));
        config()->set(['media.download_offload' => true, 'media.read_from_r2' => true]);
        Storage::disk('media')->put($key, 'remote');
        $response = app(MediaStorage::class)->streamResponse($key);
        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $this->assertSame('remote', ob_get_clean());
    }

    public function test_originals_disk_is_selected_before_retained_private_copy(): void
    {
        Storage::fake('media_originals');
        config()->set([
            'media.tiered_storage_enabled' => true,
            'filesystems.disks.media_originals.root' => Storage::disk('media_originals')->path(''),
        ]);
        $guard = \Mockery::mock(OriginalsStorageGuard::class);
        $guard->shouldReceive('isAvailable')->andReturnTrue();
        app()->instance(OriginalsStorageGuard::class, $guard);
        $media = new MediaStorage($guard);
        app()->instance(MediaStorage::class, $media);
        $key = 'shoots/12/todo/original.cr3';
        Storage::disk('media_originals')->put($key, 'original');
        Storage::disk('local')->put($key, 'retained');

        $this->assertSame('/_repro-media/originals/'.$key, $media->streamResponse($key)->headers->get('X-Accel-Redirect'));
        Storage::disk('media_originals')->delete($key);
        $this->assertSame('/_repro-media/private/'.$key, $media->streamResponse($key)->headers->get('X-Accel-Redirect'));
    }

    public function test_traversal_is_rejected_before_filesystem_resolution(): void
    {
        $this->expectException(HttpException::class);
        app(MediaStorage::class)->streamResponse('shoots/12/../../secret');
    }

    public function test_symlinks_outside_root_are_rejected_for_streams_and_paths(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Requires Linux symlinks.');
        }
        $outside = tempnam(sys_get_temp_dir(), 'offload-secret-');
        file_put_contents($outside, 'secret');
        $disk = Storage::disk('local');
        $disk->makeDirectory('shoots/12');
        $link = $disk->path('shoots/12/escape.jpg');
        symlink($outside, $link);
        try {
            foreach ([true, false] as $enabled) {
                config()->set('media.download_offload', $enabled);
                try {
                    app(MediaStorage::class)->streamResponse('shoots/12/escape.jpg');
                    $this->fail('Escaping link was served.');
                } catch (HttpException $exception) {
                    $this->assertSame(404, $exception->getStatusCode());
                }
            }
        } finally {
            unlink($link);
            unlink($outside);
        }
    }

    public function test_generated_download_survives_response_and_only_expires_after_24_hours(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'offload-download-');
        file_put_contents($source, 'complete archive');
        $delivery = app(LocalMediaDelivery::class);
        $response = app(MediaStorage::class)->temporaryDownload($source, 'photos.zip', ['Content-Type' => 'application/zip'], 12);
        $uri = $response->headers->get('X-Accel-Redirect');
        $this->assertStringStartsWith('/_repro-media/downloads/', $uri);
        $published = $delivery->temporaryDirectory().'/'.basename($uri);
        try {
            $response->sendContent();
            $this->assertFileExists($published);
            $this->assertSame('complete archive', file_get_contents($published));
            $this->assertSame(0, $delivery->pruneTemporaryDownloads());
            touch($published, time() - 86401);
            clearstatcache();
            $this->assertSame(1, $delivery->pruneTemporaryDownloads());
            $this->assertFileDoesNotExist($published);
        } finally {
            if (is_file($published)) {
                unlink($published);
            }
        }
    }
}
