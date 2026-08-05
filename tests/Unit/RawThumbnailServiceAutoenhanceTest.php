<?php

namespace Tests\Unit;

use App\Services\RawThumbnailService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the Autoenhance RAW-substitution fix.
 *
 * Context: Autoenhance natively supports most RAW formats, but cannot decode a
 * few compressed variants (Nikon HE/HE*, Canon CRAW, Sony lossless). Those came
 * back as corrupted "rainbow static" enhancements. The pipeline now detects
 * those variants and uploads a full-size JPEG instead.
 */
class RawThumbnailServiceAutoenhanceTest extends TestCase
{
    private RawThumbnailService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RawThumbnailService();
    }

    #[Test]
    public function it_recognises_nikon_nef_as_a_raw_file(): void
    {
        $this->assertTrue($this->service->isRawFile('photo.NEF'));
        $this->assertTrue($this->service->isRawFile('photo.nef'));
        $this->assertTrue($this->service->isRawFile('photo.cr3'));
        $this->assertFalse($this->service->isRawFile('photo.jpg'));
        $this->assertFalse($this->service->isRawFile('photo.png'));
    }

    #[Test]
    public function it_does_not_flag_a_jpeg_for_substitution(): void
    {
        // A JPEG is not a RAW file and must never be flagged for substitution,
        // regardless of tooling availability.
        $jpeg = tempnam(sys_get_temp_dir(), 'ae_test_') . '.jpg';
        $img = imagecreatetruecolor(64, 64);
        imagejpeg($img, $jpeg);
        imagedestroy($img);

        try {
            $this->assertFalse($this->service->autoenhanceNeedsJpegSubstitution($jpeg));
        } finally {
            @unlink($jpeg);
        }
    }

    #[Test]
    public function it_returns_false_for_substitution_when_source_is_missing(): void
    {
        $this->assertFalse(
            $this->service->autoenhanceNeedsJpegSubstitution('/path/does/not/exist.nef')
        );
    }

    #[Test]
    public function it_detects_unsupported_high_efficiency_nef_and_extracts_a_valid_jpeg(): void
    {
        $image = imagecreatetruecolor(1200, 800);
        $background = imagecolorallocate($image, 30, 90, 150);
        imagefill($image, 0, 0, $background);
        for ($x = 0; $x < 1200; $x += 20) {
            $color = imagecolorallocate($image, $x % 255, ($x * 2) % 255, ($x * 3) % 255);
            imageline($image, $x, 0, 1199 - $x, 799, $color);
        }
        ob_start();
        imagejpeg($image, null, 92);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($image);

        $service = new class($jpegBytes) extends RawThumbnailService {
            public function __construct(private readonly string $jpegBytes) {}

            protected function commandExists(string $command): bool
            {
                return $command === 'exiftool';
            }

            protected function runCommand(string $command, array &$output): int
            {
                $output = ['High Efficiency*', 'NEF'];

                return 0;
            }

            protected function extractEmbeddedJpegData(string $sourcePath, string $tag): ?string
            {
                return $tag === 'JpgFromRaw' ? $this->jpegBytes : null;
            }
        };

        $heNef = tempnam(sys_get_temp_dir(), 'he_nef_') . '.nef';
        file_put_contents($heNef, 'deterministic-test-raw');

        try {
            // Detection: this variant MUST be flagged for JPEG substitution.
            $this->assertTrue(
                $service->autoenhanceNeedsJpegSubstitution($heNef),
                'Expected the HE-compressed NEF to require JPEG substitution.'
            );

            // Extraction: we must get a real, decodable JPEG of sensible size.
            $jpeg = $service->extractFullSizeJpeg($heNef);
            $this->assertNotNull($jpeg, 'Expected a JPEG to be extracted from the HE NEF.');

            try {
                $this->assertFileExists($jpeg);

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $jpeg);
                finfo_close($finfo);
                $this->assertSame('image/jpeg', $mime, 'Extracted file must be a JPEG.');

                $info = getimagesize($jpeg);
                $this->assertNotFalse($info, 'Extracted JPEG must be decodable.');
                $this->assertGreaterThanOrEqual(800, $info[0], 'Extracted JPEG should be full-size, not a tiny thumbnail.');
            } finally {
                @unlink($jpeg);
            }
        } finally {
            @unlink($heNef);
        }
    }
}
