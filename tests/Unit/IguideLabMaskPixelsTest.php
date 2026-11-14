<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabMaskPixels;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IguideLabMaskPixelsTest extends TestCase
{
    private function png(int $color, array $pixels, bool $section = true, int $filter = 0, int $width = 2048, ?int $height = null): string
    {
        $height ??= $section ? 2048 : 1024;
        $channels = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$color];
        $default = str_repeat("\0", $channels - (in_array($color, [4, 6], true) ? 1 : 0)).(in_array($color, [4, 6], true) ? "\xff" : '');
        $rowDefault = str_repeat($default, $width); $rows = ''; $previous = str_repeat("\0", $width * $channels);
        for ($y = 0; $y < $height; $y++) {
            $row = $rowDefault;
            foreach ($pixels[$y] ?? [] as $x => $pixel) $row = substr_replace($row, $pixel, $x * $channels, $channels);
            // Up filtering exercises dependent-row decoding with sparse masks.
            $encoded = $row;
            if ($filter === 2) for ($i = 0; $i < strlen($row); $i++) $encoded[$i] = chr((ord($row[$i]) - ord($previous[$i])) & 255);
            $rows .= chr($filter).$encoded; $previous = $row;
        }
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $color, 0, 0, 0)).$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');
    }

    /** Normalized output intentionally uses filter0, so exact8-bit alpha can be verified without GD loss. */
    private function decodedRows(string $png): string
    {
        $data = '';
        for ($offset = 8; $offset < strlen($png);) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            if (substr($png, $offset + 4, 4) === 'IDAT') $data .= substr($png, $offset + 8, $length);
            $offset += $length + 12;
        }
        return zlib_decode($data);
    }

    public function test_preserves_every_soft_value_and_emits_exact_inverse_provider_alpha(): void
    {
        $values = [1, 127, 128, 254, 255]; $pixels = [];
        foreach ($values as $i => $value) $pixels[1000][1000 + $i] = chr($value);
        $result = LabMaskPixels::normalize($this->png(0, $pixels, filter: 2), 2048, 2048, true);
        $gray = $this->decodedRows($result['png']); $provider = $this->decodedRows($result['providerPng']);
        $this->assertSame(0, ord($result['png'][25]));
        $this->assertSame(6, ord($result['providerPng'][25]));
        foreach ($values as $i => $value) {
            $this->assertSame($value, ord($gray[1000 * 2049 + 1 + 1000 + $i]));
            $this->assertSame(255 - $value, ord($provider[1000 * 8193 + 1 + (1000 + $i) * 4 + 3]));
        }
        $this->assertSame(255, ord($provider[4]));
        $this->assertSame(['editablePixels' => 5, 'softPixels' => 4, 'protectedPixels' => 4194299], $result['statistics']);
    }

    public function test_equal_rgb_grass_preserves_soft_seam_pixels_without_provider_mask(): void
    {
        $result = LabMaskPixels::normalize($this->png(2, [0 => [0 => "\x01\x01\x01"], 1023 => [2047 => "\xfe\xfe\xfe"]], false), 2048, 1024, false);
        $this->assertNull($result['providerPng']);
        $this->assertSame(['editablePixels' => 2, 'softPixels' => 2, 'protectedPixels' => 2097150], $result['statistics']);
        $rows = $this->decodedRows($result['png']);
        $this->assertSame(1, ord($rows[1])); $this->assertSame(254, ord($rows[1023 * 2049 + 2048]));
    }

    public function test_accepts_opaque_rgba_and_the_first_safe_border_pixel(): void
    {
        $result = LabMaskPixels::normalize($this->png(6, [205 => [205 => "\x80\x80\x80\xff"]]), 2048, 2048, true);
        $this->assertSame(1, $result['statistics']['editablePixels']);
    }

    public function test_real_rgba_encoder_filters_preserve_every_coverage_byte(): void
    {
        // Independently encoded with Pillow: Sub, Up and Paeth rows, opaque RGBA.
        // Center384-square coverage is ((x + 3*y) %255)+1; all other coverage is zero.
        // Golden decoded row hashes were computed from the pre-encoded numpy arrays.
        $png = file_get_contents(__DIR__.'/../Fixtures/iguide-lab/mask-opaque-rgba.png');
        $result = LabMaskPixels::normalize($png, 2048, 2048, true);
        $this->assertSame(['editablePixels' => 147456, 'softPixels' => 146898, 'protectedPixels' => 4046848], $result['statistics']);
        $this->assertSame('93918595c27ac38f22920f9e4ebbe81e70de0168b8722ca9c92e19564de4d50e', hash('sha256', $this->decodedRows($result['png'])));
        $this->assertSame('9cf1034d98937388bec91c821cb621c052c9347aef6347a5a4e5ac0b79882b9d', hash('sha256', $this->decodedRows($result['providerPng'])));
    }

    public static function invalidPixels(): array
    {
        return [
            'colored' => [2, [1000 => [1000 => "\xff\xfe\xff"]]],
            'almost opaque rgba' => [6, [1000 => [1000 => "\x80\x80\x80\xfe"]]],
            'transparent grayscale alpha' => [4, [1000 => [1000 => "\x80\0"]]],
            'top edge soft pixel' => [0, [204 => [1000 => "\x01"]]],
            'right edge soft pixel' => [0, [1000 => [1843 => "\x01"]]],
            'empty' => [0, []],
        ];
    }

    #[DataProvider('invalidPixels')]
    public function test_rejects_unsafe_masks(int $color, array $pixels): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabMaskPixels::normalize($this->png($color, $pixels), 2048, 2048, true);
    }

    public function test_rejects_wrong_dimensions_before_decoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabMaskPixels::normalize($this->png(0, [], width: 32, height: 32), 2048, 2048, true);
    }

    public function test_rejects_corrupt_crc(): void
    {
        $png = $this->png(0, [1000 => [1000 => "\xff"]]); $png[29] = chr(ord($png[29]) ^ 1);
        $this->expectException(InvalidArgumentException::class);
        LabMaskPixels::normalize($png, 2048, 2048, true);
    }

    public function test_rejects_oversize_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LabMaskPixels::normalize("\x89PNG\r\n\x1a\n".str_repeat('x', 8 * 1024 * 1024), 2048, 2048, true);
    }
}
