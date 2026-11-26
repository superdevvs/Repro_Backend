<?php

return [
    /*
    | Log every database transaction that runs longer than threshold_ms to the
    | "transactions" channel (storage/logs/transactions-YYYY-MM-DD.log). Each
    | record has the duration, the time to the first write, how long the
    | writer was held, query and write counts, and the code line that opened
    | the transaction. Off by default; turn on for the one-week measurement.
    */
    'enabled' => (bool) env('TRANSACTION_TIMING_ENABLED', false),

    'threshold_ms' => (int) env('TRANSACTION_TIMING_THRESHOLD_MS', 250),

    'channel' => 'transactions',
];
