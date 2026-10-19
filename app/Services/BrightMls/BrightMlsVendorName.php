<?php

namespace App\Services\BrightMls;

final class BrightMlsVendorName
{
    public const DEFAULT = 'R/E Pro Photos';

    public static function normalize(?string $value): string
    {
        $value = trim($value ?? '');

        // Upgrade only the previous default; retain custom vendor branding.
        return $value === '' || strcasecmp($value, 'Repro Photos') === 0
            ? self::DEFAULT
            : $value;
    }
}
