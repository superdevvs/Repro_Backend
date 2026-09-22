<?php

namespace Tests\Unit;

use App\Services\RawPreviewService;
use Tests\TestCase;

class RawPreviewResizeTest extends TestCase
{
    public function test_oversized_embedded_preview_uses_the_supported_imagemagick_fallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'raw-resize-');
        $image = imagecreatetruecolor(1600, 900);
        imagejpeg($image, $path);
        imagedestroy($image);
        $service = new class extends RawPreviewService {
            public array $conversions = [];

            public function resize(string $path): void
            {
                $this->resizeIfNeeded($path);
            }

            protected function convertWithImageMagick(string $inputPath, string $outputPath): bool
            {
                $this->conversions[] = [$inputPath, $outputPath];

                return true;
            }
        };
        try {
            $service->resize($path);
            $this->assertSame([[$path, $path]], $service->conversions);
        } finally {
            unlink($path);
        }
    }
}
