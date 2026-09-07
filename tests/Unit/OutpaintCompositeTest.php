<?php

namespace Tests\Unit;

use App\Services\Studio\OutpaintComposite;
use GdImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OutpaintCompositeTest extends TestCase
{
    public function test_all_original_rgba_pixels_are_preserved_and_distant_padding_is_unchanged(): void
    {
        $source = $this->image(20, 16, 120);
        $native = $source->core()->native();
        imagealphablending($native, false);
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 20; $x++) {
                imagesetpixel($native, $x, $y, (($x % 4) << 24) | (($x * 9) << 16) | (($y * 11) << 8) | 70);
            }
        }
        $before = $this->pixels($native);
        $canvas = $this->image(220, 216, 60);
        $result = (new OutpaintComposite)->apply($canvas, $source, 100, 100)->core()->native();
        $this->assertSame($before, $this->pixels($result, 100, 100, 20, 16));
        $this->assertSame($before, $this->pixels($native), 'The source argument must not be modified.');
        $this->assertSame(0x3C3C3C, imagecolorat($result, 110, 35));
        $this->assertSame(0x3C3C3C, imagecolorat($result, 35, 108));
        $this->assertSame(0x3C3C3C, imagecolorat($result, 184, 108));
        $this->assertSame(0x3C3C3C, imagecolorat($result, 110, 180));
    }

    public function test_color_steps_are_removed_on_all_four_edges_and_taper_smoothly(): void
    {
        $canvas = $this->image(200, 200, 60);
        $source = $this->image(40, 40, 180);
        $result = (new OutpaintComposite)->apply($canvas, $source, 80, 80)->core()->native();
        foreach ([[100, 79], [100, 120], [79, 100], [120, 100]] as [$x, $y]) {
            $this->assertSame(0xB4B4B4, imagecolorat($result, $x, $y));
        }
        $previous = 180;
        for ($distance = 1; $distance < 64; $distance++) {
            $current = imagecolorat($result, 100, 79 - $distance) & 255;
            $this->assertLessThanOrEqual($previous, $current);
            $this->assertLessThanOrEqual(3, $previous - $current, 'The blend must not move the hard step to its outer edge.');
            $previous = $current;
        }
        $this->assertSame(60, $previous);
    }

    public function test_spatially_varying_edges_match_without_repeating_the_photo_structure(): void
    {
        $canvas = $this->image(40, 180, 70);
        $source = $this->image(40, 20, 110);
        $generated = $canvas->core()->native();
        $original = $source->core()->native();
        for ($x = 0; $x < 40; $x++) {
            $shade = 70 + ($x % 2) * 12;
            for ($y = 0; $y < 180; $y++) {
                imagesetpixel($generated, $x, $y, $shade * 0x010101);
            }
            imagesetpixel($original, $x, 0, ($shade + 40) * 0x010101);
        }
        // A feature inside the source is never mirrored or repeated into the padding.
        imagesetpixel($original, 10, 5, 0xFF0000);
        $result = (new OutpaintComposite)->apply($canvas, $source, 0, 80)->core()->native();
        $this->assertSame(imagecolorat($original, 10, 0), imagecolorat($result, 10, 79));
        $this->assertSame(imagecolorat($original, 11, 0), imagecolorat($result, 11, 79));
        $this->assertSame(12, (imagecolorat($result, 11, 60) & 255) - (imagecolorat($result, 10, 60) & 255));
        $pixel = imagecolorat($result, 10, 74);
        $this->assertSame(($pixel >> 16) & 255, $pixel & 255, 'Only color deltas are applied; the red source feature must not be copied.');
    }

    public function test_no_padding_restores_only_the_source_without_changing_its_pixels(): void
    {
        $source = $this->image(12, 10, 130);
        imagesetpixel($source->core()->native(), 4, 3, 0x123456);
        $expected = $this->pixels($source->core()->native());
        $result = (new OutpaintComposite)->apply($this->image(12, 10, 70), $source, 0, 0);
        $this->assertSame($expected, $this->pixels($result->core()->native()));
        $this->assertSame($expected, $this->pixels((new OutpaintComposite)->apply($result, $source, 0, 0)->core()->native()));
    }

    public function test_matching_boundary_leaves_all_generated_padding_unchanged(): void
    {
        $canvas = $this->image(120, 180, 90);
        imagesetpixel($canvas->core()->native(), 30, 20, 0xABCDEF);
        $before = $this->pixels($canvas->core()->native());
        $result = (new OutpaintComposite)->apply($canvas, $this->image(120, 20, 90), 0, 80);
        $this->assertSame($before, $this->pixels($result->core()->native()));
    }

    public function test_a_source_that_would_be_cropped_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new OutpaintComposite)->apply($this->image(30, 30, 70), $this->image(20, 20, 100), 15, 0);
    }

    private function image(int $width, int $height, int $shade): ImageInterface
    {
        return ImageManager::gd()->create($width, $height)->fill(sprintf('%02x%02x%02x', $shade, $shade, $shade));
    }

    private function pixels(GdImage $image, int $x = 0, int $y = 0, ?int $width = null, ?int $height = null): array
    {
        $pixels = [];
        for ($py = $y; $py < $y + ($height ?? imagesy($image)); $py++) {
            for ($px = $x; $px < $x + ($width ?? imagesx($image)); $px++) {
                $pixels[] = imagecolorat($image, $px, $py);
            }
        }

        return $pixels;
    }
}
