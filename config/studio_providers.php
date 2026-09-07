<?php

return [
    'fotello' => [
        'api_key' => env('FOTELLO_API_KEY'),
        'team_id' => env('FOTELLO_TEAM_ID'),
        'timeout' => 60,
        'transfer_timeout' => 120,
        'allowed_transfer_hosts' => array_filter(explode(',', env('FOTELLO_TRANSFER_HOSTS', ''))),
    ],
];
