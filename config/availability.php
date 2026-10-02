<?php

return [
    'hybrid_travel_enabled' => (bool) env('HYBRID_TRAVEL_ENABLED', false),
    'scheduling_lock_store' => env('SCHEDULING_LOCK_STORE', 'scheduling'),
    'scheduling_lock_seconds' => (int) env('SCHEDULING_LOCK_SECONDS', 30),
    'scheduling_lock_wait_seconds' => (int) env('SCHEDULING_LOCK_WAIT_SECONDS', 5),
    'travel_geocode_timeout_seconds' => 3,
    'travel_road_distance_factor' => 1.3,
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
    // Travel between distinct bookings keeps at least a 15-minute gap.
    'buffer_time_minutes' => max(15, (int) env('PHOTOGRAPHER_BUFFER_TIME', 15)),

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

    // Scheduling/catalog default when a service has no explicit shoot_duration_minutes.
    // Used by ShootDurationResolver, Service::getShootDurationMinutes, booking_duration_defaults.
    'default_shoot_duration_minutes' => (int) env('DEFAULT_SHOOT_DURATION', 60),

    // Manual duration slider/custom values; catalogue defaults remain service-specific.
    'min_shoot_duration_minutes' => 5,

    // Maximum shoot duration in minutes (for safety cap)
    'max_shoot_duration_minutes' => 300,

    /*
    |--------------------------------------------------------------------------
    | Photographer service-radius enforcement (Option B — flag-gated rollout)
    |--------------------------------------------------------------------------
    |
    | When enabled, a photographer is excluded from booking eligibility and
    | manual/auto assignment when the shoot location's distance exceeds their
    | `metadata.service_radius_miles`. Distance/availability/service-area logic
    | is unchanged when this is OFF (the historical default), so production can
    | stay OFF until product sign-off while local/QA runs with it ON.
    |
    |   PHOTOGRAPHER_RADIUS_ENFORCEMENT=true        # enable the gate
    |   PHOTOGRAPHER_RADIUS_UNLIMITED_WHEN_NULL=    # null/empty radius → unlimited (only if approved)
    |
    */

    // Master switch for radius gating. Default OFF (safe for production).
    'radius_enforcement' => env('PHOTOGRAPHER_RADIUS_ENFORCEMENT', false),

    // When radius is null/empty: treat as unlimited (true) or NOT eligible (false).
    // Product decision; defaults to NOT eligible per QA spec.
    'radius_unlimited_when_null' => env('PHOTOGRAPHER_RADIUS_UNLIMITED_WHEN_NULL', false),
];
