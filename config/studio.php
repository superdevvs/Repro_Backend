<?php

return [
    // Explicit paths support an operator-managed media runtime without changing system packages.
    'ffmpeg_path' => env('STUDIO_FFMPEG_PATH', 'ffmpeg'),
    'ffprobe_path' => env('STUDIO_FFPROBE_PATH', 'ffprobe'),
    // Keep client drafts and grants intact while the client rollout is paused.
    'client_access_enabled' => env('STUDIO_CLIENT_ACCESS_ENABLED', false),
];
