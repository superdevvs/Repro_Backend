<?php

return [
    // Enable only after signed installers and the platform acceptance matrix pass.
    'release_ready' => (bool) env('EDIT_HELPER_RELEASE_READY', false),
    'installers' => [
        'win32-x64' => env('EDIT_HELPER_WINDOWS_URL'),
        'darwin-x64' => env('EDIT_HELPER_MAC_INTEL_URL'),
        'darwin-arm64' => env('EDIT_HELPER_MAC_ARM_URL'),
    ],
];
