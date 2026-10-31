<?php

return [
    'enabled' => (bool) env('LISTING_STUDIO_TRIALS_ENABLED', false),
    'bridge_key' => env('LISTING_STUDIO_TRIALS_BRIDGE_KEY'),
    'turnstile_secret' => env('LISTING_STUDIO_TURNSTILE_SECRET'),
    'turnstile_site_key' => env('LISTING_STUDIO_TURNSTILE_SITE_KEY'),
    'turnstile_hosts' => array_values(array_filter(array_map('trim', explode(',', env('LISTING_STUDIO_TURNSTILE_HOSTS', 'reprophotos.com,www.reprophotos.com'))))),
    'global_daily_limit' => (int) env('LISTING_STUDIO_TRIALS_DAILY_LIMIT', 100),
    'ip_daily_limit' => (int) env('LISTING_STUDIO_TRIALS_IP_DAILY_LIMIT', 12),
    'retention_days' => 7,
    'claim_minutes' => 30,
    'max_file_kb' => 10240,
    'max_pixels' => 40000000,
];
