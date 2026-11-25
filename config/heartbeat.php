<?php

/*
| Outside alerting (php artisan ops:heartbeat, every minute). The server pings
| an alert service (Healthchecks.io, Better Stack and similar). A failed check
| pings the fail address instead, and silence means the server is down.
*/
return [
    // Heartbeat address from the alert service. Treat it as private. Empty
    // turns the scheduled heartbeat off.
    'url' => (string) env('HEARTBEAT_PING_URL', ''),

    // Address for a failed check. Empty means the ping address plus /fail.
    'fail_url' => (string) env('HEARTBEAT_FAIL_URL', ''),

    'timeout_seconds' => (int) env('HEARTBEAT_TIMEOUT_SECONDS', 10),

    'backup' => [
        // Pattern for the database backup files, for example
        // /mnt/16tb/repro/sqlite-nightly/*.sqlite. Empty skips the check.
        'glob' => (string) env('HEARTBEAT_BACKUP_GLOB', ''),
        // Plan pass mark: the newest backup is under 26 hours old.
        'max_age_hours' => (float) env('HEARTBEAT_BACKUP_MAX_AGE_HOURS', 26),
    ],

    'disk' => [
        // Comma-separated paths to watch. Empty watches the app's own disk.
        'paths' => (string) env('HEARTBEAT_DISK_PATHS', ''),
        // Same reserve the deploy preflight keeps.
        'min_free_gb' => (float) env('HEARTBEAT_DISK_MIN_FREE_GB', 20),
    ],

    'queue' => [
        // Plan pass mark: the oldest waiting job is under ten minutes old.
        'max_age_minutes' => (int) env('HEARTBEAT_QUEUE_MAX_AGE_MINUTES', 10),
        // Comma-separated queue names to watch. Empty watches every queue.
        'queues' => (string) env('HEARTBEAT_QUEUES', ''),
    ],
];
