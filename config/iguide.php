<?php

return [
    'offline_upload' => [
        'chunk_size_bytes' => 5 * 1024 * 1024,
        'max_size_bytes' => 256 * 1024 * 1024,
        'inactive_ttl_hours' => 24,
        'hard_ttl_days' => 7,
        'terminal_retention_days' => 7,
        'stale_assembly_minutes' => 45,
        // Safely exceeds one 30-minute scan attempt while recovering a
        // swallowed initial dispatch failure within a practical window.
        'stale_scan_minutes' => 90,
    ],
];
