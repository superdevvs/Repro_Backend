<?php

return [
    // Virtual staging is pinned to this API. Result URLs expire, so the worker downloads them.
    // https://docs.virtualstagingai.app/v2-api/endpoints
    'virtualstagingai' => [
        'api_key' => env('VIRTUAL_STAGING_AI_API_KEY'),
        'base_url' => env('VIRTUAL_STAGING_AI_BASE_URL', 'https://api.virtualstagingai.app/v2'),
        'timeout' => 60,
        'poll_interval' => (int) env('VIRTUAL_STAGING_AI_POLL_INTERVAL', 3),
        'poll_timeout' => (int) env('VIRTUAL_STAGING_AI_POLL_TIMEOUT', 900),
    ],
    'fotello' => [
        'api_key' => env('FOTELLO_API_KEY'),
        'team_id' => env('FOTELLO_TEAM_ID'),
        'timeout' => 60,
        'transfer_timeout' => 120,
        'allowed_transfer_hosts' => array_filter(explode(',', env('FOTELLO_TRANSFER_HOSTS', ''))),
    ],
];
