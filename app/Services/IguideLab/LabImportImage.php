<?php

namespace App\Services\IguideLab;

use Illuminate\Validation\ValidationException;

/** Decode every source sequentially before publication; retain exact original bytes. */
class LabImportImage
{
    public function validate(string $bytes, array $size): void
    {
        $this->require(! isset($size['channels']) || $size['channels'] === 3, 'Use an opaque RGB source; grayscale and CMYK panoramas need explicit normalization before import.');
        $this->require($this->orientation($bytes, $size['mime']) === 1, 'Rotated or mirrored EXIF orientation is unsupported for full-sphere sources. Export an upright original 2:1 panorama first.');
        $warning = false; $image = false;
        set_error_handler(function () use (&$warning) { $warning = true; return true; });
        try { $image = imagecreatefromstring($bytes); }
        finally { restore_error_handler(); }
        try { $this->require($image !== false && ! $warning && imagesx($image) === $size[0] && imagesy($image) === $size[1], 'A panorama cannot be fully decoded without corruption.'); }
        finally { if ($image !== false) imagedestroy($image); }
    }

    private function orientation(string $bytes, string $mime): int
    {
        $exif = null;
        if ($mime === 'image/jpeg') {
            for ($p = 2, $n = strlen($bytes); $p + 4 <= $n;) {
                $this->require(ord($bytes[$p]) === 255, 'Invalid JPEG marker structure.');
                while ($p < $n && ord($bytes[$p]) === 255) $p++;
                $this->require($p < $n, 'Truncated JPEG marker.'); $marker = ord($bytes[$p++]);
                if ($marker === 218 || $marker === 217) break;
                if ($marker === 1 || ($marker >= 208 && $marker <= 216)) continue;
                $this->require($p + 2 <= $n, 'Truncated JPEG segment.'); $length = unpack('n', substr($bytes, $p, 2))[1];
                $this->require($length >= 2 && $length <= $n - $p, 'Truncated JPEG segment.'); $segment = substr($bytes, $p + 2, $length - 2);
                $this->require(! ($marker === 226 && str_starts_with($segment, "ICC_PROFILE\0")), 'Embedded ICC profiles need explicit color normalization before import; original bytes are never silently changed.');
                if ($marker === 225 && str_starts_with($segment, "Exif\0\0")) { $this->require($exif === null, 'Ambiguous duplicate EXIF metadata.'); $exif = substr($segment, 6); }
                $p += $length;
            }
        } elseif ($mime === 'image/png') {
            for ($p = 8, $n = strlen($bytes); $p + 12 <= $n;) {
                $length = unpack('N', substr($bytes, $p, 4))[1]; $type = substr($bytes, $p + 4, 4);
                $this->require($length <= $n - $p - 12, 'A PNG chunk is truncated.');
                $this->require($type !== 'acTL', 'Animated panoramas are unsupported.');
                $this->require(! in_array($type, ['iCCP', 'tRNS'], true), 'Embedded profiles or transparency need explicit RGB normalization before import.');
                if ($type === 'IHDR') $this->require($length === 13 && ord($bytes[$p + 16]) === 8 && ord($bytes[$p + 17]) === 2, 'Use an opaque 8-bit RGB PNG source; palette, alpha and other modes require explicit normalization.');
                if ($type === 'eXIf') $exif = substr($bytes, $p + 8, $length);
                $p += 12 + $length;
            }
        } elseif ($mime === 'image/webp') {
            for ($p = 12, $n = strlen($bytes); $p + 8 <= $n;) {
                $length = unpack('V', substr($bytes, $p + 4, 4))[1]; $type = substr($bytes, $p, 4);
                $this->require($length <= $n - $p - 8, 'A WebP chunk is truncated.');
                $this->require(! in_array($type, ['ANIM', 'ANMF'], true), 'Animated panoramas are unsupported.');
                $this->require(! in_array($type, ['ICCP', 'ALPH'], true), 'Embedded profiles or transparency need explicit RGB normalization before import.');
                if ($type === 'VP8X') $this->require($length >= 10 && (ord($bytes[$p + 8]) & 0x30) === 0, 'Use an opaque WebP without an embedded color profile.');
                if ($type === 'VP8L') $this->require($length >= 5 && (unpack('V', substr($bytes, $p + 9, 4))[1] & 0x10000000) === 0, 'Transparent lossless WebP requires explicit RGB normalization.');
                if ($type === 'EXIF') $exif = substr($bytes, $p + 8, $length);
                $p += 8 + $length + ($length % 2);
            }
        }
        if ($exif === null) return 1;
        if (str_starts_with($exif, "Exif\0\0")) $exif = substr($exif, 6);
        $this->require(strlen($exif) >= 8 && in_array(substr($exif, 0, 2), ['II', 'MM'], true), 'Unsupported EXIF orientation metadata.');
        $little = substr($exif, 0, 2) === 'II';
        $u16 = fn ($p) => unpack($little ? 'v' : 'n', substr($exif, $p, 2))[1];
        $u32 = fn ($p) => unpack($little ? 'V' : 'N', substr($exif, $p, 4))[1];
        $this->require($u16(2) === 42, 'Unsupported EXIF orientation metadata.'); $offset = $u32(4);
        $this->require($offset <= strlen($exif) - 2, 'Invalid EXIF orientation directory.'); $count = $u16($offset);
        $this->require($count <= 4096 && $offset + 2 + $count * 12 <= strlen($exif), 'Invalid EXIF orientation directory.');
        $orientation = 1; $seen = false;
        for ($i = 0; $i < $count; $i++) {
            $p = $offset + 2 + 12 * $i;
            if ($u16($p) === 274) { $this->require(! $seen && $u16($p + 2) === 3 && $u32($p + 4) === 1, 'Ambiguous EXIF orientation.'); $seen = true; $orientation = $u16($p + 8); }
        }
        return $orientation;
    }
    private function require(bool $condition, string $message): void { if (! $condition) throw ValidationException::withMessages(['source' => $message]); }
}
