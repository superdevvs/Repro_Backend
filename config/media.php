<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media storage disks
    |--------------------------------------------------------------------------
    |
    | Logical disk names used by App\Services\Media\MediaStorage. The "remote"
    | disk is the Cloudflare R2 (S3-compatible) bucket. The "local" disk is the
    | private application disk (storage/app/private). Historical objects that
    | were written to the public disk remain readable via legacy_public_disk
    | until an operator migrates them.
    |
    */

    'remote_disk' => env('MEDIA_REMOTE_DISK', 'media'),

    'local_disk' => env('MEDIA_LOCAL_DISK', 'local'),

    'legacy_public_disk' => env('MEDIA_LEGACY_PUBLIC_DISK', 'public'),

    // Enable only after provisioning and verifying a non-destructive originals copy.
    // Local/NVMe remains the default for processing, previews, and unknown paths.
    'tiered_storage_enabled' => (bool) env('MEDIA_TIERED_STORAGE_ENABLED', false),

    'originals_disk' => 'media_originals',

    'originals_mount' => env('MEDIA_ORIGINALS_MOUNT', ''),

    'originals_uuid' => env('MEDIA_ORIGINALS_UUID', ''),

    /*
    |--------------------------------------------------------------------------
    | Migration feature flags (Dropbox/local -> R2 cutover)
    |--------------------------------------------------------------------------
    |
    | These gate the phased R2 cutover and provide instant rollback. They are all
    | OFF by default so R2 is unused until provisioned. Shoot media is written to
    | the private local disk regardless of these flags so `/storage/shoots/` is
    | not the live path.
    |
    |  - dual_write : mirror every new write to R2 in addition to local.
    |  - read_from_r2 : resolve URLs / serve reads from R2 (local stays as fallback).
    |  - r2_only : writes go to R2 only; the local private disk is no longer written.
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

    /*
    |--------------------------------------------------------------------------
    | App-signed URL TTL (local / private disk)
    |--------------------------------------------------------------------------
    |
    | Lifetime for Laravel signed URLs that stream private shoot media through
    | the application. This is not a public nginx alias; expiry is the access
    | window for <img> tags, emails, and in-app viewers.
    |
    */

    'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 604800),

];
