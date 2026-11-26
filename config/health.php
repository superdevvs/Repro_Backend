<?php

return [
    /*
    | The engine the default database connection must use: sqlite or mysql.
    | When set, /up answers 503 on any other engine and every query on the
    | default connection stops with DatabaseEngineMismatch. Empty turns both
    | checks off.
    */
    'expected_engine' => (string) env('DB_EXPECTED_ENGINE', ''),

    /*
    | On MySQL only: /up also compares @@server_uuid with this value. Empty
    | skips the comparison. Same variable as the MySQL connector uses.
    */
    'mysql_server_uuid' => (string) env('REPRO_MYSQL_EXPECTED_SERVER_UUID', ''),
];
