<?php

namespace App\Services\Studio;

use GdImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/** Restore the source exactly, and match its edge colors only in generated padding. */
class OutpaintComposite
{
    private const BAND = 64;

    public function apply(ImageInterface $canvas, ImageInterface $source, int $x, int $y): ImageInterface
    {
        $width = $source->width();
        $height = $source->height();
        $right = $x + $width;
        $bottom = $y + $height;
        if ($x < 0 || $y < 0 || $right > $canvas->width() || $bottom > $canvas->height()) {
            throw new InvalidArgumentException('The source must fit within the outpaint canvas.');
        }

        // The Studio uses GD. Convert other drivers losslessly if this helper is reused elsewhere.
        if (! $canvas->core()->native() instanceof GdImage) {
            $canvas = ImageManager::gd()->read((string) $canvas->toPng());
        }
        if (! $source->core()->native() instanceof GdImage) {
            $source = ImageManager::gd()->read((string) $source->toPng());
        }
        $target = $canvas->core()->native();
        $original = $source->core()->native();
        imagepalettetotruecolor($target);
        imagealphablending($target, false);
        imagesavealpha($target, true);

        // Read deltas before restoring the source. Profiles follow the edge, rather than
        // copying/mirroring rows of the photograph into the generated scene.
        $top = $y > 0 ? $this->profile($target, $original, $x, $y - 1, 0, 0, $width, true) : null;
        $lower = $bottom < $canvas->height() ? $this->profile($target, $original, $x, $bottom, 0, $height - 1, $width, true) : null;
        $left = $x > 0 ? $this->profile($target, $original, $x - 1, $y, 0, 0, $height, false) : null;
        $outerRight = $right < $canvas->width() ? $this->profile($target, $original, $right, $y, $width - 1, 0, $height, false) : null;

        for ($py = max(0, $y - self::BAND); $py < min($canvas->height(), $bottom + self::BAND); $py++) {
            for ($px = max(0, $x - self::BAND); $px < min($canvas->width(), $right + self::BAND); $px++) {
                $dx = $px < $x ? $x - $px : ($px >= $right ? $px - $right + 1 : 0);
                $dy = $py < $y ? $y - $py : ($py >= $bottom ? $py - $bottom + 1 : 0);
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $distance = max($dx, $dy) - 1;
                $t = $distance / (self::BAND - 1);
                $weight = 1 - 3 * $t * $t + 2 * $t * $t * $t;
                if ($weight <= 0) {
                    continue;
                }
                // Keep the immediate boundary exact. Gradually smooth the color-delta
                // field along the edge farther out, avoiding narrow structural streaks.
                $radius = min(12, intdiv($distance, 4));
                $horizontal = $dy > 0 ? $this->average($py < $y ? $top : $lower, min($width - 1, max(0, $px - $x)), $radius) : null;
                $vertical = $dx > 0 ? $this->average($px < $x ? $left : $outerRight, min($height - 1, max(0, $py - $y)), $radius) : null;
                $delta = $horizontal ?? $vertical;
                if ($horizontal !== null && $vertical !== null) {
                    $delta = [];
                    for ($channel = 0; $channel < 3; $channel++) {
                        $delta[] = ($horizontal[$channel] * $dy + $vertical[$channel] * $dx) / ($dx + $dy);
                    }
                }
                $pixel = imagecolorat($target, $px, $py);
                $rgb = $this->rgb($target, $pixel);
                $result = $pixel & 0x7F000000;
                foreach ([16, 8, 0] as $channel => $shift) {
                    $result |= max(0, min(255, (int) round($rgb[$channel] + $delta[$channel] * $weight))) << $shift;
                }
                imagesetpixel($target, $px, $py, $result);
            }
        }

        // Alpha blending is disabled: preserve every original RGBA pixel, including its edges.
        imagecopy($target, $original, $x, $y, 0, 0, $width, $height);

        return $canvas;
    }

    /** @return array<int, array{int, int, int}> Prefix sums of the RGB boundary deltas. */
    private function profile(GdImage $canvas, GdImage $source, int $x, int $y, int $sx, int $sy, int $length, bool $horizontal): array
    {
        $prefix = [[0, 0, 0]];
        for ($i = 0; $i < $length; $i++) {
            $dx = $horizontal ? $i : 0;
            $dy = $horizontal ? 0 : $i;
            $a = $this->rgb($source, imagecolorat($source, $sx + $dx, $sy + $dy));
            $b = $this->rgb($canvas, imagecolorat($canvas, $x + $dx, $y + $dy));
            $prefix[] = [$prefix[$i][0] + $a[0] - $b[0], $prefix[$i][1] + $a[1] - $b[1], $prefix[$i][2] + $a[2] - $b[2]];
        }

        return $prefix;
    }

    /** @return array{float, float, float} */
    private function average(array $prefix, int $position, int $radius): array
    {
        $start = max(0, $position - $radius);
        $end = min(count($prefix) - 1, $position + $radius + 1);
        $count = $end - $start;

        return [($prefix[$end][0] - $prefix[$start][0]) / $count, ($prefix[$end][1] - $prefix[$start][1]) / $count, ($prefix[$end][2] - $prefix[$start][2]) / $count];
    }

    /** @return array{int, int, int} */
    private function rgb(GdImage $image, int $pixel): array
    {
        if (imageistruecolor($image)) {
            return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
        }
        $color = imagecolorsforindex($image, $pixel);

        return [$color['red'], $color['green'], $color['blue']];
    }
}
