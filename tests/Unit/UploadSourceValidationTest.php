<?php

namespace Tests\Unit;

use App\Services\UploadSourceService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the cloud-import filename/type gate (task 13.3): the allow-list is
 * reconciled with config/uploads.php and the broad image/*, video/* MIME
 * escape hatch is replaced with an extension-first check plus a narrow
 * safe-MIME fallback for extension-less remote files.
 */
class UploadSourceValidationTest extends TestCase
{
    private UploadSourceService $service;
    private ReflectionMethod $assertSupportedFilename;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'uploads.allowed_types' => ['jpg', 'jpeg', 'png', 'mp4', 'tiff', 'tif', 'zip'],
        ]);

        $this->service = new UploadSourceService();
        $this->assertSupportedFilename = new ReflectionMethod(
            UploadSourceService::class,
            'assertSupportedFilename'
        );
        $this->assertSupportedFilename->setAccessible(true);
    }

    private function assertFilename(string $name, ?string $contentType): void
    {
        $this->assertSupportedFilename->invoke($this->service, $name, $contentType);
    }

    #[Test]
    public function it_accepts_a_config_allow_listed_extension(): void
    {
        $this->assertFilename('living-room.jpg', 'image/jpeg');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_accepts_an_import_only_extension(): void
    {
        // .dng is not in config but is an accepted cloud-import RAW format.
        $this->assertFilename('capture.dng', 'application/octet-stream');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_a_non_allow_listed_extension_even_with_an_image_content_type(): void
    {
        // The previous MIME-prefix escape hatch would have accepted this; the
        // tightened check rejects a spoofed media type on a bad extension.
        $this->expectException(RuntimeException::class);
        $this->assertFilename('malware.exe', 'image/png');
    }

    #[Test]
    public function it_rejects_archives_via_cloud_import(): void
    {
        // ZIP is allowed for direct staff uploads but never via cloud import,
        // which performs no role check (Req 5.9).
        $this->expectException(RuntimeException::class);
        $this->assertFilename('deliverables.zip', 'application/zip');
    }

    #[Test]
    public function it_accepts_an_extension_less_file_with_a_safe_mime_type(): void
    {
        $this->assertFilename('remote-upload', 'image/jpeg');
        $this->assertTrue(true);
    }

    #[Test]
    public function it_rejects_an_extension_less_file_with_an_unsafe_mime_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->assertFilename('remote-upload', 'application/octet-stream');
    }
}
