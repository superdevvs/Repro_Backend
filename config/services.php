<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'dropbox' => [
        'enabled' => env('DROPBOX_ENABLED', false),
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/api/dropbox/callback',
        'access_token' => env('DROPBOX_ACCESS_TOKEN'),
        'refresh_token' => env('DROPBOX_REFRESH_TOKEN'),
    ],

    'square' => [
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'currency' => env('SQUARE_CURRENCY', 'USD'),
    ],

    'google' => [
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // LocationIQ (OSM-backed) for address autocomplete/geocoding
    'locationiq' => [
        'key' => env('LOCATIONIQ_API_KEY', 'pk.3a2d28377d12c16abd80db803710ff03'),
        'base_url' => env('LOCATIONIQ_BASE_URL', 'https://api.locationiq.com/v1'),
    ],

    // Geoapify for address autocomplete/geocoding
    'geoapify' => [
        'key' => env('GEOAPIFY_API_KEY', '26c00c91ab3744c5a6b89362001fe905'),
        'base_url' => env('GEOAPIFY_BASE_URL', 'https://api.geoapify.com/v1'),
    ],

    // Zillow / Bridge Data Output API
    'zillow' => [
        'client_id' => env('ZILLOW_CLIENT_ID', '5bOfqJUnM7v65ZflG5lF'),
        'client_secret' => env('ZILLOW_CLIENT_SECRET', 'lNU1jMbR8nssVbwZQPPAWN1z22Q0EN2aVG5sR3Zr'),
        'server_token' => env('ZILLOW_SERVER_TOKEN', '78c8cbd5fbbba256de6dc99f22e77d92'),
        'browser_token' => env('ZILLOW_BROWSER_TOKEN', '4f3d8422267deb1e05e83cc409b6bb61'),
        'base_url' => env('ZILLOW_BASE_URL', 'https://api.bridgedataoutput.com/api/v2'),
    ],

    // Address provider selector
    'address' => [
        // Supported: locationiq, zillow
        'provider' => env('ADDRESS_PROVIDER', 'zillow'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ayrshare' => [
        'api_key' => env('AYRSHARE_API_KEY'),
        'base_url' => env('AYRSHARE_BASE_URL', 'https://app.ayrshare.com/api'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'cubicasa' => [
        'api_key' => env('CUBICASA_API_KEY'),
        'environment' => env('CUBICASA_ENVIRONMENT', 'staging'),
        'base_url' => env('CUBICASA_BASE_URL', env('CUBICASA_ENVIRONMENT', 'staging') === 'production'
            ? 'https://app.cubi.casa/api/integrate/v3'
            : 'https://qa-customers.cubi.casa/api/integrate/v3'),
    ],

    'mightycall' => [
        'api_key' => env('MIGHTYCALL_API_KEY', 'a2ef1a6d-842a-4848-9777-0372d5fe5de0'),
        'secret_key' => env('MIGHTYCALL_SECRET_KEY'),
        'base_url' => env('MIGHTYCALL_BASE_URL', 'https://ccapi.mightycall.com/v4'),
        'webhook_secret' => env('MIGHTYCALL_WEBHOOK_SECRET'),
    ],

    // Bright MLS Integration
    'bright_mls' => [
        'api_url' => env('BRIGHT_MLS_API_URL', 'https://bright-manifestservices.tst.brightmls.com'),
        'api_user' => env('BRIGHT_MLS_API_USER'),
        'api_key' => env('BRIGHT_MLS_API_KEY'),
        'vendor_id' => env('BRIGHT_MLS_VENDOR_ID'),
        'vendor_name' => env('BRIGHT_MLS_VENDOR_NAME', 'Repro Photos'),
        'default_doc_visibility' => env('BRIGHT_MLS_DEFAULT_DOC_VISIBILITY', 'private'),
        'enabled' => env('BRIGHT_MLS_ENABLED', true),
    ],

    // iGUIDE Integration
    'iguide' => [
        'api_username' => env('IGUIDE_API_USERNAME'),
        'api_password' => env('IGUIDE_API_PASSWORD'),
        'api_key' => env('IGUIDE_API_KEY'),
        'base_url' => env('IGUIDE_API_URL', 'https://api.iguide.com'),
        'webhook_url' => env('IGUIDE_WEBHOOK_URL', env('APP_URL') . '/iguide_webhook.php'),
    ],

    // Fotello AI Photo Editing Integration
    'fotello' => [
        'api_key' => env('FOTELLO_API_KEY'),
        'base_url' => env('FOTELLO_BASE_URL', 'https://app.fotello.co/api'),
        'timeout' => env('FOTELLO_TIMEOUT', 120),
        'retry_attempts' => env('FOTELLO_RETRY_ATTEMPTS', 3),
    ],

    // MyMarketingMatters (MMM) Punchout/SSO Integration
    'mmm' => [
        'enabled' => env('MMM_ENABLED', true),
        'duns' => env('MMM_DUNS'),
        'shared_secret' => env('MMM_SHARED_SECRET'),
        'user_agent' => env('MMM_USER_AGENT', 'REPro Photos'),
        'punchout_url' => env('MMM_PUNCHOUT_URL'),
        'template_external_number' => env('MMM_TEMPLATE_EXTERNAL_NUMBER'),
        'deployment_mode' => env('MMM_DEPLOYMENT_MODE', 'test'),
        'start_point' => env('MMM_START_POINT', 'Category'),
        'to_identity' => env('MMM_TO_IDENTITY', ''),
        'sender_identity' => env('MMM_SENDER_IDENTITY', ''),
        'url_return' => env('MMM_URL_RETURN', env('APP_URL') . '/api/integrations/mmm/return'),
        'return_redirect_url' => env('MMM_RETURN_REDIRECT_URL'),
        'timeout' => env('MMM_TIMEOUT', 20),
    ],

    // Cakemail Email API Integration
    'cakemail' => [
        'username' => env('CAKEMAIL_USERNAME', 'contact@reprophotos.com'),
        'password' => env('CAKEMAIL_PASSWORD'),
        'sender_id' => env('CAKEMAIL_SENDER_ID'),
        'list_id' => env('CAKEMAIL_LIST_ID'),
        'base_url' => env('CAKEMAIL_BASE_URL', 'https://api.cakemail.dev'),
        'webhook_secret' => env('CAKEMAIL_WEBHOOK_SECRET'),
    ],

];
