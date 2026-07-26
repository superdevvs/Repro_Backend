<?php

$standardImages = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'heic', 'heif'];
$rawImages = ['raw', 'cr2', 'cr3', 'nef', 'arw', 'dng', 'raf', 'rw2', 'orf', 'pef', 'srw', '3fr', 'fff', 'iiq', 'rwl', 'x3f'];
$imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'image/heic', 'image/heif'];
$rawMimes = ['application/octet-stream', 'image/x-canon-cr2', 'image/x-canon-cr3', 'image/x-nikon-nef', 'image/x-sony-arw', 'image/x-adobe-dng', 'image/x-raw'];
$videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'wmv', 'webm'];
$videoMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/x-ms-wmv', 'video/webm'];

return [
    'disk' => env('STUDIO_UPLOAD_DISK', 'public'),
    'workflows' => [
        'photo-enhancement' => [
            'extensions' => [...$standardImages, ...$rawImages],
            'mimes' => [...$imageMimes, ...$rawMimes],
            'max_bytes' => 100 * 1024 * 1024,
        ],
        'twilight' => [
            'extensions' => [...$standardImages, ...$rawImages],
            'mimes' => [...$imageMimes, ...$rawMimes],
            'max_bytes' => 100 * 1024 * 1024,
        ],
        'video-cleanup' => [
            'extensions' => $videoExtensions,
            'mimes' => $videoMimes,
            'max_bytes' => 1024 * 1024 * 1024,
        ],
        'listing-video' => [
            'extensions' => $standardImages,
            'mimes' => $imageMimes,
            'max_bytes' => 50 * 1024 * 1024,
        ],
        'reel-generator' => [
            'extensions' => $standardImages,
            'mimes' => $imageMimes,
            'max_bytes' => 50 * 1024 * 1024,
        ],
        'batch-ai-jobs' => [
            'extensions' => [...$standardImages, ...$rawImages],
            'mimes' => [...$imageMimes, ...$rawMimes],
            'max_bytes' => 100 * 1024 * 1024,
        ],
    ],
];
