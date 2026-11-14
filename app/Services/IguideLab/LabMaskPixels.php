<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Mask channels are coverage bytes, not colour. Do not quantize them through GD's 7-bit alpha. */
final class LabMaskPixels
{
    public static function normalize(string $bytes, int $expectedWidth, int $expectedHeight, bool $section): array
    {
        if (strlen($bytes) > 8 * 1024 * 1024 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n"
            || $expectedWidth !== 2048 || $expectedHeight !== ($section ? 2048 : 1024)) {
            throw new InvalidArgumentException('Use a PNG mask within the supported dimensions and 8 MiB limit.');
        }
        $header = null; $compressed = ''; $ended = false; $seenData = false; $closedData = false;
        for ($offset = 8, $size = strlen($bytes); $offset < $size;) {
            if ($offset + 12 > $size) self::invalid();
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($length > $size - $offset - 12) self::invalid();
            $type = substr($bytes, $offset + 4, 4); $data = substr($bytes, $offset + 8, $length);
            if (! preg_match('/^[A-Za-z]{4}$/D', $type) || substr($bytes, $offset + 8 + $length, 4) !== pack('N', crc32($type.$data))) self::invalid();
            if ($header === null && $type !== 'IHDR') self::invalid();
            if (in_array($type, ['tRNS', 'acTL', 'fcTL', 'fdAT'], true)) throw new InvalidArgumentException('Masks must be fully opaque, single-frame PNG images.');
            if ($type === 'IHDR') {
                if ($header !== null || $length !== 13) self::invalid();
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                if ($header['width'] !== $expectedWidth || $header['height'] !== $expectedHeight) throw new InvalidArgumentException('Mask dimensions must match the edit surface exactly.');
                if ($header['depth'] !== 8 || ! in_array($header['color'], [0, 2, 4, 6], true) || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] !== 0) {
                    throw new InvalidArgumentException('Use a non-interlaced 8-bit grayscale or equal-RGB PNG mask.');
                }
            } elseif ($type === 'IDAT') {
                if ($closedData) self::invalid();
                $seenData = true; $compressed .= $data;
            } elseif ($type === 'IEND') {
                if (! $seenData || $length !== 0 || $offset + 12 !== $size) self::invalid();
                $ended = true;
            } else {
                if (ctype_upper($type[0])) self::invalid();
                if ($seenData) $closedData = true;
            }
            $offset += $length + 12;
        }
        if (! $ended || $header === null) self::invalid();
        $channels = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$header['color']];
        $stride = $expectedWidth * $channels; $decodedSize = ($stride + 1) * $expectedHeight;
        $decoded = @zlib_decode($compressed, $decodedSize);
        if (! is_string($decoded) || strlen($decoded) !== $decodedSize) self::invalid();
        $previous = str_repeat("\0", $stride); $grayRows = ''; $providerRows = '';
        $editable = 0; $soft = 0; $border = (int) ceil($expectedWidth * .1);
        for ($y = 0; $y < $expectedHeight; $y++) {
            $base = $y * ($stride + 1); $filter = ord($decoded[$base]);
            if ($filter > 4) self::invalid();
            $row = substr($decoded, $base + 1, $stride);
            if ($filter !== 0) {
                for ($i = 0; $i < $stride; $i++) {
                    $left = $i >= $channels ? ord($row[$i - $channels]) : 0;
                    $up = ord($previous[$i]); $upperLeft = $i >= $channels ? ord($previous[$i - $channels]) : 0;
                    $predictor = match ($filter) { 1 => $left, 2 => $up, 3 => intdiv($left + $up, 2), 4 => self::paeth($left, $up, $upperLeft) };
                    $row[$i] = chr((ord($row[$i]) + $predictor) & 255);
                }
            }
            $grayRows .= "\0"; if ($section) $providerRows .= "\0";
            for ($x = 0; $x < $expectedWidth; $x++) {
                $i = $x * $channels; $value = ord($row[$i]);
                if (in_array($header['color'], [2, 6], true) && ($value !== ord($row[$i + 1]) || $value !== ord($row[$i + 2]))) {
                    throw new InvalidArgumentException('Mask RGB channels must be equal grayscale values.');
                }
                if (in_array($header['color'], [4, 6], true) && ord($row[$i + $channels - 1]) !== 255) {
                    throw new InvalidArgumentException('Input masks must be fully opaque. Use grayscale to express edit coverage.');
                }
                if ($value > 0) {
                    if ($section && ($x < $border || $y < $border || $x >= $expectedWidth - $border || $y >= $expectedHeight - $border)) {
                        throw new InvalidArgumentException('Keep the outer 10 percent of the section mask black; widen or reposition the section.');
                    }
                    $editable++; if ($value < 255) $soft++;
                }
                $grayRows .= chr($value);
                if ($section) $providerRows .= "\xff\xff\xff".chr(255 - $value);
            }
            $previous = $row;
        }
        if ($editable === 0) throw new InvalidArgumentException('The mask must include at least one editable pixel.');
        return ['png' => self::png($expectedWidth, $expectedHeight, 0, $grayRows),
            'providerPng' => $section ? self::png($expectedWidth, $expectedHeight, 6, $providerRows) : null,
            'statistics' => ['editablePixels' => $editable, 'softPixels' => $soft, 'protectedPixels' => $expectedWidth * $expectedHeight - $editable]];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function png(int $width, int $height, int $color, string $rows): string
    {
        return "\x89PNG\r\n\x1a\n".self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $color, 0, 0, 0))
            .self::chunk('IDAT', gzcompress($rows, 6)).self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('The PNG mask is invalid or unsupported.');
    }
}
