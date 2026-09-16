<?php

namespace App\Support;

final class UploadLimit
{
    public static function maxBytes(): int
    {
        return (int) config('uploads.max_bytes');
    }

    public static function label(): string
    {
        $bytes = self::maxBytes();
        $gib = 1024 * 1024 * 1024;
        if ($bytes > 0 && $bytes % $gib === 0) {
            return ($bytes / $gib).'GB';
        }

        $mib = 1024 * 1024;
        if ($bytes > 0 && $bytes % $mib === 0) {
            return ($bytes / $mib).'MB';
        }

        return (string) $bytes;
    }
}
