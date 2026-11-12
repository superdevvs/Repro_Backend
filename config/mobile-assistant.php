<?php

return [
    // Enable only after the native Siri acceptance run. Empty pilot list permits all eligible users.
    'enabled' => env('MOBILE_ASSISTANT_ENABLED', false),
    'pilot_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MOBILE_ASSISTANT_PILOT_USER_IDS', ''))))),
    'proposal_seconds' => 300,
];
