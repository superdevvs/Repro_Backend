<?php

/*
 * Producer replay during a database freeze or a CRM pause.
 *
 * While ingress_hold is on, the listed producer routes answer 503 with
 * Retry-After before any middleware or controller can write, so the website
 * outbox, the booking forwarder, the contact form and Resend keep their own
 * copy and retry later. Off by default. See docs/mysql-lanes/producer-replay.md.
 */

return [
    'ingress_hold' => (bool) env('PRODUCER_INGRESS_HOLD', false),

    'retry_after_seconds' => max(1, (int) env('PRODUCER_INGRESS_RETRY_AFTER', 60)),

    // Request paths (Request::is patterns). Reads such as /api/external/services stay open.
    'held_paths' => [
        'api/external/recruiting/application',
        'api/external/website-email',
        'api/external/book-shoot',
        'api/webhooks/resend',
    ],
];
