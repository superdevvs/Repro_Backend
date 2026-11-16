<?php
// Tour analysis layer (Deploy 1). Not hash-pinned. Never move these keys into
// config/iguide-lab.php, which LabStagingLayouts.php:19 hashes.
return [
    'tour_analysis_enabled' => env('IGUIDE_LAB_TOUR_ANALYSIS_ENABLED', false),
    'tour_runs_paused' => env('IGUIDE_LAB_TOUR_RUNS_PAUSED', false),
    'grass_runs_enabled' => env('IGUIDE_LAB_TOUR_GRASS_RUNS_ENABLED', false),
    'declutter_runs_enabled' => env('IGUIDE_LAB_TOUR_DECLUTTER_RUNS_ENABLED', false),

    'survey' => [
        'model' => env('IGUIDE_LAB_TOUR_SURVEY_MODEL', 'gpt-6-astra'),
        'effort' => env('IGUIDE_LAB_TOUR_SURVEY_EFFORT', 'medium'),
        'max_output_tokens' => (int) env('IGUIDE_LAB_TOUR_SURVEY_MAX_OUTPUT_TOKENS', 16000),
        'max_response_bytes' => (int) env('IGUIDE_LAB_TOUR_SURVEY_MAX_RESPONSE_BYTES', 524288),
        'max_output_text_bytes' => 262144,
        'max_payload_bytes' => 37748736,
        'deadline_seconds' => 360,
        'views_timeout_seconds' => 90,
    ],

    'caps' => [
        'max_captures' => (int) env('IGUIDE_LAB_TOUR_MAX_CAPTURES', 80),
        'survey_calls_per_project' => (int) env('IGUIDE_LAB_TOUR_SURVEY_CALLS_PER_PROJECT', 240),
        'survey_calls_per_day' => (int) env('IGUIDE_LAB_TOUR_SURVEY_CALLS_PER_DAY', 160),
        'max_paid_surveys_per_capture' => (int) env('IGUIDE_LAB_TOUR_MAX_PAID_SURVEYS_PER_CAPTURE', 2),
        'max_runs_per_analysis' => 40,
    ],

    'runs' => [
        'chunk_size' => (int) env('IGUIDE_LAB_TOUR_CHUNK_SIZE', 8),
        'max_workflows_per_run' => 10,
        'max_consecutive_chunk_failures' => 2,
        'max_not_ready_ticks' => 10,
        'workflow_stall_seconds' => 900,
        'monitor_idle_hours' => 6,
    ],

    'queue_connection' => env('IGUIDE_LAB_TOUR_QUEUE_CONNECTION', 'studio'),
    'queue' => env('IGUIDE_LAB_TOUR_QUEUE', 'studio'),
    'worker_count' => (int) env('IGUIDE_LAB_TOUR_WORKERS', 1),

    'timing' => [
        'survey_minutes_per_capture' => ['min' => 1, 'max' => 2],
        'edit_minutes_per_room' => ['min' => 3, 'max' => 5],
    ],

    'monitor' => [
        'interval_seconds' => 60,
        'heartbeat_stale_seconds' => 300,
        'kick_gate_seconds' => 60,
    ],

    'staleness' => [
        'survey_queued_seconds' => 900,
        'survey_submitting_seconds' => 900,
        'survey_received_seconds' => 300,
        'max_survey_dispatches' => 5,
        'chunk_reserved_seconds' => 600,
        'max_chunk_start_attempts' => 3,
    ],

    'capabilities_cache_seconds' => 30,

    'body_limits' => [
        'selection_bytes' => 16384,
        'default_bytes' => 4096,
    ],
];
