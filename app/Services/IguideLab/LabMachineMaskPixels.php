<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Describes exact provider coverage bytes, without a human-review or semantic claim. */
class LabMachineMaskPixels
{
    public function describe(string $bytes): array
    {
        if (strlen($bytes) > LabMachineSegmentationTransport::MAX_MASK_BYTES || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") $this->invalid();
        $header = null; $ended = false; $data = false;
        for ($offset = 8, $length = strlen($bytes); $offset < $length;) {
            if ($offset + 12 > $length) $this->invalid();
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            if ($size > $length - $offset - 12) $this->invalid();
            $type = substr($bytes, $offset + 4, 4); $value = substr($bytes, $offset + 8, $size);
            if (! preg_match('/\A[A-Za-z]{4}\z/D', $type) || substr($bytes, $offset + 8 + $size, 4) !== pack('N', crc32($type.$value))) $this->invalid();
            if ($header === null && $type !== 'IHDR') $this->invalid();
            if (in_array($type, ['tRNS', 'acTL', 'fcTL', 'fdAT', 'iCCP', 'eXIf'], true)) $this->invalid();
            if ($type === 'IHDR') {
                if ($header !== null || $size !== 13) $this->invalid();
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $value);
                if ($header['width'] !== 2048 || $header['height'] !== 2048 || $header['depth'] !== 8
                    || ! in_array($header['color'], [0, 2, 4, 6], true) || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] !== 0) $this->invalid();
            } elseif ($type === 'IDAT') { $data = true; }
            elseif ($type === 'IEND') { if (! $data || $size !== 0 || $offset + 12 !== $length) $this->invalid(); $ended = true; }
            elseif (ctype_upper($type[0])) $this->invalid();
            $offset += $size + 12;
        }
        if (! $ended || $header === null) $this->invalid();
        $image = @imagecreatefromstring($bytes); if ($image === false) $this->invalid();
        if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) { imagedestroy($image); $this->invalid(); }
        $count = 0; $x0 = 2048; $y0 = 2048; $x1 = 0; $y1 = 0;
        try {
            for ($y = 0; $y < 2048; $y++) for ($x = 0; $x < 2048; $x++) {
                $p = imagecolorat($image, $x, $y); $gray = ($p >> 16) & 255;
                if ((($p >> 24) & 127) !== 0 || (($p >> 8) & 255) !== $gray || ($p & 255) !== $gray) $this->invalid();
                if ($gray > 0) { $count++; $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x + 1); $y1 = max($y1, $y + 1); }
            }
        } finally { imagedestroy($image); }
        return ['sha256' => hash('sha256', $bytes), 'width' => 2048, 'height' => 2048, 'bounds' => $count ? [$x0, $y0, $x1, $y1] : null, 'positivePixels' => $count];
    }
    private function invalid(): never { throw new InvalidArgumentException('Segmentation requires a complete, opaque, equal-channel 8-bit PNG mask at2048square.'); }
}
