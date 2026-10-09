<?php

return [
    'issuer' => rtrim(env('APP_URL', 'https://reprodashboard.com'), '/'),
    'scopes' => ['repro.read', 'repro.write', 'repro.finance'],
    'access_minutes' => 30,
    'refresh_days' => 30,
    'draft_minutes' => 15,
    'protocol_versions' => ['2025-03-26', '2025-06-18', '2025-11-25'],
];
