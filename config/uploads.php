<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload validation rules (pre-scan)
    |--------------------------------------------------------------------------
    |
    | These limits are the single source of truth for the pre-scan validation
    | performed by App\Services\UploadValidationService (Req 14.5, 14.6). They
    | mirror the inline rules historically enforced by FileUploadController
    | (`max:1048576` KB and the photo/video `mimes:` allow-list) so behaviour
    | stays consistent everywhere uploads are accepted.
    |
    */

    // Maximum allowed size for a single uploaded file, in bytes.
    // Historical rule: `max:1048576` (KB) === 1 GiB === 1073741824 bytes.
    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 1048576 * 1024),

    // Allowed file extensions (lower-case, no leading dot). Mirrors the
    // FileUploadController `mimes:` allow-list.
    'allowed_types' => [
        'jpeg',
        'jpg',
        'png',
        'gif',
        'mp4',
        'mov',
        'avi',
        'raw',
        'cr2',
        'cr3',
        'nef',
        'arw',
        'tiff',
        'bmp',
        'heic',
        'heif',
        'zip',
    ],

];
