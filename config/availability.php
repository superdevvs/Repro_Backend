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

    /*
    |--------------------------------------------------------------------------
    | Backend_Fallback_Hours (single canonical fallback working window)
    |--------------------------------------------------------------------------
    |
    | The ONE authoritative default working window (9:00 AM to 6:00 PM),
    | applied by the backend only when no configured hours are available
    | while computing the effective availability window it returns to the
    | frontend. This is the single source of truth for the fallback window
    | used to authorize bookings. The frontend keeps a DISPLAY-ONLY copy
    | (FRONTEND_FALLBACK_HOURS_DISPLAY_ONLY) that never authorizes a booking.
    |
    */

    // Canonical fallback start time (24h H:i) when no configured hours exist
    'fallback_start_time' => env('AVAILABILITY_FALLBACK_START', '09:00'),

    // Canonical fallback end time (24h H:i) when no configured hours exist
    'fallback_end_time' => env('AVAILABILITY_FALLBACK_END', '18:00'),

    // Default shoot duration in minutes if services don't specify
    'default_shoot_duration_minutes' => env('DEFAULT_SHOOT_DURATION', 120),

    // Minimum shoot duration in minutes
    'min_shoot_duration_minutes' => 60,

    // Maximum shoot duration in minutes (for safety cap)
    'max_shoot_duration_minutes' => 240,
];

