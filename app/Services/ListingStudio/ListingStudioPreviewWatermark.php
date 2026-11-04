<?php

namespace App\Services\ListingStudio;

use GdImage;
use RuntimeException;

/** A subtle, diagonal brand mark baked into private guest previews only. */
final class ListingStudioPreviewWatermark
{
    public const VERSION = 'logo-v1';

    public function apply(GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $logo = @imagecreatefrompng(public_path('images/email-atelier/v6/logo-dark.png'));
        if (! $logo) {
            throw new RuntimeException('The preview brand mark is unavailable.');
        }
        $stamp = $rotated = null;
        try {
            $size = max(32, min(420, (int) round(min($width, $height) * 0.7)));
            $logoWidth = max(16, (int) round($size * 0.47));
            $logoHeight = max(1, (int) round($logoWidth * imagesy($logo) / imagesx($logo)));
            $fontSize = max(6, (int) round($size * 0.1));
            $font = resource_path('fonts/Inter-SemiBold.ttf');
            $bounds = imagettfbbox($fontSize, 0, $font, 'Preview');
            if ($bounds === false) {
                throw new RuntimeException('The preview typeface is unavailable.');
            }
            $textWidth = max($bounds[0], $bounds[2], $bounds[4], $bounds[6]) - min($bounds[0], $bounds[2], $bounds[4], $bounds[6]);
            $top = min($bounds[1], $bounds[3], $bounds[5], $bounds[7]);
            $textHeight = max($bounds[1], $bounds[3], $bounds[5], $bounds[7]) - $top;
            $gap = max(4, (int) round($size * 0.06));
            $stampWidth = $logoWidth + $gap + $textWidth + 12;
            $stampHeight = max($logoHeight, $textHeight) + 12;
            $stamp = imagecreatetruecolor($stampWidth, $stampHeight);
            imagealphablending($stamp, false);
            imagesavealpha($stamp, true);
            imagefill($stamp, 0, 0, imagecolorallocatealpha($stamp, 255, 255, 255, 127));
            imagecopyresampled($stamp, $logo, 6, (int) (($stampHeight - $logoHeight) / 2), 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
            imagettftext($stamp, $fontSize, 0, 6 + $logoWidth + $gap, (int) (($stampHeight - $textHeight) / 2) - $top, imagecolorallocatealpha($stamp, 255, 255, 255, 0), $font, 'Preview');
            $rotated = imagerotate($stamp, 30, imagecolorallocatealpha($stamp, 255, 255, 255, 127));
            if (! $rotated) {
                throw new RuntimeException('The preview brand mark could not be rendered.');
            }
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
            // Scale the existing per-pixel alpha, preserving the logo's transparent edges.
            for ($y = 0; $y < imagesy($rotated); $y++) {
                for ($x = 0; $x < imagesx($rotated); $x++) {
                    $pixel = imagecolorat($rotated, $x, $y);
                    $alpha = 127 - (int) round((127 - (($pixel >> 24) & 127)) * 0.16);
                    imagesetpixel($rotated, $x, $y, ($alpha << 24) | ($pixel & 0xFFFFFF));
                }
            }
            $positions = min($width, $height) < 180 ? [[0.5, 0.5]] : [[0.27, 0.24], [0.73, 0.5], [0.36, 0.79]];
            imagealphablending($image, true);
            foreach ($positions as [$cx, $cy]) {
                imagecopy($image, $rotated, (int) round($width * $cx - imagesx($rotated) / 2), (int) round($height * $cy - imagesy($rotated) / 2), 0, 0, imagesx($rotated), imagesy($rotated));
            }
        } finally {
            imagedestroy($logo);
            if ($stamp) {
                imagedestroy($stamp);
            }
            if ($rotated) {
                imagedestroy($rotated);
            }
        }
    }
}
