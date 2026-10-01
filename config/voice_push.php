<?php

return [
    'enabled' => (bool) env('VOICE_WEB_PUSH_ENABLED', false),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT'),
    'connection' => env('VOICE_WEB_PUSH_QUEUE_CONNECTION', 'database'),
    'queue' => env('VOICE_WEB_PUSH_QUEUE', 'voice-notifications'),
    // Only browser-vendor push gateways. Never accept an arbitrary request URL.
    'endpoint_hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', '*.push.services.mozilla.com', '*.push.apple.com', '*.notify.windows.com'],
];
