<?php

return [
    // Deploy the additive API before enabling the replacement frontend.
    'enabled' => env('CONTEXTUAL_SEARCH_ENABLED', false),
    'limit' => 8,
];
