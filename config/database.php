<?php

use Illuminate\Support\Str;

$defaultSqlitePath = database_path('database.sqlite');

$sqliteDatabasePath = env('DB_DATABASE');

if ($sqliteDatabasePath === null || $sqliteDatabasePath === '') {
    $sqliteDatabasePath = $defaultSqlitePath;
} elseif ($sqliteDatabasePath !== ':memory:') {
    $driveLetterAbsolute = strlen($sqliteDatabasePath) > 2
        && ctype_alpha($sqliteDatabasePath[0])
        && $sqliteDatabasePath[1] === ':'
        && in_array($sqliteDatabasePath[2], ['\\', '/'], true);

    $isAbsolutePath = Str::startsWith($sqliteDatabasePath, ['/', '\\']) || $driveLetterAbsolute;

    if (! $isAbsolutePath) {
        $sqliteDatabasePath = base_path($sqliteDatabasePath);
    }
}

$mysql = [
    'driver' => 'mysql',
    'repro_require_server_identity' => env('APP_ENV', 'production') === 'production',
    'repro_expected_server_uuid' => env('REPRO_MYSQL_EXPECTED_SERVER_UUID'),
    'repro_route_budget_writer_token' => env('REPRO_MYSQL_ROUTE_BUDGET_WRITER_TOKEN', false) === true,
    // One writer at a time, as on SQLite: every write on this connection first
    // locks repro_mysql_control.writer_token. Exactly one connection carries it.
    // On by default for MySQL; only an explicit false turns it off. No effect on SQLite.
    'repro_writer_policy' => env('REPRO_MYSQL_WRITER_POLICY', true) !== false,
    'repro_writer_long_hold_ms' => (int) env('REPRO_MYSQL_WRITER_LONG_HOLD_MS', 1000),
    // A row lock outside a transaction protects nothing: an error in tests, a log line elsewhere.
    'repro_level0_lock_guard' => env('REPRO_MYSQL_LEVEL0_LOCK_GUARD', env('APP_ENV', 'production') === 'testing' ? 'throw' : 'log'),
    // Fixed, not tuning knobs: the writer token refuses any other session settings.
    'isolation_level' => 'READ COMMITTED',
    'repro_lock_wait_timeout' => 5,
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    | What a long-lived worker does with a transaction a job or in-process
    | scheduled task left open: 'rollback', or 'log' only. On MySQL a leaked
    | transaction would hold the writer token until the worker dies.
    */
    'repro_leftover_transactions' => env('REPRO_DB_LEFTOVER_TRANSACTIONS',
        env('DB_CONNECTION', 'sqlite') === 'mysql' ? 'rollback' : 'log'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => $sqliteDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => env('DB_BUSY_TIMEOUT'),
            'journal_mode' => env('DB_JOURNAL_MODE'),
            'synchronous' => env('DB_SYNCHRONOUS'),
        ],

        'mysql' => $mysql,

        'mysql_queue_worker' => [
            ...$mysql,
            'repro_require_server_identity' => true,
            'repro_route_budget_writer_token' => false,
            'url' => null,
            'repro_writer_policy' => false,
            'repro_writer_exempt_tables' => ['jobs'],
            'username' => env('REPRO_MYSQL_QUEUE_WORKER_USERNAME'),
            'password' => env('REPRO_MYSQL_QUEUE_WORKER_PASSWORD'),
        ],

        'mysql_scheduling_cache' => [
            ...$mysql,
            'repro_require_server_identity' => true,
            'repro_route_budget_writer_token' => false,
            'repro_scheduling_cache_only' => true,
            'repro_writer_policy' => false,
            'repro_writer_exempt_tables' => ['cache', 'cache_locks'],
            'url' => null,
            'username' => env('REPRO_MYSQL_SCHEDULING_CACHE_USERNAME'),
            'password' => env('REPRO_MYSQL_SCHEDULING_CACHE_PASSWORD'),
        ],

        // Database cache locks on MySQL. Same account, separate session, no writer
        // token, and only cache_locks may be written. A process that holds the token
        // on 'mysql' can still take or release a lock without waiting for itself.
        'mysql_cache_locks' => [
            ...$mysql,
            'repro_route_budget_writer_token' => false,
            'repro_writer_policy' => false,
            'repro_writer_exempt_tables' => ['cache_locks'],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
