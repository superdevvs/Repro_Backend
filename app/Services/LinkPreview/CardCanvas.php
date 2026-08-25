<?php

namespace App\Services\LinkPreview;

use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Drawing primitives for the 1200x630 preview cards.
 *
 * Raw GD rather than Intervention, for the same reason ImageProcessingService
 * drops to GD for its resize path: the cards need exact control over alpha
 * compositing (scrims, chips, glows) and over text baselines, and going through
 * an abstraction for a few hundred blended rectangles buys nothing. Intervention
 * stays in the picture only where it is genuinely better - it is not needed here.
 *
 * All coordinates are in card pixels with the origin at the top left.
 */
class CardCanvas
{
    private GdImage $im;

    public function __construct(
        private readonly int $canvasWidth,
        private readonly int $canvasHeight,
        string $backgroundHex = '#060a0e',
    ) {
        $im = imagecreatetruecolor($canvasWidth, $canvasHeight);
        if ($im === false) {
            throw new \RuntimeException('LinkPreview: unable to allocate card canvas.');
        }

        imagealphablending($im, true);
        imagesavealpha($im, false);

        $this->im = $im;
        $this->fillRect(0, 0, $canvasWidth, $canvasHeight, $backgroundHex, 1.0);
    }

    public function width(): int
    {
        return $this->canvasWidth;
    }

    public function height(): int
    {
        return $this->canvasHeight;
    }

    public function native(): GdImage
    {
        return $this->im;
    }

    // -----------------------------------------------------------------
    // Solid shapes
    // -----------------------------------------------------------------

    public function fillRect(int $x, int $y, int $w, int $h, string $hex, float $opacity = 1.0): static
    {
        if ($w <= 0 || $h <= 0 || $opacity <= 0) {
            return $this;
        }

        imagefilledrectangle($this->im, $x, $y, $x + $w - 1, $y + $h - 1, $this->color($hex, $opacity));

        return $this;
    }

    /**
     * Rounded rectangle. Drawn on its own fully-opaque layer and then
     * composited, because stacking semi-transparent corner ellipses over
     * semi-transparent side rectangles double-blends the overlaps and leaves
     * visible seams at the corners.
     */
    public function roundedRect(
        int $x,
        int $y,
        int $w,
        int $h,
        int $radius,
        string $hex,
        float $opacity = 1.0,
        ?string $borderHex = null,
        float $borderOpacity = 1.0,
        int $borderWidth = 1,
    ): static {
        if ($w <= 0 || $h <= 0 || $opacity <= 0) {
            return $this;
        }

        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));

        $layer = imagecreatetruecolor($w, $h);
        if ($layer === false) {
            return $this;
        }
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($layer, 0, 0, 0, 127));

        // Opaque on the layer; the global opacity is applied at composite time.
        imagealphablending($layer, true);
        [$r, $g, $b] = $this->rgb($hex);
        $fill = imagecolorallocatealpha($layer, $r, $g, $b, 0);

        if ($radius === 0) {
            imagefilledrectangle($layer, 0, 0, $w - 1, $h - 1, $fill);
        } else {
            $d = $radius * 2;
            imagefilledrectangle($layer, $radius, 0, $w - 1 - $radius, $h - 1, $fill);
            imagefilledrectangle($layer, 0, $radius, $w - 1, $h - 1 - $radius, $fill);
            imagefilledellipse($layer, $radius, $radius, $d, $d, $fill);
            imagefilledellipse($layer, $w - 1 - $radius, $radius, $d, $d, $fill);
            imagefilledellipse($layer, $radius, $h - 1 - $radius, $d, $d, $fill);
            imagefilledellipse($layer, $w - 1 - $radius, $h - 1 - $radius, $d, $d, $fill);
        }

        if ($borderHex !== null && $borderWidth > 0) {
            [$br, $bg, $bb] = $this->rgb($borderHex);
            $borderColor = imagecolorallocatealpha(
                $layer,
                $br,
                $bg,
                $bb,
                (int) round((1 - max(0.0, min(1.0, $borderOpacity))) * 127)
            );
            imagesetthickness($layer, $borderWidth);
            if ($radius === 0) {
                imagerectangle($layer, 0, 0, $w - 1, $h - 1, $borderColor);
            } else {
                $d = $radius * 2;
                imageline($layer, $radius, 0, $w - 1 - $radius, 0, $borderColor);
                imageline($layer, $radius, $h - 1, $w - 1 - $radius, $h - 1, $borderColor);
                imageline($layer, 0, $radius, 0, $h - 1 - $radius, $borderColor);
                imageline($layer, $w - 1, $radius, $w - 1, $h - 1 - $radius, $borderColor);
                imagearc($layer, $radius, $radius, $d, $d, 180, 270, $borderColor);
                imagearc($layer, $w - 1 - $radius, $radius, $d, $d, 270, 360, $borderColor);
                imagearc($layer, $radius, $h - 1 - $radius, $d, $d, 90, 180, $borderColor);
                imagearc($layer, $w - 1 - $radius, $h - 1 - $radius, $d, $d, 0, 90, $borderColor);
            }
            imagesetthickness($layer, 1);
        }

        $this->compositeLayer($layer, $x, $y, $opacity);
        imagedestroy($layer);

        return $this;
    }

    public function circle(int $cx, int $cy, int $radius, string $hex, float $opacity = 1.0): static
    {
        if ($radius <= 0 || $opacity <= 0) {
            return $this;
        }

        imagefilledellipse($this->im, $cx, $cy, $radius * 2, $radius * 2, $this->color($hex, $opacity));

        return $this;
    }

    public function ring(int $cx, int $cy, int $radius, int $thickness, string $hex, float $opacity = 1.0): static
    {
        if ($radius <= 0 || $thickness <= 0 || $opacity <= 0) {
            return $this;
        }

        // Same layer trick as roundedRect: an arc stroked at thickness > 1
        // overlaps itself and darkens at semi-transparent opacities.
        $size = ($radius + $thickness) * 2 + 2;
        $layer = imagecreatetruecolor($size, $size);
        if ($layer === false) {
            return $this;
        }
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);

        [$r, $g, $b] = $this->rgb($hex);
        $stroke = imagecolorallocatealpha($layer, $r, $g, $b, 0);
        $mid = (int) floor($size / 2);
        imagesetthickness($layer, $thickness);
        imageellipse($layer, $mid, $mid, $radius * 2, $radius * 2, $stroke);
        imagesetthickness($layer, 1);

        $this->compositeLayer($layer, $cx - $mid, $cy - $mid, $opacity);
        imagedestroy($layer);

        return $this;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $points
     */
    public function polygon(array $points, string $hex, float $opacity = 1.0): static
    {
        if (count($points) < 3 || $opacity <= 0) {
            return $this;
        }

        $flat = [];
        foreach ($points as [$px, $py]) {
            $flat[] = $px;
            $flat[] = $py;
        }

        imagefilledpolygon($this->im, $flat, $this->color($hex, $opacity));

        return $this;
    }

    // -----------------------------------------------------------------
    // Gradients
    // -----------------------------------------------------------------

    /**
     * Vertical gradient of a single colour, fading between two opacities.
     *
     * Each row is blended independently against whatever is underneath, which
     * is what makes a scrim over a photo read smoothly. $direction 'down' means
     * $startOpacity at the top edge; 'up' means $startOpacity at the bottom.
     */
    public function verticalGradient(
        int $x,
        int $y,
        int $w,
        int $h,
        string $hex,
        float $startOpacity,
        float $endOpacity,
        string $direction = 'down',
        float $ease = 1.0,
    ): static {
        if ($w <= 0 || $h <= 0) {
            return $this;
        }

        for ($row = 0; $row < $h; $row++) {
            $t = $h === 1 ? 0.0 : $row / ($h - 1);
            if ($direction === 'up') {
                $t = 1.0 - $t;
            }
            if ($ease !== 1.0) {
                $t = $t ** $ease;
            }

            $opacity = $startOpacity + ($endOpacity - $startOpacity) * $t;
            if ($opacity <= 0.002) {
                continue;
            }

            imagefilledrectangle(
                $this->im,
                $x,
                $y + $row,
                $x + $w - 1,
                $y + $row,
                $this->color($hex, $opacity)
            );
        }

        return $this;
    }

    /**
     * Soft radial glow, used for the brand card's red/blue accents.
     *
     * Rendered small and scaled up: GD interpolates the alpha channel when it
     * resamples, so a 64px source produces a smoother falloff than stacking
     * concentric ellipses on the full-size canvas, and costs a few thousand
     * pixel writes instead of hundreds of thousands.
     */
    public function radialGlow(int $cx, int $cy, int $radius, string $hex, float $peakOpacity, float $falloff = 2.0): static
    {
        if ($radius <= 0 || $peakOpacity <= 0) {
            return $this;
        }

        $n = 64;
        $small = imagecreatetruecolor($n, $n);
        if ($small === false) {
            return $this;
        }
        imagealphablending($small, false);
        imagesavealpha($small, true);

        [$r, $g, $b] = $this->rgb($hex);
        $mid = ($n - 1) / 2;

        for ($py = 0; $py < $n; $py++) {
            for ($px = 0; $px < $n; $px++) {
                $dist = sqrt((($px - $mid) ** 2) + (($py - $mid) ** 2)) / $mid;
                $opacity = $dist >= 1.0 ? 0.0 : $peakOpacity * ((1.0 - $dist) ** $falloff);
                imagesetpixel(
                    $small,
                    $px,
                    $py,
                    imagecolorallocatealpha($small, $r, $g, $b, (int) round((1 - $opacity) * 127))
                );
            }
        }

        $size = $radius * 2;
        imagecopyresampled($this->im, $small, $cx - $radius, $cy - $radius, 0, 0, $size, $size, $n, $n);
        imagedestroy($small);

        return $this;
    }

    // -----------------------------------------------------------------
    // Images
    // -----------------------------------------------------------------

    /**
     * Cover-crop $bytes into the given box: fill it entirely, preserving aspect
     * ratio and centre-cropping the overflow. This is what og:image needs -
     * 1.91:1 rarely matches a camera's 3:2 frame, and letterboxing a listing
     * photo looks broken next to every other card in a feed.
     */
    public function drawImageCover(?string $bytes, int $x, int $y, int $w, int $h, float $opacity = 1.0): bool
    {
        $src = $this->decode($bytes);
        if ($src === null || $w <= 0 || $h <= 0) {
            return false;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $srcRatio = $sw / max($sh, 1);
        $dstRatio = $w / $h;

        if ($srcRatio > $dstRatio) {
            $cropH = $sh;
            $cropW = (int) round($sh * $dstRatio);
            $cropX = (int) round(($sw - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $sw;
            $cropH = (int) round($sw / $dstRatio);
            $cropX = 0;
            $cropY = (int) round(($sh - $cropH) / 2);
        }

        if ($opacity >= 1.0) {
            imagecopyresampled($this->im, $src, $x, $y, $cropX, $cropY, $w, $h, $cropW, $cropH);
        } else {
            $layer = imagecreatetruecolor($w, $h);
            if ($layer === false) {
                imagedestroy($src);

                return false;
            }
            imagecopyresampled($layer, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);
            imagecopymerge($this->im, $layer, $x, $y, 0, 0, $w, $h, (int) round($opacity * 100));
            imagedestroy($layer);
        }

        imagedestroy($src);

        return true;
    }

    /**
     * Fit $bytes inside the box without cropping, preserving aspect ratio and
     * per-pixel alpha. Used for the logo, where distorting the mark or clipping
     * its edges would both be wrong.
     */
    public function drawImageContain(?string $bytes, int $x, int $y, int $maxW, int $maxH, string $anchor = 'left-top'): ?array
    {
        $src = $this->decode($bytes);
        if ($src === null || $maxW <= 0 || $maxH <= 0) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($maxW / max($sw, 1), $maxH / max($sh, 1));
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));

        $dx = match ($anchor) {
            'right-top', 'right-middle' => $x + $maxW - $dw,
            'center-top', 'center-middle' => $x + (int) round(($maxW - $dw) / 2),
            default => $x,
        };
        $dy = match ($anchor) {
            'left-middle', 'right-middle', 'center-middle' => $y + (int) round(($maxH - $dh) / 2),
            default => $y,
        };

        imagealphablending($this->im, true);
        imagecopyresampled($this->im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        return ['x' => $dx, 'y' => $dy, 'width' => $dw, 'height' => $dh];
    }

    // -----------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------

    /**
     * Draw text anchored relative to ($x, $y).
     *
     * $align  : left | center | right
     * $valign : baseline | top | middle | bottom
     *
     * GD positions text on its baseline. 'middle' is computed from the actual
     * glyph bounding box rather than the em box, so an all-caps chip label sits
     * optically centred instead of low.
     */
    public function text(
        string $text,
        int $x,
        int $y,
        ?string $fontPath,
        float $size,
        string $hex,
        float $opacity = 1.0,
        string $align = 'left',
        string $valign = 'baseline',
    ): static {
        if ($text === '' || !$fontPath || $opacity <= 0) {
            return $this;
        }

        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            return $this;
        }

        $width = (int) round(max($box[2], $box[4]) - min($box[0], $box[6]));
        $top = min($box[5], $box[7]);      // negative: above baseline
        $bottom = max($box[1], $box[3]);   // positive: below baseline

        $drawX = match ($align) {
            'center' => $x - (int) round($width / 2),
            'right' => $x - $width,
            default => $x,
        };

        $drawY = match ($valign) {
            'top' => $y - $top,
            'middle' => $y - $top - (int) round(($bottom - $top) / 2),
            'bottom' => $y - $bottom,
            default => $y,
        };

        // imagettfbbox can report a small negative left bearing; correct for it
        // so left-aligned runs share a true optical margin.
        $drawX -= min($box[0], $box[6]);

        imagettftext($this->im, $size, 0, $drawX, $drawY, $this->color($hex, $opacity), $fontPath, $text);

        return $this;
    }

    /**
     * Draw text with extra letter spacing, glyph by glyph.
     *
     * GD has no tracking control, and the small all-caps labels on these cards
     * (VIRTUAL TOUR, 24 PHOTOS, BEDS) look cramped and cheap set solid. Advance
     * is measured per glyph so the spacing stays even in a proportional face.
     */
    public function textTracked(
        string $text,
        int $x,
        int $y,
        ?string $fontPath,
        float $size,
        string $hex,
        float $tracking,
        float $opacity = 1.0,
        string $align = 'left',
        string $valign = 'baseline',
    ): static {
        if ($text === '' || !$fontPath || $opacity <= 0) {
            return $this;
        }

        $offsets = $this->trackedOffsets($text, $fontPath, $size, $tracking);
        if ($offsets === []) {
            return $this;
        }

        $width = (int) round(array_pop($offsets));

        $drawX = match ($align) {
            'center' => $x - (int) round($width / 2),
            'right' => $x - $width,
            default => $x,
        };

        // Resolve the baseline once, from the whole run. Aligning each glyph
        // individually would key off its own bounding box, which lifts short
        // glyphs to the line top - a hyphen between words renders as a
        // superscript dash.
        $baseline = $this->baselineFor($text, $fontPath, $size, $y, $valign);

        foreach (mb_str_split($text) as $i => $char) {
            if (trim($char) === '') {
                continue;
            }
            $this->text($char, $drawX + (int) round($offsets[$i]), $baseline, $fontPath, $size, $hex, $opacity, 'left', 'baseline');
        }

        return $this;
    }

    /**
     * Baseline y for a run anchored at $y under the given vertical alignment.
     */
    private function baselineFor(string $text, string $fontPath, float $size, int $y, string $valign): int
    {
        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            return $y;
        }

        $top = min($box[5], $box[7]);
        $bottom = max($box[1], $box[3]);

        return match ($valign) {
            'top' => $y - $top,
            'middle' => $y - $top - (int) round(($bottom - $top) / 2),
            'bottom' => $y - $bottom,
            default => $y,
        };
    }

    public function trackedWidth(string $text, ?string $fontPath, float $size, float $tracking): int
    {
        $offsets = $this->trackedOffsets($text, $fontPath, $size, $tracking);

        return $offsets === [] ? 0 : (int) round(array_pop($offsets));
    }

    /**
     * Per-glyph x offsets for a tracked run, plus the total width as the final
     * element.
     *
     * Offsets are derived from the measured width of each cumulative prefix
     * rather than from per-glyph bounding boxes. A glyph's bounding box is not
     * its advance - it excludes side bearings - so summing boxes inserts a
     * visible gap after wide letters ("W ALKTHROUGH", "PHOTOGRAPH Y"). Measuring
     * prefixes also preserves the font's kerning.
     *
     * @return array<int, float>
     */
    private function trackedOffsets(string $text, ?string $fontPath, float $size, float $tracking): array
    {
        if ($text === '' || !$fontPath) {
            return [];
        }

        $chars = mb_str_split($text);
        $offsets = [];
        $prefix = '';
        $previousWidth = 0.0;

        foreach ($chars as $i => $char) {
            $offsets[$i] = $previousWidth + $i * $tracking;
            $prefix .= $char;

            $box = @imagettfbbox($size, 0, $fontPath, $prefix);
            $previousWidth = $box === false
                ? $previousWidth + $size * 0.55
                : (float) (max($box[2], $box[4]) - min($box[0], $box[6]));
        }

        // Total width lands in the slot after the last glyph.
        $offsets[count($chars)] = $previousWidth + (count($chars) - 1) * $tracking;

        return $offsets;
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    /**
     * Encode as JPEG, stepping quality down until the payload fits $maxBytes.
     *
     * WhatsApp silently drops an og:image over roughly 600KB - the link then
     * unfurls with no picture at all and there is no error anywhere to tell you
     * why - so staying comfortably under a budget matters more than the last
     * few percent of quality.
     */
    public function toJpeg(int $quality, ?int $maxBytes = null, int $minQuality = 60): string
    {
        $quality = max(1, min(100, $quality));

        while (true) {
            ob_start();
            imagejpeg($this->im, null, $quality);
            $bytes = (string) ob_get_clean();

            if ($maxBytes === null || strlen($bytes) <= $maxBytes || $quality <= $minQuality) {
                return $bytes;
            }

            $quality -= 6;
        }
    }

    public function destroy(): void
    {
        if (isset($this->im)) {
            imagedestroy($this->im);
            unset($this->im);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function decode(?string $bytes): ?GdImage
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        // Inspect the header before allocating. GD needs about 4 bytes per pixel,
        // so a highly compressed but very large image can exhaust memory even
        // though its byte size looked harmless. A fatal error here would happen
        // while holding the card generation lock.
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return null;
        }

        $pixels = (int) ($info[0] ?? 0) * (int) ($info[1] ?? 0);
        $ceiling = max(1, (int) config('link_preview.max_source_megapixels', 40)) * 1_000_000;
        if ($pixels <= 0 || $pixels > $ceiling) {
            Log::debug('LinkPreview: source image rejected', [
                'width' => $info[0] ?? null,
                'height' => $info[1] ?? null,
                'megapixel_ceiling' => $ceiling / 1_000_000,
            ]);

            return null;
        }

        $image = @imagecreatefromstring($bytes);

        return $image === false ? null : $image;
    }

    /**
     * Composite a layer that carries per-pixel alpha, scaled by a global
     * opacity. imagecopymerge cannot do this (it discards the alpha channel),
     * so the layer's own alpha is pre-multiplied by the global opacity first.
     */
    private function compositeLayer(GdImage $layer, int $x, int $y, float $opacity): void
    {
        $w = imagesx($layer);
        $h = imagesy($layer);
        $opacity = max(0.0, min(1.0, $opacity));

        if ($opacity < 1.0) {
            imagealphablending($layer, false);
            for ($py = 0; $py < $h; $py++) {
                for ($px = 0; $px < $w; $px++) {
                    $rgba = imagecolorat($layer, $px, $py);
                    $alpha = ($rgba >> 24) & 0x7F;
                    if ($alpha === 127) {
                        continue;
                    }
                    $scaled = (int) round(127 - (127 - $alpha) * $opacity);
                    imagesetpixel(
                        $layer,
                        $px,
                        $py,
                        ($scaled << 24) | ($rgba & 0x00FFFFFF)
                    );
                }
            }
        }

        imagealphablending($this->im, true);
        imagecopy($this->im, $layer, $x, $y, 0, 0, $w, $h);
    }

    private function color(string $hex, float $opacity): int
    {
        [$r, $g, $b] = $this->rgb($hex);
        $alpha = (int) round((1 - max(0.0, min(1.0, $opacity))) * 127);

        return imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
