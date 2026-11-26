<?php

return [
    /*
    | Write each API request's telemetry trace after the response is sent (the middleware's
    | terminate step) instead of before it. false records inside the request, as before.
    */
    'record_after_response' => (bool) env('SYSTEM_OVERVIEW_RECORD_AFTER_RESPONSE', true),
];
