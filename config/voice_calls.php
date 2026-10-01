<?php

return [
    // Shared by PHP-FPM and all voice workers, independent of CACHE_STORE.
    'lock_store' => env('VOICE_LOCK_STORE', 'database'),
    'cache_store' => env('VOICE_CACHE_STORE', 'database'),
    // Keep timed offers and staff cleanup ahead of general mail/media jobs.
    'realtime_connection' => env('VOICE_REALTIME_QUEUE_CONNECTION', 'database'),
    'realtime_queue' => env('VOICE_REALTIME_QUEUE', 'voice-realtime'),
    // Explicit recording recovery can occupy a provider request for up to 45s.
    'transcript_connection' => env('VOICE_TRANSCRIPT_QUEUE_CONNECTION', 'database'),
    'transcript_queue' => env('VOICE_TRANSCRIPT_QUEUE', 'voice-transcripts'),
];
