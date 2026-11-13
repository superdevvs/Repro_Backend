<?php

namespace App\Services\IguideLab;

use Intervention\Image\ImageManager;

class LabImages
{
    /** Shared with generation so the compositor can verify the exact submitted pixels. */
    public static function prepared(string $bytes): string
    {
        return (string) ImageManager::gd()->read($bytes)->scaleDown(width: 2048, height: 2048)->toPng();
    }
}
