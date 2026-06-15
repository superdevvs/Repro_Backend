<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media storage disks
    |--------------------------------------------------------------------------
    |
    | Logical disk names used by App\Services\Media\MediaStorage. The "remote"
    | disk is the Cloudflare R2 (S3-compatible) bucket; the "local" disk is the
    | historical local public disk that media has always been written to.
    |
    */

    'remote_disk' => env('MEDIA_REMOTE_DISK', 'media'),

    'local_disk' => env('MEDIA_LOCAL_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Migration feature flags (Dropbox/local -> R2 cutover)
    |--------------------------------------------------------------------------
    |
    | These gate the phased cutover and provide instant rollback. They are all
    | OFF by default so installing this code is a no-op until R2 is provisioned
    | and explicitly enabled per environment.
    |
    |  - dual_write : mirror every new write to R2 in addition to local.
    |  - read_from_r2 : resolve URLs / serve reads from R2 (local stays as fallback).
    |  - r2_only : writes go to R2 only; local public disk is no longer written.
    |
    */

    'dual_write' => (bool) env('MEDIA_DUAL_WRITE', false),

    'read_from_r2' => (bool) env('MEDIA_READ_FROM_R2', false),

    'r2_only' => (bool) env('MEDIA_R2_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Presigned URL TTL
    |--------------------------------------------------------------------------
    |
    | Default lifetime (in seconds) for temporary/presigned URLs handed out for
    | raw originals and unpaid/locked client media. Keep this short so locked
    | media links cannot be shared/cached long after issuance.
    |
    */

    'temporary_url_ttl' => (int) env('MEDIA_TEMPORARY_URL_TTL', 900),

];
