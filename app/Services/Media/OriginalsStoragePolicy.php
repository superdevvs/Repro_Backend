<?php

namespace App\Services\Media;

/** Key-only policy shared by runtime routing and the operator's copy manifest. */
class OriginalsStoragePolicy
{
    public const ORIGINAL_DIRECTORIES = [
        'todo', 'completed', 'final', 'extra', 'floorplan', 'floorplans',
        'virtual_staging', 'green_grass', 'twilight', 'drone', 'autoenhance', 'fal', 'fal-ai',
    ];

    public const PREVIEW_DIRECTORIES = [
        'thumbnail', 'thumbnails', 'thumb', 'thumbs', 'grid', 'grids', 'web', 'webs',
        'preview', 'previews', 'placeholder', 'placeholders',
    ];

    public static function isOriginalKey(string $key): bool
    {
        $key = ltrim(trim($key), '/');
        if (str_starts_with($key, 'storage/')) {
            $key = substr($key, strlen('storage/'));
        }
        if ($key === '' || str_contains($key, '\\') || preg_match('#(^|/)\.{1,2}(/|$)#', $key)) {
            return false;
        }
        $segments = explode('/', $key);
        foreach (array_slice($segments, 2, -1) as $segment) {
            if (in_array(strtolower($segment), self::PREVIEW_DIRECTORIES, true)
                || str_starts_with(strtolower($segment), 'watermarked')) {
                return false;
            }
        }
        if (count($segments) >= 4 && $segments[0] === 'shoots' && ctype_digit($segments[1])
            && in_array($segments[2], self::ORIGINAL_DIRECTORIES, true) && end($segments) !== '') {
            return true;
        }

        return preg_match('#^shoots/[0-9]+/archives/[^/]+\.zip$#iD', $key) === 1
            || preg_match('#^share-links/[^/]+/[^/]+\.zip$#iD', $key) === 1;
    }
}
