<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | Choose the mailer via MAIL_MAILER. Defaults to CakeMail.
    |
    */

    'default' => env('MAIL_MAILER', 'cakemail'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure mailers in .env. CakeMail remains the default provider.
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
        ],

        'cakemail' => [
            'transport' => 'smtp',
            'host' => env('CAKEMAIL_HOST', 'smtp.cakemail.com'),
            'port' => env('CAKEMAIL_PORT', 587),
            'encryption' => env('CAKEMAIL_ENCRYPTION', 'tls'),
            'username' => env('CAKEMAIL_USERNAME'),
            'password' => env('CAKEMAIL_PASSWORD'),
            'timeout' => null,
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | All emails sent by the application will use this from address.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@reprophotos.com'),
        'name' => env('MAIL_FROM_NAME', 'REPro Photos'),
    ],

    'contact_address' => env('MAIL_CONTACT_ADDRESS', 'contact@reprophotos.com'),

    'accounting_address' => env('MAIL_ACCOUNTING_ADDRESS', 'accounting@reprophotos.com'),

    'editing_team_address' => env('MAIL_EDITING_TEAM_ADDRESS', 'editing@reprophotos.com'),

];
