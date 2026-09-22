<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class MediaResponseMimeTypeTest extends TestCase
{
    private MediaStorage $media;
    private FilesystemAdapter $disk;
    private MimeTypeDetector $detector;
    private string $key = 'shoots/81/todo/original.raw';
    private string $bytes = "raw-original\0bytes\xff";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('media_originals', config('filesystems.disks.media_originals'));
        $root = Storage::disk('media_originals')->path('');
        $this->detector = $this->createMock(MimeTypeDetector::class);
        // A real strict Flysystem adapter reproduces inconclusive finfo metadata
        // without relying on a particular platform's RAW magic database.
        $adapter = new LocalFilesystemAdapter($root, mimeTypeDetector: $this->detector);
        $this->disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => true]);
        Storage::set('media_originals', $this->disk);
        config()->set([
            'media.local_disk' => 'local', 'media.legacy_public_disk' => 'public',
            'media.originals_disk' => 'media_originals', 'media.tiered_storage_enabled' => true,
            'media.dual_write' => false, 'media.read_from_r2' => false, 'media.r2_only' => false,
        ]);
        $guard = new class extends OriginalsStorageGuard
        {
            public function assertAvailable(bool $forWrite = true): void {}
        };
        $this->media = new MediaStorage($guard);
        $this->disk->put($this->key, $this->bytes);
    }

    #[DataProvider('responseMethods')]
    public function test_inconclusive_mime_on_strict_disk_still_streams_exact_bytes(string $method): void
    {
        $this->detector->method('detectMimeTypeFromFile')->willReturn(null);
        try {
            $this->disk->mimeType($this->key);
            $this->fail('The fixture must reproduce strict metadata failure.');
        } catch (UnableToRetrieveMetadata) {
            $this->assertSame($this->bytes, $this->disk->get($this->key));
        }

        $response = $this->response($method);
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame($this->bytes, $this->capture($response));
        if ($method === 'download') {
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('original.raw', $response->headers->get('Content-Disposition'));
            $this->assertSame((string) strlen($this->bytes), $response->headers->get('Content-Length'));
        }
    }

    #[DataProvider('responseMethods')]
    public function test_explicit_content_type_skips_detection_and_is_preserved(string $method): void
    {
        $this->detector->expects($this->never())->method('detectMimeTypeFromFile');
        $response = $this->response($method, ['Content-Type' => 'image/x-camera-raw', 'X-Request-Check' => 'kept']);
        $this->assertSame('image/x-camera-raw', $response->headers->get('Content-Type'));
        $this->assertSame('kept', $response->headers->get('X-Request-Check'));
        $this->assertSame($this->bytes, $this->capture($response));
    }

    public function test_explicit_stream_mime_argument_skips_detection(): void
    {
        $this->detector->expects($this->never())->method('detectMimeTypeFromFile');
        $response = $this->media->streamResponse($this->key, 'image/x-camera-raw');
        $this->assertSame('image/x-camera-raw', $response->headers->get('Content-Type'));
        $this->assertSame($this->bytes, $this->capture($response));
    }

    #[DataProvider('responseMethods')]
    public function test_missing_file_remains_not_found(string $method): void
    {
        $this->disk->delete($this->key);
        $this->expectException(NotFoundHttpException::class);
        $this->response($method);
    }

    #[DataProvider('responseMethods')]
    public function test_read_failure_after_response_construction_is_not_masked(string $method): void
    {
        $this->detector->method('detectMimeTypeFromFile')->willReturn(null);
        $response = $this->response($method);
        $this->disk->delete($this->key);
        $this->expectException(UnableToReadFile::class);
        $this->capture($response);
    }

    #[DataProvider('responseMethods')]
    public function test_unrelated_detector_errors_are_not_masked(string $method): void
    {
        $this->detector->method('detectMimeTypeFromFile')->willThrowException(new RuntimeException('Detector unavailable'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Detector unavailable');
        $this->response($method);
    }

    public static function responseMethods(): array
    {
        return [['stream'], ['download']];
    }

    private function response(string $method, array $headers = []): StreamedResponse
    {
        return $method === 'stream'
            ? $this->media->streamResponse($this->key, headers: $headers)
            : $this->media->downloadResponse($this->key, 'original.raw', $headers);
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        try {
            $response->sendContent();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
