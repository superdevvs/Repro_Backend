<?php
// Virtual staging on the interior room engine. Not hash-pinned: never move these keys into
// config/iguide-lab.php, which LabStagingLayouts.php hashes. Captured per workflow at start.
return [
    // Staging requests run the interior room workflow (the route that produced real furniture drafts)
    // and keep reporting preset "staging" to the editor. Off returns staging to its own older route.
    'staging_room_alias_enabled' => (bool) env('IGUIDE_LAB_STAGING_ROOM_ALIAS_ENABLED', true),
];
