<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | CakeMail is the only mail provider for this application.
    | All emails are sent through CakeMail.
    |
    */

    'default' => 'cakemail',

    /*
    |--------------------------------------------------------------------------
    | CakeMail Configuration
    |--------------------------------------------------------------------------
    |
    | CakeMail mailer configuration. This is the only mailer used by the
    | application. Configure credentials in .env file.
    |
    */

    'mailers' => [

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
