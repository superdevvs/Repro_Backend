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

    private function exiftoolAvailable(): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        exec("$check exiftool 2>&1", $out, $code);
        return $code === 0;
    }

    /**
     * Locate a real Nikon High Efficiency NEF in the workspace storage, if any
     * exists, so the test can exercise the true detection + extraction path.
     */
    private function findHighEfficiencyNef(): ?string
    {
        if (!$this->exiftoolAvailable()) {
            return null;
        }

        $base = storage_path('app/public/shoots');
        if (!is_dir($base)) {
            return null;
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'nef') {
                continue;
            }
            // Cap the scan so the test stays fast.
            if (++$count > 200) {
                break;
            }
            if ($this->service->autoenhanceNeedsJpegSubstitution($file->getPathname())) {
                return $file->getPathname();
            }
        }

        return null;
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
        $heNef = $this->findHighEfficiencyNef();

        if ($heNef === null) {
            $this->markTestSkipped(
                'No Nikon High Efficiency NEF available (or exiftool not installed) to exercise the real path.'
            );
        }

        // Detection: this variant MUST be flagged for JPEG substitution.
        $this->assertTrue(
            $this->service->autoenhanceNeedsJpegSubstitution($heNef),
            'Expected the HE-compressed NEF to require JPEG substitution.'
        );

        // Extraction: we must get a real, decodable JPEG of sensible size.
        $jpeg = $this->service->extractFullSizeJpeg($heNef);
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
    }
}
