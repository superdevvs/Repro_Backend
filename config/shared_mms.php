<?php

// Deployment opt-in only after provider configuration and controlled device acceptance.
return [
    'enabled' => env('SHARED_MMS_ENABLED', false),
    // Explicitly provisioned SmsNumber IDs; never inferred from an SMS-only number.
    'sender_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SHARED_MMS_SENDER_IDS', ''))))),
    'lock_store' => env('SHARED_MMS_LOCK_STORE', 'database'),
];
