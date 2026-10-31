<?php

return [
    'enabled' => (bool) env('LISTING_STUDIO_TRIALS_ENABLED', false),
    // Release each recipe only after a real output preserves the photographed property.
    'services' => [
        'staging' => ['enabled' => (bool) env('LISTING_STUDIO_STAGING_TRIALS_ENABLED', false)],
        'decluttering' => ['enabled' => (bool) env('LISTING_STUDIO_DECLUTTERING_TRIALS_ENABLED', false),
            'route' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null]],
        'grass' => ['enabled' => (bool) env('LISTING_STUDIO_GRASS_TRIALS_ENABLED', true)],
        'twilight' => ['enabled' => (bool) env('LISTING_STUDIO_TWILIGHT_TRIALS_ENABLED', false),
            'route' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null]],
    ],
    'bridge_key' => env('LISTING_STUDIO_TRIALS_BRIDGE_KEY'),
    'turnstile_secret' => env('LISTING_STUDIO_TURNSTILE_SECRET'),
    'turnstile_site_key' => env('LISTING_STUDIO_TURNSTILE_SITE_KEY'),
    'turnstile_hosts' => array_values(array_filter(array_map('trim', explode(',', env('LISTING_STUDIO_TURNSTILE_HOSTS', 'reprophotos.com,www.reprophotos.com'))))),
    'global_daily_limit' => (int) env('LISTING_STUDIO_TRIALS_DAILY_LIMIT', 100),
    'ip_daily_limit' => (int) env('LISTING_STUDIO_TRIALS_IP_DAILY_LIMIT', 12),
    'admission_seconds' => 300,
    'admission_session_limit' => 3,
    'admission_global_limit' => 1000,
    'retention_days' => 7,
    'claim_minutes' => 30,
    'max_file_kb' => 10240,
    'max_pixels' => 40000000,
    // Frozen, explicitly approved provider QA. Excluded from conversion only, never quotas or health.
    'monitoring_qa_trial_ids' => [
        '965b4683-a39c-4135-955a-5eab902190ca', 'c9ec3170-3f6d-4180-a6ed-0a3c755e641d',
        'd17ece88-a00e-4920-9649-853b2872c5a8', 'a22af3bd-8c2c-4783-a7b2-59b4dc0508cf',
        '475868f4-ae7a-4940-8e42-e6ae03626f80', 'aae47cae-010c-42e9-90c9-981ecb32af5a',
        'b04a75d8-becf-4863-bf56-0082f2dd68c9',
    ],
];
