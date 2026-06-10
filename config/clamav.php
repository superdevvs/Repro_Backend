<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClamAV (clamd) Connection
    |--------------------------------------------------------------------------
    |
    | Connection settings for a self-hosted ClamAV daemon (clamd). Uploaded
    | ShootFiles are submitted to clamd over the INSTREAM protocol (Req 14).
    |
    | Two transports are supported:
    |   - Unix socket: set CLAMAV_SOCKET to clamd's LocalSocket path. When set,
    |     it takes precedence over the TCP host/port.
    |   - TCP: set CLAMAV_HOST + CLAMAV_PORT to clamd's TCPAddr/TCPSocket.
    |
    */

    // Absolute path to clamd's unix socket (e.g. /var/run/clamav/clamd.ctl).
    // When non-empty this is preferred over the TCP host/port below.
    'socket' => env('CLAMAV_SOCKET'),

    // TCP host/port for clamd (used when no unix socket is configured).
    'host' => env('CLAMAV_HOST', '127.0.0.1'),
    'port' => (int) env('CLAMAV_PORT', 3310),

    // Seconds to wait when establishing the connection to clamd. A connect
    // failure within this window surfaces as a ClamAvUnavailable exception so
    // the scan job keeps the file quarantined and retries (Req 15.2).
    'connect_timeout' => (int) env('CLAMAV_CONNECT_TIMEOUT', 10),

    // Seconds to wait for read/write on the established stream.
    'read_timeout' => (int) env('CLAMAV_READ_TIMEOUT', 60),

    // Chunk size (bytes) for streaming file contents to clamd via INSTREAM.
    // Must not exceed clamd's StreamMaxLength.
    'chunk_size' => (int) env('CLAMAV_CHUNK_SIZE', 8192),

];
