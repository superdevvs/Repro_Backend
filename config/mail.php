<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | All transactional emails are sent via CakeMail REST API
    | (MessagingService → CakemailProvider). The log mailer is kept
    | as a fallback for local development / debugging only.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

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

    'contact_phone' => env('MAIL_CONTACT_PHONE', '202-868-1113'),

    'accounting_address' => env('MAIL_ACCOUNTING_ADDRESS', 'accounting@reprophotos.com'),

    'editing_team_address' => env('MAIL_EDITING_TEAM_ADDRESS', 'editing@reprophotos.com'),

];
