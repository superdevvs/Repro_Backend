<?php

return [
    // Operations calendar follows the same Eastern business day as dashboard
    // reporting. Individual appointment dates/times retain each property's zone.
    'calendar_timezone' => env('SCHEDULE_CALENDAR_TIMEZONE', 'America/New_York'),

    // Scheduler hold. While enabled, `schedule:run` skips every scheduled task
    // named in a scheduler-hold.<owner>.json file in the hold directory ("*"
    // holds all). Deploys write one into the directory `scheduler:hold path`
    // prints; see docs/sqlite-first/telnyx-receiver.md.
    'hold' => [
        'enabled' => (bool) env('SCHEDULER_HOLD_ENABLED', false),
        'directory' => env('SCHEDULER_HOLD_DIRECTORY', storage_path('framework')),
        // No hold outlives its file by more than this, whatever it says.
        'max_minutes' => (int) env('SCHEDULER_HOLD_MAX_MINUTES', 720),
    ],
];
