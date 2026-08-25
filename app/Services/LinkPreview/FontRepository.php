<?php

namespace App\Services\LinkPreview;

use Illuminate\Support\Facades\Log;

/**
 * Resolves the TrueType files the card renderer draws with, and measures text.
 *
 * GD needs real .ttf files: the @fontsource packages the frontend uses ship
 * woff2 only, which FreeType cannot read. Inter is therefore bundled as static
 * TTFs under resources/fonts so a generated card looks identical on a developer
 * machine and on the server. If that bundle is ever missing we fall back to a
 * system font rather than failing the render - a card set in DejaVu is worth
 * more than no preview at all.
 */
class FontRepository
{
    /** @var array<string, string|null> */
    private array $resolved = [];

    private ?string $fallback = null;

    public function extrabold(): ?string
    {
        return $this->weight('extrabold');
    }

    public function bold(): ?string
    {
        return $this->weight('bold');
    }

    public function semibold(): ?string
    {
        return $this->weight('semibold');
    }

    public function medium(): ?string
    {
        return $this->weight('medium');
    }

    public function weight(string $name): ?string
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        $filename = config("link_preview.fonts.weights.{$name}");
        $path = $filename ? $this->locate($filename) : null;

        return $this->resolved[$name] = $path ?? $this->systemFallback();
    }

    /**
     * True when the bundled Inter files were found. Callers use this to decide
     * whether metric-dependent layout is trustworthy enough to draw tight
     * chips, or whether to leave extra breathing room for an unknown fallback.
     */
    public function hasBundledFonts(): bool
    {
        $filename = config('link_preview.fonts.weights.bold');

        return $filename !== null && $this->locate($filename) !== null;
    }

    private function locate(string $filename): ?string
    {
        foreach ((array) config('link_preview.fonts.dirs', []) as $dir) {
            $candidate = base_path(trim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function systemFallback(): ?string
    {
        if ($this->fallback !== null) {
            return $this->fallback ?: null;
        }

        foreach ((array) config('link_preview.fonts.fallbacks', []) as $candidate) {
            if (is_file($candidate)) {
                return $this->fallback = $candidate;
            }
        }

        Log::warning('LinkPreview: no usable TrueType font found; card text will not render.', [
            'searched_dirs' => config('link_preview.fonts.dirs'),
            'fallbacks' => config('link_preview.fonts.fallbacks'),
        ]);

        $this->fallback = '';

        return null;
    }

    /**
     * Width in pixels that $text will occupy. GD gives no text wrapping or
     * fitting, so every layout decision in the renderer goes through here.
     */
    public function textWidth(string $text, ?string $fontPath, float $size): int
    {
        if ($text === '' || !$fontPath) {
            return 0;
        }

        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            // Rough estimate rather than a hard failure.
            return (int) round(mb_strlen($text) * $size * 0.55);
        }

        return (int) round(max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * Cap height in pixels, used to centre text inside chips and pills. GD
     * positions text on its baseline, and Intervention's 'middle' valign keys
     * off the full em box (including descender space), which leaves short
     * all-caps labels sitting visibly low in a tight chip.
     */
    public function capHeight(string $text, ?string $fontPath, float $size): int
    {
        if ($text === '' || !$fontPath) {
            return 0;
        }

        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if ($box === false) {
            return (int) round($size * 0.72);
        }

        return (int) round(max($box[1], $box[3]) - min($box[5], $box[7]));
    }

    /**
     * Shorten $text with an ellipsis until it fits $maxWidth.
     */
    public function truncateToWidth(string $text, ?string $fontPath, float $size, int $maxWidth): string
    {
        if ($text === '' || !$fontPath || $maxWidth <= 0) {
            return $text;
        }

        if ($this->textWidth($text, $fontPath, $size) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '...';
        $chars = mb_str_split($text);

        while (count($chars) > 1) {
            array_pop($chars);
            $candidate = rtrim(implode('', $chars), " \t,.-") . $ellipsis;
            if ($this->textWidth($candidate, $fontPath, $size) <= $maxWidth) {
                return $candidate;
            }
        }

        return $ellipsis;
    }

    /**
     * Largest size at or below $preferred at which $text fits $maxWidth.
     * Lets a long address shrink a little before it gets truncated, which
     * reads better than clipping a street name mid-word.
     */
    public function fitSize(string $text, ?string $fontPath, float $preferred, int $maxWidth, float $minimum): float
    {
        if ($text === '' || !$fontPath || $maxWidth <= 0) {
            return $preferred;
        }

        $size = $preferred;
        while ($size > $minimum && $this->textWidth($text, $fontPath, $size) > $maxWidth) {
            $size -= 1;
        }

        return max($size, $minimum);
    }

    /**
     * Wrap $text onto at most $maxLines lines that each fit $maxWidth,
     * ellipsising the final line when the text runs long.
     *
     * @return array<int, string>
     */
    public function wrapToLines(string $text, ?string $fontPath, float $size, int $maxWidth, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || !$fontPath || $maxLines < 1) {
            return $text === '' ? [] : [$text];
        }

        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->textWidth($candidate, $fontPath, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;

            if (count($lines) === $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        if (count($lines) === $maxLines) {
            $consumed = mb_strlen(implode(' ', $lines));
            if ($consumed < mb_strlen($text)) {
                $last = array_pop($lines);
                $lines[] = $this->truncateToWidth($last . ' ...', $fontPath, $size, $maxWidth);
            }
        }

        return $lines;
    }
}
