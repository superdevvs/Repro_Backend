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
    // Photographer shoot-media ceiling: 10 GiB === 10737418240 bytes
    // (Laravel validation uses KB: max:10485760).
    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 10737418240),

    // Allowed file extensions (lower-case, no leading dot). Mirrors the
    // FileUploadController `mimes:` allow-list.
    'allowed_types' => [
        'jpeg',
        'jpg',
        'png',
        // Floorplan originals are PDF deliverables, not only their JPEG previews.
        'pdf',
        'gif',
        'mp4',
        'mov',
        'avi',
        // The upload picker offers these video containers too (FULL_UPLOAD_ACCEPT).
        'm4v',
        'webm',
        'raw',
        'cr2',
        'cr3',
        'nef',
        'arw',
        // Adobe DNG and other camera RAW formats accepted by raw upload /
        // studio / FE FULL_UPLOAD_ACCEPT. Missing `dng` caused shoot 138
        // photographer batch 2ac1ab32 to return HTTP 422 "File type not allowed".
        'dng',
        'raf',
        'rw2',
        'orf',
        'pef',
        'srw',
        '3fr',
        'fff',
        'iiq',
        'rwl',
        'x3f',
        'nrw',
        'srf',
        'sr2',
        'tiff',
        'tif',
        'bmp',
        'heic',
        'heif',
        // Layered/archived editor deliverables (Req 5.8).
        'psd',
        // ZIP is accepted only from authenticated staff accounts. ClamAV scans
        // a ZIP as a single opaque object and never inspects the files inside
        // it, so archive CONTENTS are effectively unscanned (Req 5.9). The
        // staff-role gate lives in App\Services\UploadValidationService.
        'zip',
    ],

];
