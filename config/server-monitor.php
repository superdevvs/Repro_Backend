<?php

return [
    'enabled' => (bool) env('SERVER_MONITOR_ENABLED', false),
    'socket' => env('SERVER_MONITOR_SOCKET', '/run/repro-monitor/events.sock'),
    'key_file' => env('SERVER_MONITOR_KEY_FILE', '/etc/repro-monitor/auth.key'),
    'gateway_path' => '/server-monitor/v1',
];
