<?php

namespace Tests\Unit;

use App\Services\Studio\HdrExposureFusion;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class HdrExposureFusionTest extends TestCase
{
    public function test_fuses_exposures_to_a_decodable_jpeg_or_reports_missing_worker_tools(): void
    {
        $finder = new ExecutableFinder;
        if (! $finder->find('align_image_stack') || ! $finder->find('enfuse')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('requires align_image_stack and enfuse');
            (new HdrExposureFusion)->merge(['source', 'source']);

            return;
        }
        $sources = [];
        foreach ([0.55, 1.0, 1.6] as $exposure) {
            $image = imagecreatetruecolor(512, 384);
            for ($y = 0; $y < 384; $y++) {
                for ($x = 0; $x < 512; $x++) {
                    $shade = min(255, (int) ((30 + $x * 0.3 + $y * 0.12) * $exposure));
                    imagesetpixel($image, $x, $y, imagecolorallocate($image, $shade, $shade, $shade));
                }
            }
            for ($i = 1; $i <= 40; $i++) {
                $x = ($i * 71) % 450;
                $y = ($i * 113) % 330;
                $color = imagecolorallocate($image, min(255, (int) (($i * 31 % 220) * $exposure)), min(255, (int) (($i * 53 % 220) * $exposure)), min(255, (int) (($i * 97 % 220) * $exposure)));
                imagefilledrectangle($image, $x, $y, $x + 15 + $i % 25, $y + 10 + $i % 20, $color);
            }
            ob_start();
            imagepng($image);
            $sources[] = ob_get_clean();
            imagedestroy($image);
        }
        $merged = (new HdrExposureFusion)->merge($sources);
        $dimensions = getimagesizefromstring($merged);
        $this->assertSame('image/jpeg', $dimensions['mime']);
        $this->assertGreaterThan(300, $dimensions[0]);
        $this->assertGreaterThan(200, $dimensions[1]);
        $this->assertLessThanOrEqual(512, $dimensions[0]);
    }
}
