<?php

return [
    'enabled' => (bool) env('MOBILE_NOTIFICATIONS_ENABLED', false),
    'environment' => env('MOBILE_NOTIFICATIONS_ENVIRONMENT', 'sandbox'),
    'topic' => env('MOBILE_NOTIFICATIONS_TOPIC', 'com.reprophotos.dashboard.ios'),
    'team_id' => env('MOBILE_NOTIFICATIONS_TEAM_ID'),
    'key_id' => env('MOBILE_NOTIFICATIONS_KEY_ID'),
    'private_key_path' => env('MOBILE_NOTIFICATIONS_PRIVATE_KEY_PATH'),
    'queue' => env('MOBILE_NOTIFICATIONS_QUEUE', 'default'),
];
