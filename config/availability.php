<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Photographer Availability Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for photographer availability management
    |
    */

    // Buffer time in minutes between consecutive shoots
    // This accounts for travel time and prevents back-to-back bookings
    // Minimum 1 hour (60 minutes) gap required between bookings
    'buffer_time_minutes' => env('PHOTOGRAPHER_BUFFER_TIME', 15),

    // Default shoot duration in minutes if services don't specify
    'default_shoot_duration_minutes' => env('DEFAULT_SHOOT_DURATION', 120),

    // Minimum shoot duration in minutes
    'min_shoot_duration_minutes' => 60,

    // Maximum shoot duration in minutes (for safety cap)
    'max_shoot_duration_minutes' => 240,
];

