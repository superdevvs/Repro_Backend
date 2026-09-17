<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            // Web workers and deployment CLI share the runtime group.
            'permission' => 0660,
        ],

        'daily' => [
            'driver' => 'daily',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        // Bounded authentication events must remain visible when general
        // production diagnostics are restricted to error severity.
        'auth-security' => [
            'driver' => 'daily',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'path' => storage_path('logs/auth-security.log'),
            'level' => 'notice',
            'days' => 14,
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        // Media intake diagnostics. Production runs LOG_LEVEL=error, which is why
        // a night of 69 failed RAW uploads left nothing at all in laravel.log:
        // the per-file failure was a warning. Every accepted file, every failed
        // file with the driver's own message, and every lock retry lands here so
        // a photographer's report can be traced by shoot, batch and correlation
        // id regardless of the general log level.
        //
        // Level is pinned to info (not env('LOG_LEVEL')) so this channel still
        // writes when production diagnostics are error-only. PrivacyLogTap is
        // intentionally omitted: that processor rewrites messages and drops
        // string correlation ids, which would make this channel unusable.
        'uploads' => [
            'driver' => 'daily',
            'path' => storage_path('logs/uploads.log'),
            'level' => 'info',
            'days' => env('LOG_UPLOADS_DAYS', 30),
            'replace_placeholders' => true,
            'permission' => 0660,
        ],

        'slack' => [
            'driver' => 'slack',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'tap' => [\App\Logging\PrivacyLogTap::class],
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
