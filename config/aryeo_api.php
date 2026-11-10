<?php

return [
    // UI and local records work while provider traffic is disabled.
    'enabled' => (bool) env('ARYEO_API_ENABLED', false),
    'writes_enabled' => (bool) env('ARYEO_API_WRITES_ENABLED', false),
    'production_enabled' => (bool) env('ARYEO_API_PRODUCTION_ENABLED', false),
    'client_id' => env('ARYEO_APPLICATION_CLIENT_ID'),
    'key_id' => env('ARYEO_APPLICATION_KEY_ID'),
    'private_key_path' => env('ARYEO_APPLICATION_PRIVATE_KEY_PATH'),
    'auth_url' => 'https://auth.aryeo.com',
    'production_url' => 'https://api.aryeo.com/v2beta1',
    // Obtain the actual sandbox endpoint from the partner; never infer one.
    'sandbox_url' => env('ARYEO_API_SANDBOX_URL'),
    'redirect_uri' => 'https://reprodashboard.com/api/integrations/aryeo/oauth/callback',
    'public_origin' => 'https://reprodashboard.com',
    'queue' => 'aryeo-api',
    'primary_operator' => env('ARYEO_API_PRIMARY_OPERATOR', 'Shubham'),
    'grant_hours' => 48,
    'storage_group' => env('ARYEO_API_STORAGE_GROUP', 'www-data'),
    'requests_per_minute' => 400,
    'webhook_secrets' => json_decode(env('ARYEO_API_WEBHOOK_SECRETS', '{}'), true) ?: [],
    'webhook_source' => env('ARYEO_API_WEBHOOK_SOURCE'),
    'delegated_scopes' => 'aryeo.job:manage aryeo.place:manage aryeo.file-ingest-intent:manage aryeo.file:read aryeo.link:manage aryeo.place-asset:manage aryeo.place-asset-section:read aryeo.place-asset-subsection:read aryeo.delivery:manage offline_access',
];
