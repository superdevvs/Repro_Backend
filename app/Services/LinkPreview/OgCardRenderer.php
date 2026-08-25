<?php

namespace App\Services\LinkPreview;

use Illuminate\Support\Facades\Log;

/**
 * Draws a PreviewPayload as a 1200x630 JPEG.
 *
 * One method per approved design. They share the same vocabulary - cover-cropped
 * hero, gradient scrim, tracked all-caps chip, address block, stat chips - so a
 * feed containing an agent's photo, video and 3D links for the same address
 * reads as one family while still telling the three apart at a glance.
 *
 * Every design assumes its inputs may be missing. Missing price, missing stats,
 * missing floorplan and missing photos are all normal, so nothing here throws
 * when a shoot is sparse; it just draws less.
 */
class OgCardRenderer
{
    private const INK = '#04080e';

    public function __construct(
        private readonly FontRepository $fonts,
        private readonly ImageSourceLoader $images,
    ) {
    }

    public function render(PreviewPayload $payload): string
    {
        $width = (int) config('link_preview.card.width', 1200);
        $height = (int) config('link_preview.card.height', 630);

        $canvas = new CardCanvas($width, $height, (string) config('link_preview.palette.ink', '#060a0e'));

        try {
            match ($payload->design) {
                'd4' => $this->drawGalleryMosaic($canvas, $payload),
                'd5' => $this->drawCinematicVideo($canvas, $payload),
                'd6' => $this->drawWalkthrough3d($canvas, $payload),
                'd8' => $this->drawBrandCard($canvas, $payload),
                default => $this->drawHeroInfoBar($canvas, $payload),
            };

            return $canvas->toJpeg(
                (int) config('link_preview.card.quality', 82),
                (int) config('link_preview.card.max_bytes', 307200),
                (int) config('link_preview.card.min_quality', 62),
            );
        } finally {
            $canvas->destroy();
        }
    }

    // =================================================================
    // D2 - hero photo + info bar
    // =================================================================

    private function drawHeroInfoBar(CardCanvas $canvas, PreviewPayload $p): void
    {
        $w = $canvas->width();
        $h = $canvas->height();

        if (!$this->paintHero($canvas, $p->hero, 0, 0, $w, $h)) {
            $this->drawBrandCard($canvas, $p);

            return;
        }

        // Scrim across the lower two thirds so the type always has contrast,
        // whatever the photo is doing underneath.
        $scrimH = (int) round($h * 0.64);
        $canvas->verticalGradient(0, $h - $scrimH, $w, $scrimH, self::INK, 0.0, 0.94, 'down', 1.5);

        $this->drawChip($canvas, $p, 36, 32);
        $this->drawWordmark($canvas, $p, $w);

        $priceBox = $this->drawPriceTag($canvas, $p, $w, $h);
        $textRight = $priceBox ? $priceBox['x'] - 28 : $w - 36;

        $this->drawAddressBlock($canvas, $p, 36, $textRight, $h - 44, [
            'address' => 60,
            'city' => 27,
            'stats' => 'chips',
        ]);
    }

    // =================================================================
    // D4 - gallery mosaic
    // =================================================================

    private function drawGalleryMosaic(CardCanvas $canvas, PreviewPayload $p): void
    {
        $w = $canvas->width();
        $h = $canvas->height();
        $gap = 6;
        $railW = 380;
        $heroW = $w - $railW - $gap;

        if (!$this->paintHero($canvas, $p->hero, 0, 0, $heroW, $h)) {
            $this->drawHeroInfoBar($canvas, $p);

            return;
        }

        // Supporting rail: three tiles, the last one carrying the remaining
        // count. When the shoot has a floorplan it takes the final slot, so the
        // tiles actually evidence the "FLOOR PLANS" claim in the chip.
        $tiles = array_slice($p->gallery, 0, 3);
        if ($p->floorplan) {
            $tiles = array_slice($p->gallery, 0, 2);
            $tiles[2] = $p->floorplan;
        }

        $tileH = (int) floor(($h - $gap * 2) / 3);
        for ($i = 0; $i < 3; $i++) {
            $ty = $i * ($tileH + $gap);
            $th = $i === 2 ? $h - $ty : $tileH;
            $source = $tiles[$i] ?? null;

            if (!$this->paintHero($canvas, $source, $heroW + $gap, $ty, $railW, $th)) {
                $canvas->fillRect($heroW + $gap, $ty, $railW, $th, '#101722', 1.0);
            }
        }

        // Remaining-photo count on the last tile.
        $remaining = $p->photoCount - (1 + min(count($p->gallery), 3));
        if ($remaining > 0) {
            $ty = 2 * ($tileH + $gap);
            $th = $h - $ty;
            $canvas->fillRect($heroW + $gap, $ty, $railW, $th, '#060a0e', 0.72);
            $cx = $heroW + $gap + (int) round($railW / 2);
            $canvas->text('+' . $remaining, $cx, $ty + (int) round($th / 2) - 8, $this->fonts->extrabold(), 44, '#ffffff', 1.0, 'center', 'middle');
            $canvas->textTracked('MORE PHOTOS', $cx, $ty + (int) round($th / 2) + 30, $this->fonts->bold(), 12, '#ffffff', 1.6, 0.70, 'center', 'middle');
        }

        $canvas->verticalGradient(0, (int) round($h * 0.45), $heroW, (int) round($h * 0.55), self::INK, 0.0, 0.92, 'down', 1.5);

        $this->drawChip($canvas, $p, 36, 32);

        $this->drawAddressBlock($canvas, $p, 36, $heroW - 36, $h - 38, [
            'address' => 52,
            'city' => 23,
            'stats' => 'inline',
        ]);
    }

    // =================================================================
    // D5 - cinematic video
    // =================================================================

    private function drawCinematicVideo(CardCanvas $canvas, PreviewPayload $p): void
    {
        $w = $canvas->width();
        $h = $canvas->height();

        if (!$this->paintHero($canvas, $p->hero, 0, 0, $w, $h)) {
            $this->drawBrandCard($canvas, $p);

            return;
        }

        // Vignette: a flat knock-back plus edge gradients. Reads as cinematic
        // and, more usefully, guarantees the white play button has contrast on
        // a bright sky or a snow-covered lawn.
        $canvas->fillRect(0, 0, $w, $h, self::INK, 0.32);
        $canvas->verticalGradient(0, 0, $w, (int) round($h * 0.30), self::INK, 0.42, 0.0, 'down', 1.2);
        $canvas->verticalGradient(0, (int) round($h * 0.42), $w, (int) round($h * 0.58), self::INK, 0.0, 0.90, 'down', 1.4);

        $this->drawChip($canvas, $p, 36, 32);
        $this->drawWordmark($canvas, $p, $w);

        // Play affordance: people expect to be able to press a video link.
        $cx = (int) round($w / 2);
        $cy = (int) round($h * 0.40);
        $canvas->circle($cx, $cy, 66, '#ffffff', 0.16);
        $canvas->ring($cx, $cy, 66, 3, '#ffffff', 0.88);
        // Nudged right of centre: a triangle's visual mass sits left of its
        // bounding box, so a geometrically centred play glyph reads off-centre.
        $canvas->polygon([
            [$cx - 14, $cy - 27],
            [$cx + 29, $cy],
            [$cx - 14, $cy + 27],
        ], '#ffffff', 1.0);

        // Centred type, since the composition is symmetrical around the button.
        $bottom = $h - 42;
        $pillsH = 42;
        $hasPills = $p->stats !== [] || $p->price !== null;
        $pillsTop = $bottom - $pillsH;

        $cityFont = $this->fonts->medium();
        $cityH = 32;
        $cityTop = ($hasPills ? $pillsTop - 20 : $bottom) - $cityH;

        $addrFont = $this->fonts->extrabold();
        $addrSize = $this->fonts->fitSize((string) $p->addressLine, $addrFont, 56, $w - 140, 34);
        $addrTop = $cityTop - 12 - (int) round($addrSize * 1.05);

        if ($p->addressLine) {
            $canvas->text($p->addressLine, $cx, $addrTop, $addrFont, $addrSize, '#ffffff', 1.0, 'center', 'top');
        }
        if ($p->cityLine) {
            $canvas->text($p->cityLine, $cx, $cityTop, $cityFont, 24, '#ffffff', 0.86, 'center', 'top');
        }

        if ($hasPills) {
            $this->drawCentredPills($canvas, $p, $cx, $pillsTop, $pillsH);
        }
    }

    // =================================================================
    // D6 - 3D walkthrough
    // =================================================================

    private function drawWalkthrough3d(CardCanvas $canvas, PreviewPayload $p): void
    {
        $w = $canvas->width();
        $h = $canvas->height();

        if (!$this->paintHero($canvas, $p->hero, 0, 0, $w, $h)) {
            $this->drawBrandCard($canvas, $p);

            return;
        }

        $canvas->verticalGradient(0, 0, $w, (int) round($h * 0.30), self::INK, 0.30, 0.0, 'down', 1.2);
        $canvas->verticalGradient(0, (int) round($h * 0.40), $w, (int) round($h * 0.60), self::INK, 0.0, 0.92, 'down', 1.45);

        $this->drawChip($canvas, $p, 36, 32);
        $this->drawWordmark($canvas, $p, $w);

        // Isometric cube: distinguishes a 3D link from the photo and video
        // links for the same address at thumbnail size.
        $cx = (int) round($w / 2);
        $cy = (int) round($h * 0.36);
        $canvas->circle($cx, $cy, 60, (string) config('link_preview.palette.tour3d', '#7c3aed'), 0.34);
        $canvas->ring($cx, $cy, 60, 3, '#ffffff', 0.85);
        $this->drawCubeGlyph($canvas, $cx, $cy, 30);

        // Floorplan inset, when the shoot has one.
        $insetRight = $w - 34;
        $textRight = $w - 36;
        if ($p->floorplan) {
            $iw = 214;
            $ih = 150;
            $ix = $insetRight - $iw;
            $iy = $h - 34 - $ih;
            $canvas->fillRect($ix - 3, $iy - 3, $iw + 6, $ih + 6, '#ffffff', 0.90);
            if ($this->paintHero($canvas, $p->floorplan, $ix, $iy, $iw, $ih)) {
                $canvas->fillRect($ix, $iy + $ih - 30, $iw, 30, '#060a0e', 0.78);
                $canvas->textTracked('FLOOR PLAN', $ix + 12, $iy + $ih - 15, $this->fonts->bold(), 11, '#ffffff', 1.4, 1.0, 'left', 'middle');
                $textRight = $ix - 28;
            }
        }

        $this->drawAddressBlock($canvas, $p, 36, $textRight, $h - 40, [
            'address' => 54,
            'city' => 23,
            'stats' => 'inline',
        ]);
    }

    // =================================================================
    // D8 - brand card (also the universal fallback)
    // =================================================================

    private function drawBrandCard(CardCanvas $canvas, PreviewPayload $p): void
    {
        if (! $p->branded && ! in_array($p->type, ['dashboard', 'portal'], true)) {
            $this->drawNeutralFallbackCard($canvas, $p);

            return;
        }

        $w = $canvas->width();
        $h = $canvas->height();
        $stripH = 132;

        // A darkened hero keeps the card photography-led even with no property
        // to show. Plain background when there is nothing to draw.
        $painted = $this->paintHero($canvas, $p->hero, 0, 0, $w, $h);
        $canvas->fillRect(0, 0, $w, $h, '#060a0e', $painted ? 0.955 : 1.0);

        // Glows echoing the logo's red and blue.
        $canvas->radialGlow(170, 150, 310, (string) config('link_preview.palette.red', '#c8102e'), 0.30);
        $canvas->radialGlow(980, 490, 340, (string) config('link_preview.palette.blue', '#0b6bc9'), 0.34);

        // Photo strip, full bleed along the bottom.
        $strip = array_values(array_filter($p->gallery));
        if ($strip !== []) {
            $count = min(count($strip), 5);
            $gap = 3;
            $tileW = (int) floor(($w - $gap * ($count - 1)) / $count);
            for ($i = 0; $i < $count; $i++) {
                $tx = $i * ($tileW + $gap);
                $tw = $i === $count - 1 ? $w - $tx : $tileW;
                if (!$this->paintHero($canvas, $strip[$i], $tx, $h - $stripH, $tw, $stripH)) {
                    $canvas->fillRect($tx, $h - $stripH, $tw, $stripH, '#101722', 1.0);
                }
            }
            $canvas->fillRect(0, $h - $stripH - 1, $w, 1, '#ffffff', 0.14);
        } else {
            $stripH = 0;
        }

        // Two compositions. With a photo strip the block sits left-aligned above
        // it, matching the property cards. Without one there is nothing to
        // balance against, so the card becomes a centred title card rather than
        // a left-aligned block floating in empty space.
        $centred = $stripH === 0;

        $left = 74;
        // A title card centres in the whole frame; the strip variant centres in
        // the space left above the strip.
        $contentBottom = $stripH > 0 ? $h - $stripH - 30 : $h;
        $maxTextW = $centred ? min(880, $w - 160) : min(780, $w - $left * 2);
        $anchorX = $centred ? (int) round($w / 2) : $left;
        $align = $centred ? 'center' : 'left';

        $logoH = $centred ? 104 : 92;
        $ruleGapTop = 30;
        $ruleH = 4;
        $ruleW = 248;
        $ruleGapBottom = 28;
        $headSize = 48.0;
        $subSize = 23.0;

        $headline = (string) ($p->headline ?? '');
        $subLines = $this->fonts->wrapToLines((string) ($p->subhead ?? ''), $this->fonts->medium(), $subSize, $maxTextW, 3);

        if ($headline !== '') {
            $headSize = $this->fonts->fitSize($headline, $this->fonts->extrabold(), $headSize, $maxTextW, 30);
        }

        $headH = $headline !== '' ? (int) round($headSize * 1.1) : 0;
        $subH = count($subLines) * (int) round($subSize * 1.5);

        $serviceLine = 'PHOTOGRAPHY  -  VIDEO  -  3D TOURS  -  FLOOR PLANS';
        $serviceH = $centred ? 42 : 0;

        $blockH = $logoH + $ruleGapTop + $ruleH + $ruleGapBottom
            + $headH
            + ($subLines ? 14 + $subH : 0)
            + $serviceH;

        $top = max(40, (int) round(($contentBottom - $blockH) / 2));

        $logoBox = $centred
            ? $canvas->drawImageContain($this->logoBytes(true), (int) round(($w - 460) / 2), $top, 460, $logoH, 'center-top')
            : $canvas->drawImageContain($this->logoBytes(true), $left, $top, 420, $logoH);
        $cursor = $top + ($logoBox['height'] ?? $logoH);

        $cursor += $ruleGapTop;
        $this->drawBrandRule($canvas, $centred ? $anchorX - (int) round($ruleW / 2) : $left, $cursor, $ruleW, $ruleH);
        $cursor += $ruleH + $ruleGapBottom;

        if ($headline !== '') {
            $canvas->text($headline, $anchorX, $cursor, $this->fonts->extrabold(), $headSize, '#ffffff', 1.0, $align, 'top');
            $cursor += $headH;
        }

        if ($subLines !== []) {
            $cursor += 14;
            foreach ($subLines as $line) {
                $canvas->text($line, $anchorX, $cursor, $this->fonts->medium(), $subSize, '#ffffff', 0.70, $align, 'top');
                $cursor += (int) round($subSize * 1.5);
            }
        }

        if ($centred) {
            $canvas->textTracked($serviceLine, $anchorX, $cursor + 22, $this->fonts->bold(), 13, '#ffffff', 2.4, 0.46, 'center', 'top');
        } else {
            $canvas->textTracked($serviceLine, $w - $left, $h - $stripH - 30, $this->fonts->bold(), 13, '#ffffff', 2.4, 0.46, 'right', 'middle');
        }
    }

    /**
     * Compliance-safe fallback for MLS/generic links. It deliberately contains
     * no REPRO logo, brand palette, company copy, or service advertising.
     */
    private function drawNeutralFallbackCard(CardCanvas $canvas, PreviewPayload $p): void
    {
        $w = $canvas->width();
        $h = $canvas->height();
        $painted = $this->paintHero($canvas, $p->hero, 0, 0, $w, $h);

        $canvas->fillRect(0, 0, $w, $h, '#080d14', $painted ? 0.88 : 1.0);
        $canvas->radialGlow((int) ($w * 0.78), (int) ($h * 0.28), 330, '#64748b', 0.20);

        $strip = array_values(array_filter($p->gallery));
        $stripH = $strip === [] ? 0 : 126;
        if ($stripH > 0) {
            $count = min(5, count($strip));
            $gap = 3;
            $tileW = (int) floor(($w - $gap * ($count - 1)) / $count);
            for ($i = 0; $i < $count; $i++) {
                $x = $i * ($tileW + $gap);
                $width = $i === $count - 1 ? $w - $x : $tileW;
                if (! $this->paintHero($canvas, $strip[$i], $x, $h - $stripH, $width, $stripH)) {
                    $canvas->fillRect($x, $h - $stripH, $width, $stripH, '#111827', 1.0);
                }
            }
            $canvas->fillRect(0, $h - $stripH - 1, $w, 1, '#ffffff', 0.16);
        }

        $contentBottom = $h - $stripH;
        $left = 68;
        $maxWidth = $w - $left * 2;
        $headline = trim((string) ($p->headline ?: $p->addressLine ?: 'Property Tour'));
        $subhead = trim((string) ($p->subhead ?: 'View the property details and available media.'));

        $canvas->textTracked('PROPERTY TOUR', $left, 62, $this->fonts->bold(), 14, '#ffffff', 2.2, 0.78, 'left', 'top');
        $canvas->fillRect($left, 104, 220, 4, '#ffffff', 0.68);

        $headlineSize = $this->fonts->fitSize($headline, $this->fonts->extrabold(), 56, $maxWidth, 32);
        $headlineY = max(150, (int) round(($contentBottom - 160) / 2));
        $canvas->text($headline, $left, $headlineY, $this->fonts->extrabold(), $headlineSize, '#ffffff', 1.0, 'left', 'top');

        $subLines = $this->fonts->wrapToLines($subhead, $this->fonts->medium(), 23, min(820, $maxWidth), 3);
        $cursor = $headlineY + (int) round($headlineSize * 1.2) + 16;
        foreach ($subLines as $line) {
            $canvas->text($line, $left, $cursor, $this->fonts->medium(), 23, '#ffffff', 0.72, 'left', 'top');
            $cursor += 34;
        }
    }

    // =================================================================
    // Shared pieces
    // =================================================================

    private function paintHero(CardCanvas $canvas, ?string $source, int $x, int $y, int $w, int $h): bool
    {
        $bytes = $this->images->load($source);
        if ($bytes === null) {
            return false;
        }

        return $canvas->drawImageCover($bytes, $x, $y, $w, $h);
    }

    /**
     * All-caps category chip. Sized to its label rather than fixed width, so
     * "VIRTUAL TOUR" and "24 PHOTOS - FLOOR PLANS" both sit correctly.
     */
    private function drawChip(CardCanvas $canvas, PreviewPayload $p, int $x, int $y): void
    {
        $label = $p->chipLabel;
        if (!$label) {
            return;
        }

        $font = $this->fonts->bold();
        $size = 13.0;
        $tracking = 1.4;
        $padX = 16;
        $chipH = 36;
        $textW = $canvas->trackedWidth($label, $font, $size, $tracking);

        // Unbranded cards get a neutral translucent chip; branded cards get the
        // accent colour. Keeps MLS variants free of anything that reads as
        // promotion.
        if ($p->branded) {
            $canvas->roundedRect($x, $y, $textW + $padX * 2, $chipH, 7, $p->chipColor ?? '#1463ff', 1.0);
        } else {
            $canvas->roundedRect($x, $y, $textW + $padX * 2, $chipH, 6, '#060a0e', 0.62, '#ffffff', 0.22, 1);
        }

        $canvas->textTracked($label, $x + $padX, $y + (int) round($chipH / 2), $font, $size, '#ffffff', $tracking, 1.0, 'left', 'middle');
    }

    /**
     * REPRO wordmark, branded links only.
     *
     * Deliberately absent on MLS/unbranded cards: those exist so the tour can
     * be posted where agent and brokerage identity is not allowed, and a
     * photographer's mark is the same class of thing.
     */
    private function drawWordmark(CardCanvas $canvas, PreviewPayload $p, int $canvasWidth): void
    {
        if (!$p->branded) {
            return;
        }

        $bytes = $this->logoBytes(true);
        if ($bytes === null) {
            return;
        }

        $logoH = 38;
        $padX = 20;
        $padY = 12;
        $maxLogoW = 220;

        // Measure first so the plate fits the mark exactly.
        $probe = @imagecreatefromstring($bytes);
        if ($probe === false) {
            return;
        }
        $scale = min($maxLogoW / imagesx($probe), $logoH / imagesy($probe));
        $logoW = max(1, (int) round(imagesx($probe) * $scale));
        $logoH = max(1, (int) round(imagesy($probe) * $scale));
        imagedestroy($probe);

        $plateW = $logoW + $padX * 2;
        $plateH = $logoH + $padY * 2;
        $x = $canvasWidth - 30 - $plateW;
        $y = 26;

        $canvas->roundedRect($x, $y, $plateW, $plateH, 12, '#060a0e', 0.68, '#ffffff', 0.20, 1);
        $canvas->drawImageContain($bytes, $x + $padX, $y + $padY, $logoW, $logoH);
    }

    /**
     * @param  array{address: int, city: int, stats: string}  $sizes
     */
    private function drawAddressBlock(CardCanvas $canvas, PreviewPayload $p, int $left, int $right, int $bottom, array $sizes): void
    {
        $maxW = max(120, $right - $left);

        $addrFont = $this->fonts->extrabold();
        $cityFont = $this->fonts->medium();

        $addrSize = $this->fonts->fitSize((string) $p->addressLine, $addrFont, (float) $sizes['address'], $maxW, 30);
        $address = $this->fonts->truncateToWidth((string) $p->addressLine, $addrFont, $addrSize, $maxW);

        $cityParts = array_filter([$p->cityLine]);
        if ($sizes['stats'] === 'inline') {
            $inline = $this->inlineStats($p);
            if ($inline !== '') {
                $cityParts[] = $inline;
            }
        }
        $cityText = implode('  -  ', $cityParts);

        // Shrink the secondary line before resorting to an ellipsis: losing the
        // last stat reads worse than setting the whole line a couple of points
        // smaller.
        $citySize = $cityText === ''
            ? (float) $sizes['city']
            : $this->fonts->fitSize($cityText, $cityFont, (float) $sizes['city'], $maxW, max(16.0, $sizes['city'] - 7));

        $addrH = (int) round($addrSize * 1.05);
        $cityH = $cityText !== '' ? (int) round($citySize * 1.3) : 0;

        $statsH = 0;
        $statsGap = 0;
        if ($sizes['stats'] === 'chips' && $p->stats !== []) {
            $statsH = 66;
            $statsGap = 22;
        }

        $blockH = $addrH + ($cityH ? 12 + $cityH : 0) + ($statsH ? $statsGap + $statsH : 0);
        $top = $bottom - $blockH;

        if ($address !== '') {
            $canvas->text($address, $left, $top, $addrFont, $addrSize, '#ffffff', 1.0, 'left', 'top');
        }

        if ($cityH) {
            $cityText = $this->fonts->truncateToWidth($cityText, $cityFont, $citySize, $maxW);
            $canvas->text($cityText, $left, $top + $addrH + 12, $cityFont, $citySize, '#ffffff', 0.86, 'left', 'top');
        }

        if ($statsH) {
            $this->drawStatChips($canvas, $p, $left, $top + $addrH + ($cityH ? 12 + $cityH : 0) + $statsGap, $maxW);
        }
    }

    private function drawStatChips(CardCanvas $canvas, PreviewPayload $p, int $x, int $y, int $maxW): void
    {
        $valueFont = $this->fonts->extrabold();
        $labelFont = $this->fonts->bold();
        $gap = 10;
        $padX = 18;
        $chipH = 66;
        $cursor = $x;

        foreach ($p->stats as $stat) {
            $valueW = $this->fonts->textWidth($stat['value'], $valueFont, 26);
            $labelW = $canvas->trackedWidth($stat['label'], $labelFont, 11, 1.5);
            $chipW = max($valueW, $labelW) + $padX * 2;

            if ($cursor + $chipW > $x + $maxW) {
                break;
            }

            $canvas->roundedRect($cursor, $y, $chipW, $chipH, 10, '#ffffff', 0.13, '#ffffff', 0.22, 1);
            $canvas->text($stat['value'], $cursor + (int) round($chipW / 2), $y + 27, $valueFont, 26, '#ffffff', 1.0, 'center', 'middle');
            $canvas->textTracked($stat['label'], $cursor + (int) round($chipW / 2), $y + 50, $labelFont, 11, '#ffffff', 1.5, 0.66, 'center', 'middle');

            $cursor += $chipW + $gap;
        }
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function drawPriceTag(CardCanvas $canvas, PreviewPayload $p, int $canvasWidth, int $canvasHeight): ?array
    {
        if (!$p->price) {
            return null;
        }

        $font = $this->fonts->extrabold();
        $size = 40.0;
        $padX = 26;
        $padY = 18;

        $textW = $this->fonts->textWidth($p->price, $font, $size);
        $boxW = $textW + $padX * 2;
        $boxH = (int) round($size) + $padY * 2;
        $x = $canvasWidth - 36 - $boxW;
        $y = $canvasHeight - 44 - $boxH;

        $canvas->roundedRect($x, $y, $boxW, $boxH, 12, '#ffffff', 1.0);
        $canvas->text($p->price, $x + (int) round($boxW / 2), $y + (int) round($boxH / 2), $font, $size, '#0f172a', 1.0, 'center', 'middle');

        return ['x' => $x, 'y' => $y, 'width' => $boxW, 'height' => $boxH];
    }

    private function drawCentredPills(CardCanvas $canvas, PreviewPayload $p, int $cx, int $y, int $pillH): void
    {
        $font = $this->fonts->bold();
        $size = 17.0;
        $padX = 16;
        $gap = 12;

        $pills = [];
        $inline = $this->inlineStats($p);
        if ($inline !== '') {
            $pills[] = ['text' => $inline, 'light' => false];
        }
        if ($p->price) {
            $pills[] = ['text' => $p->price, 'light' => true];
        }
        if ($pills === []) {
            return;
        }

        $widths = [];
        $total = 0;
        foreach ($pills as $i => $pill) {
            $widths[$i] = $this->fonts->textWidth($pill['text'], $font, $size) + $padX * 2;
            $total += $widths[$i];
        }
        $total += $gap * (count($pills) - 1);

        $cursor = $cx - (int) round($total / 2);
        foreach ($pills as $i => $pill) {
            $pw = $widths[$i];
            if ($pill['light']) {
                $canvas->roundedRect($cursor, $y, $pw, $pillH, 8, '#ffffff', 1.0);
                $canvas->text($pill['text'], $cursor + (int) round($pw / 2), $y + (int) round($pillH / 2), $font, $size, '#0f172a', 1.0, 'center', 'middle');
            } else {
                $canvas->roundedRect($cursor, $y, $pw, $pillH, 8, '#000000', 0.55, '#ffffff', 0.24, 1);
                $canvas->text($pill['text'], $cursor + (int) round($pw / 2), $y + (int) round($pillH / 2), $font, $size, '#ffffff', 1.0, 'center', 'middle');
            }
            $cursor += $pw + $gap;
        }
    }

    private function drawBrandRule(CardCanvas $canvas, int $x, int $y, int $width, int $height): void
    {
        // Red-to-blue sweep taken from the logo, approximated in vertical bands.
        $red = $this->hexToRgb((string) config('link_preview.palette.red', '#c8102e'));
        $blue = $this->hexToRgb((string) config('link_preview.palette.blue', '#0b6bc9'));

        for ($i = 0; $i < $width; $i++) {
            $t = $width <= 1 ? 0 : $i / ($width - 1);
            $hex = sprintf(
                '#%02x%02x%02x',
                (int) round($red[0] + ($blue[0] - $red[0]) * $t),
                (int) round($red[1] + ($blue[1] - $red[1]) * $t),
                (int) round($red[2] + ($blue[2] - $red[2]) * $t),
            );
            $canvas->fillRect($x + $i, $y, 1, $height, $hex, 1.0);
        }
    }

    private function drawCubeGlyph(CardCanvas $canvas, int $cx, int $cy, int $r): void
    {
        // Flat-top hexagon outline with the three visible interior edges: the
        // conventional shorthand for a 3D/dollhouse view.
        $points = [];
        for ($i = 0; $i < 6; $i++) {
            $angle = deg2rad(60 * $i - 90);
            $points[] = [
                (int) round($cx + $r * cos($angle)),
                (int) round($cy + $r * sin($angle)),
            ];
        }

        $gd = $canvas->native();
        $white = imagecolorallocate($gd, 255, 255, 255);
        imagesetthickness($gd, 3);

        for ($i = 0; $i < 6; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % 6];
            imageline($gd, $a[0], $a[1], $b[0], $b[1], $white);
        }

        // Interior: centre out to the top, lower-left and lower-right vertices.
        foreach ([0, 2, 4] as $vertex) {
            imageline($gd, $cx, $cy, $points[$vertex][0], $points[$vertex][1], $white);
        }

        imagesetthickness($gd, 1);
    }

    private function inlineStats(PreviewPayload $p): string
    {
        $map = ['BEDS' => 'bd', 'BATHS' => 'ba', 'SQ FT' => 'sqft'];
        $out = [];

        foreach ($p->stats as $stat) {
            if (isset($map[$stat['label']])) {
                $out[] = $stat['value'] . ' ' . $map[$stat['label']];
            }
        }

        return implode('  -  ', $out);
    }

    private function logoBytes(bool $light): ?string
    {
        static $cache = [];
        $key = $light ? 'light' : 'dark';

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $relative = (string) config('link_preview.assets.' . ($light ? 'logo_light' : 'logo_dark'));
        $path = public_path($relative);

        if (!is_file($path)) {
            Log::warning('LinkPreview: brand logo asset missing', ['path' => $path]);

            return $cache[$key] = null;
        }

        return $cache[$key] = (string) file_get_contents($path);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
