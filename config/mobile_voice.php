<?php

// Enable only after the requested environment's app identity and provider push
// binding are verified. Production pilot may be enabled while debug stays unset;
// real-device receipt/audio acceptance remains separate from this configuration.
return [
    'enabled' => env('MOBILE_VOICE_ENABLED', false),
    'bundle_id' => env('MOBILE_VOICE_BUNDLE_ID'),
    'push_verified' => env('MOBILE_VOICE_PUSH_VERIFIED', false),
    'push_when_active' => false,
    'push_credentials' => [
        'debug' => env('MOBILE_VOICE_APNS_DEBUG_CREDENTIAL_ID'),
        'production' => env('MOBILE_VOICE_APNS_PRODUCTION_CREDENTIAL_ID'),
    ],
];
