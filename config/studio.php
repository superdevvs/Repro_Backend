<?php

return [
    // Keep client drafts and grants intact while the client rollout is paused.
    'client_access_enabled' => env('STUDIO_CLIENT_ACCESS_ENABLED', false),
];
