<?php

namespace App\Services\Media;

use GdImage;

/**
 * High-quality downscaler for the small renditions the dashboard grids display.
 *
 * GD's own `imagecopyresampled` averages the source area, which is clean but
 * soft: a 600px tile cut straight from a 6000px original loses the fine edge
 * detail that makes a property photo look crisp, and the grids showed the
 * result as "blurry thumbnails". This applies the two things a photo pipeline
 * normally uses instead:
 *
 *  1. A separable Lanczos-3 pass, which keeps more high-frequency detail than a
 *     box/bilinear average at the same output size.
 *  2. A small-radius unsharp mask, which restores the local contrast every
 *     downscale removes.
 *
 * The Lanczos pass is PHP-level arithmetic, so cost is kept bounded by first
 * reducing the source to twice the target with GD's native (C) resampler, which
 * area-averages and therefore prefilters cleanly. Running Lanczos over the full
 * original instead takes ~9.7s per image for a fidelity gain of ~2% (RMSE 2038
 * vs 2084 against an ImageMagick Lanczos reference); supersampling to 2x costs
 * ~1.0s total and still beats GD's plain one-step resample (RMSE 2499), which
 * is what the grids were showing.
 *
 * Rows are streamed through a sliding cache rather than buffering the whole
 * intermediate, so memory stays at a few hundred KB of PHP arrays regardless of
 * the source size (an unbounded version needed ~85MB and would trip a 128M
 * memory_limit on a busy worker).
 *
 * The Imagick extension is not installed on this deployment, which is why this
 * is hand-rolled GD rather than `Imagick::FILTER_LANCZOS` + `unsharpMaskImage`.
 */
final class ImageResampler
{
    /**
     * Lanczos window size. a=3 is the standard photographic choice: wide enough
     * to retain detail, narrow enough that ringing stays invisible at thumbnail
     * scale.
     */
    private const LANCZOS_A = 3;

    /**
     * Resize `$source` to exactly $targetWidth x $targetHeight with Lanczos-3,
     * then optionally sharpen.
     *
     * The caller is responsible for computing target dimensions that preserve
     * aspect ratio; this method does not letterbox or crop. Upscaling is
     * refused (a copy is returned) because inventing pixels only adds weight.
     *
     * @param  float  $sharpenAmount  Unsharp strength; 0 disables sharpening.
     */
    public function resize(
        GdImage $source,
        int $targetWidth,
        int $targetHeight,
        float $sharpenAmount = 0.0
    ): GdImage {
        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, $targetHeight);

        if (imagesx($source) <= $targetWidth || imagesy($source) <= $targetHeight) {
            $result = $this->copy($source, imagesx($source), imagesy($source));

            if ($sharpenAmount > 0.0) {
                $this->sharpen($result, $sharpenAmount);
            }

            return $result;
        }

        $prefiltered = $this->prefilter($source, $targetWidth, $targetHeight);
        $working = $prefiltered ?? $source;

        $result = imagesx($working) === $targetWidth && imagesy($working) === $targetHeight
            ? $this->copy($working, $targetWidth, $targetHeight)
            : $this->lanczos($working, $targetWidth, $targetHeight);

        if ($prefiltered !== null) {
            imagedestroy($prefiltered);
        }

        if ($sharpenAmount > 0.0) {
            $this->sharpen($result, $sharpenAmount);
        }

        return $result;
    }

    /**
     * Composite a possibly-transparent image onto white.
     *
     * Every rendition is written as JPEG, which has no alpha channel; without
     * this, transparent PNG areas encode as whatever RGB happened to sit under
     * the alpha (usually black).
     */
    public function flattenOntoWhite(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $flattened = imagecreatetruecolor($width, $height);
        imagealphablending($flattened, true);
        imagefilledrectangle($flattened, 0, 0, $width, $height, imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $source, 0, 0, 0, 0, $width, $height);

        return $flattened;
    }

    /**
     * Small-radius unsharp mask.
     *
     * A 3x3 high-pass convolution with unit gain, which is what an unsharp mask
     * with radius ~1 reduces to. The amount was calibrated against ImageMagick
     * `-unsharp 0x0.75+0.75+0.008` on a real listing photo: RMSE against that
     * reference bottoms out around 0.08-0.10 and rises steadily past 0.2, which
     * is where output starts looking crunchy rather than sharp.
     */
    public function sharpen(GdImage $image, float $amount): void
    {
        if ($amount <= 0.0) {
            return;
        }

        $edge = -$amount;
        $center = 8 * $amount + 1;

        imageconvolution($image, [
            [$edge, $edge, $edge],
            [$edge, $center, $edge],
            [$edge, $edge, $edge],
        ], 1.0, 0);
    }

    /**
     * Reduce to twice the target with GD's native resampler, which area-averages
     * for downscales and so acts as the prefilter for the Lanczos pass.
     *
     * Supersampling at 2x is the sweet spot: it moves the bulk of the reduction
     * into C, keeps the intermediate around 1 megapixel (a few MB rather than
     * the ~24MB a half-size copy of a 24MP original costs), and leaves the
     * filtered pass enough source detail to work with.
     *
     * Returns null when the source is already small enough to filter directly,
     * in which case the caller keeps using the source.
     */
    private function prefilter(GdImage $source, int $targetWidth, int $targetHeight): ?GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $prefilterWidth = $targetWidth * 2;
        $prefilterHeight = $targetHeight * 2;

        if ($prefilterWidth >= $sourceWidth || $prefilterHeight >= $sourceHeight) {
            return null;
        }

        $prefiltered = imagecreatetruecolor($prefilterWidth, $prefilterHeight);
        imagecopyresampled(
            $prefiltered,
            $source,
            0,
            0,
            0,
            0,
            $prefilterWidth,
            $prefilterHeight,
            $sourceWidth,
            $sourceHeight
        );

        return $prefiltered;
    }

    /**
     * Separable Lanczos-3 resize: horizontal pass per source row (cached), then
     * a vertical pass that only ever holds the rows the current output row
     * references.
     */
    private function lanczos(GdImage $source, int $targetWidth, int $targetHeight): GdImage
    {
        $columnWeights = $this->weights(imagesx($source), $targetWidth);
        $rowWeights = $this->weights(imagesy($source), $targetHeight);

        $destination = imagecreatetruecolor($targetWidth, $targetHeight);
        $rowCache = [];

        foreach ($rowWeights as $y => [$sourceRows, $rowFactors, $tapCount]) {
            // Output rows advance monotonically, so anything below the current
            // window can never be referenced again.
            $firstNeeded = $sourceRows[0];
            foreach ($rowCache as $cachedRow => $_unused) {
                if ($cachedRow < $firstNeeded) {
                    unset($rowCache[$cachedRow]);
                }
            }

            $red = array_fill(0, $targetWidth, 0.0);
            $green = array_fill(0, $targetWidth, 0.0);
            $blue = array_fill(0, $targetWidth, 0.0);

            for ($tap = 0; $tap < $tapCount; $tap++) {
                $sourceRow = $sourceRows[$tap];
                if (! isset($rowCache[$sourceRow])) {
                    $rowCache[$sourceRow] = $this->scaleRow($source, $sourceRow, $columnWeights, $targetWidth);
                }

                [$rowRed, $rowGreen, $rowBlue] = $rowCache[$sourceRow];
                $factor = $rowFactors[$tap];

                for ($x = 0; $x < $targetWidth; $x++) {
                    $red[$x] += $factor * $rowRed[$x];
                    $green[$x] += $factor * $rowGreen[$x];
                    $blue[$x] += $factor * $rowBlue[$x];
                }
            }

            for ($x = 0; $x < $targetWidth; $x++) {
                imagesetpixel(
                    $destination,
                    $x,
                    $y,
                    ($this->toByte($red[$x]) << 16) | ($this->toByte($green[$x]) << 8) | $this->toByte($blue[$x])
                );
            }
        }

        return $destination;
    }

    /**
     * Apply the horizontal pass to a single source row.
     *
     * @param  array<int, array{0: list<int>, 1: list<float>, 2: int}>  $columnWeights
     * @return array{0: list<float>, 1: list<float>, 2: list<float>}
     */
    private function scaleRow(GdImage $source, int $y, array $columnWeights, int $targetWidth): array
    {
        $sourceWidth = imagesx($source);
        $red = [];
        $green = [];
        $blue = [];

        for ($x = 0; $x < $sourceWidth; $x++) {
            $rgb = imagecolorat($source, $x, $y);
            $red[$x] = ($rgb >> 16) & 0xFF;
            $green[$x] = ($rgb >> 8) & 0xFF;
            $blue[$x] = $rgb & 0xFF;
        }

        $outRed = [];
        $outGreen = [];
        $outBlue = [];

        for ($i = 0; $i < $targetWidth; $i++) {
            [$columns, $factors, $tapCount] = $columnWeights[$i];
            $accRed = 0.0;
            $accGreen = 0.0;
            $accBlue = 0.0;

            for ($tap = 0; $tap < $tapCount; $tap++) {
                $column = $columns[$tap];
                $factor = $factors[$tap];
                $accRed += $factor * $red[$column];
                $accGreen += $factor * $green[$column];
                $accBlue += $factor * $blue[$column];
            }

            $outRed[$i] = $accRed;
            $outGreen[$i] = $accGreen;
            $outBlue[$i] = $accBlue;
        }

        return [$outRed, $outGreen, $outBlue];
    }

    /**
     * Precompute, for every output position, which source positions contribute
     * and with what (normalised) weight.
     *
     * When downscaling, the kernel is stretched by the scale factor so each
     * output pixel integrates the whole source span it covers; without that
     * stretch a Lanczos pass point-samples and aliases.
     *
     * @return array<int, array{0: list<int>, 1: list<float>, 2: int}>
     */
    private function weights(int $sourceSize, int $targetSize): array
    {
        $scale = $targetSize / $sourceSize;
        $kernelScale = min($scale, 1.0);
        $support = self::LANCZOS_A / $kernelScale;

        $weights = [];

        for ($i = 0; $i < $targetSize; $i++) {
            $center = ($i + 0.5) / $scale - 0.5;
            $first = (int) max(0, (int) ceil($center - $support));
            $last = (int) min($sourceSize - 1, (int) floor($center + $support));

            $positions = [];
            $factors = [];
            $total = 0.0;

            for ($position = $first; $position <= $last; $position++) {
                $factor = $this->kernel(($position - $center) * $kernelScale);
                if ($factor === 0.0) {
                    continue;
                }

                $positions[] = $position;
                $factors[] = $factor;
                $total += $factor;
            }

            if ($positions === [] || $total === 0.0) {
                // Degenerate window (possible at an edge for extreme ratios):
                // fall back to the nearest source pixel rather than emitting a
                // black one.
                $positions = [max(0, min($sourceSize - 1, (int) round($center)))];
                $factors = [1.0];
                $total = 1.0;
            }

            if ($total !== 1.0) {
                foreach ($factors as $index => $factor) {
                    $factors[$index] = $factor / $total;
                }
            }

            $weights[$i] = [$positions, $factors, count($positions)];
        }

        return $weights;
    }

    /** Lanczos kernel: normalised sinc windowed by a wider sinc. */
    private function kernel(float $x): float
    {
        if ($x === 0.0) {
            return 1.0;
        }

        if (abs($x) >= self::LANCZOS_A) {
            return 0.0;
        }

        $piX = M_PI * $x;

        return (self::LANCZOS_A * sin($piX) * sin($piX / self::LANCZOS_A)) / ($piX * $piX);
    }

    private function copy(GdImage $source, int $width, int $height): GdImage
    {
        $copy = imagecreatetruecolor($width, $height);
        imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);

        return $copy;
    }

    private function toByte(float $value): int
    {
        if ($value <= 0.0) {
            return 0;
        }

        if ($value >= 255.0) {
            return 255;
        }

        return (int) round($value);
    }
}
