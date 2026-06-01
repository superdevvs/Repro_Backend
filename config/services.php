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

    'stripe' => [
        'secret_key'      => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
        'currency'        => env('STRIPE_CURRENCY', 'USD'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', env('GOOGLE_CALENDAR_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', env('GOOGLE_CALENDAR_CLIENT_SECRET')),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('GOOGLE_CALENDAR_REDIRECT_URI', env('APP_URL') . '/api/google-calendar/callback')),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
        'calendar' => [
            'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
            'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
            'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI', env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/api/google-calendar/callback')),
            'scope' => env('GOOGLE_CALENDAR_SCOPE', 'openid email https://www.googleapis.com/auth/calendar.events'),
            'auth_url' => env('GOOGLE_CALENDAR_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url' => env('GOOGLE_CALENDAR_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'userinfo_url' => env('GOOGLE_CALENDAR_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
            'base_url' => env('GOOGLE_CALENDAR_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
            'default_calendar_id' => env('GOOGLE_CALENDAR_DEFAULT_CALENDAR_ID', 'primary'),
        ],
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
        'legacy_lookup_url' => env('ZILLOW_LEGACY_LOOKUP_URL', 'https://pro.reprophotos.com/get_zillow_info.php'),
    ],

    // Address provider selector
    'address' => [
        // Supported: google, locationiq, geoapify, zillow
        'provider' => env('ADDRESS_PROVIDER', 'google'),
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
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

    'cubicasa' => [
        'api_key' => env('CUBICASA_API_KEY'),
        'environment' => env('CUBICASA_ENVIRONMENT', 'staging'),
        'base_url' => env('CUBICASA_BASE_URL', env('CUBICASA_ENVIRONMENT', 'staging') === 'production'
            ? 'https://app.cubi.casa/api/integrate/v3'
            : 'https://qa-customers.cubi.casa/api/integrate/v3'),
        // Public URL CubiCasa should POST status events to. Defaults to {APP_URL}/cubicasa_webhook.php.
        'webhook_url' => env('CUBICASA_WEBHOOK_URL'),
        // Optional shared secret for webhook signature verification (header pinned at first delivery).
        'webhook_secret' => env('CUBICASA_WEBHOOK_SECRET'),
    ],

    'telnyx' => [
        // REST API bearer token (Telnyx Portal -> Keys & Credentials -> API Keys)
        'api_key' => env('TELNYX_API_KEY'),
        // Ed25519 base64 public key for webhook signature verification (Telnyx Portal -> Keys & Credentials -> Public Key)
        'public_key' => env('TELNYX_PUBLIC_KEY'),
        'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
        'from_number' => env('TELNYX_FROM_NUMBER'),
        // Optional default Telnyx phone_number id (UUID-like string from /v2/phone_numbers)
        'phone_number_id' => env('TELNYX_PHONE_NUMBER_ID'),
        'default_label' => env('TELNYX_DEFAULT_LABEL', 'Telnyx SMS'),
        'api_base' => rtrim(env('TELNYX_API_BASE', 'https://api.telnyx.com/v2'), '/'),
        'webhook_tolerance_seconds' => (int) env('TELNYX_WEBHOOK_TOLERANCE_SECONDS', 300),

        // AI SMS Agent (Phase B). All flags off-by-default; enable via env on rollout.
        'ai_sms_enabled' => env('TELNYX_AI_SMS_ENABLED', false),
        'ai_takeover_pause_minutes' => (int) env('TELNYX_AI_TAKEOVER_PAUSE_MINUTES', 120),
        'ai_session_idle_ttl_minutes' => (int) env('TELNYX_AI_SESSION_IDLE_TTL_MINUTES', 1440),
        'ai_pending_action_ttl_minutes' => (int) env('TELNYX_AI_PENDING_ACTION_TTL_MINUTES', 10),
        'ai_max_segments' => (int) env('TELNYX_AI_SMS_MAX_SEGMENTS', 3),
        'ai_max_replies_per_hour' => (int) env('TELNYX_AI_SMS_MAX_REPLIES_PER_HOUR', 20),
        'ai_verification_ttl_minutes' => (int) env('TELNYX_AI_VERIFICATION_TTL_MINUTES', 10),
        'ai_static_replies' => [
            'stop' => env('TELNYX_AI_STATIC_STOP', "You're unsubscribed from RepRO SMS. Reply START to resume."),
            'start' => env('TELNYX_AI_STATIC_START', "You're resubscribed. Standard rates may apply."),
            'help' => env('TELNYX_AI_STATIC_HELP', 'RepRO support: contact@reprophotos.com. Reply STOP to opt out.'),
        ],
        'voice' => [
            'enabled' => env('TELNYX_VOICE_ENABLED', false),
            'assistant_id' => env('TELNYX_VOICE_ASSISTANT_ID'),
            'connection_id' => env('TELNYX_VOICE_CONNECTION_ID'),
            'webhook_url' => env('TELNYX_VOICE_WEBHOOK_URL'),
            'recording_enabled' => env('TELNYX_VOICE_RECORDING_ENABLED', true),
            'support_handoff_number' => env('TELNYX_VOICE_SUPPORT_HANDOFF_NUMBER'),
            'allow_unverified_transfer' => env('TELNYX_VOICE_ALLOW_UNVERIFIED_TRANSFER', true),
            'disclosure_text' => env('TELNYX_VOICE_DISCLOSURE_TEXT', "Hi, this is Robbie, RePro's AI assistant. This call may be recorded and transcribed to help manage your booking. Stay on the line to continue, or say 'human' to reach a person."),
        ],
        'tool_bridge' => [
            'secret' => env('TELNYX_TOOL_BRIDGE_SECRET'),
            'tolerance_seconds' => (int) env('TELNYX_TOOL_BRIDGE_TOLERANCE_SECONDS', 300),
            'idempotency_ttl_seconds' => (int) env('TELNYX_TOOL_BRIDGE_IDEMPOTENCY_TTL', 86400),
            'debug_capture' => env('TELNYX_TOOL_BRIDGE_DEBUG_CAPTURE', false),
            'raw_capture_disk' => env('TELNYX_TOOL_BRIDGE_RAW_DISK', 'local'),
            'raw_capture_ttl_days' => (int) env('TELNYX_TOOL_BRIDGE_RAW_TTL_DAYS', 7),
        ],
        'assistant_sms' => [
            'enabled' => env('TELNYX_ASSISTANT_SMS_ENABLED', false),
            'assistant_id' => env('TELNYX_ASSISTANT_SMS_ID'),
        ],
        'webhook_events' => [
            'raw_retention_days' => (int) env('TELNYX_WEBHOOK_RAW_RETENTION_DAYS', 30),
        ],
    ],

    // QA / test harness configuration
    'qa' => [
        // Outbound destination number used by QA/test scripts (e.g. the SMS check).
        // MUST be a valid, owned E.164 number. The documented default below uses the
        // reserved North American 555-01xx test range, which is safe for non-delivering
        // test runs; override per environment with QA_OUTBOUND_TEST_NUMBER as needed.
        'outbound_test_number' => env('QA_OUTBOUND_TEST_NUMBER', '+12025550100'),
    ],

    // Bright MLS Integration
    'bright_mls' => [
        'api_mode' => env('BRIGHT_MLS_API_MODE', 'new'),
        'environment' => env('BRIGHT_MLS_ENVIRONMENT', 'p1'),
        'api_url' => env('BRIGHT_MLS_API_URL', 'https://agl1paz1msaasservices.bright-solutions.co'),
        'import_url_base' => env('BRIGHT_MLS_IMPORT_URL_BASE', 'https://agl1paz1msaasservices.bright-solutions.co'),
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
        'app_id' => env('IGUIDE_APP_ID'),
        'app_token' => env('IGUIDE_APP_TOKEN'),
        'base_url' => env('IGUIDE_API_URL', 'https://manage.youriguide.com/api/v1'),
        'legacy_base_url' => env('IGUIDE_LEGACY_API_URL', 'https://api.iguide.com'),
        'webhook_url' => env('IGUIDE_WEBHOOK_URL', env('APP_URL') . '/iguide_webhook.php'),
        // Optional shared secret used to verify HMAC-SHA256 signature on webhook bodies.
        'webhook_secret' => env('IGUIDE_WEBHOOK_SECRET'),
    ],

    // Autoenhance AI Photo Editing Integration
    'autoenhance' => [
        'api_key' => env('AUTOENHANCE_API_KEY'),
        'base_url' => env('AUTOENHANCE_BASE_URL', 'https://api.autoenhance.ai'),
        'api_version' => env('AUTOENHANCE_API_VERSION', '2025-05-05'),
        'timeout' => env('AUTOENHANCE_TIMEOUT', 120),
        'retry_attempts' => env('AUTOENHANCE_RETRY_ATTEMPTS', 3),
        'dev_mode' => env('AUTOENHANCE_DEV_MODE', false),
        'webhook_secret' => env('AUTOENHANCE_WEBHOOK_SECRET'),
    ],

    // Higgsfield AI Video Generation Integration
    'higgsfield' => [
        'api_key' => env('HIGGSFIELD_API_KEY'),
        'api_secret' => env('HIGGSFIELD_API_SECRET'),
        'base_url' => env('HIGGSFIELD_BASE_URL', 'https://platform.higgsfield.ai'),
        'timeout' => env('HIGGSFIELD_TIMEOUT', 120),
        'retry_attempts' => env('HIGGSFIELD_RETRY_ATTEMPTS', 3),
        'video_model' => env('HIGGSFIELD_VIDEO_MODEL', 'kling-video/v2.1/pro/image-to-video'),
        'image_model' => env('HIGGSFIELD_IMAGE_MODEL', 'higgsfield-ai/soul/reference'),
    ],

    // fal.ai Listing Video Generation Integration
    'fal' => [
        'key' => env('FAL_KEY'),
        'model' => env('FAL_MODEL', 'fal-ai/wan-pro/image-to-video'),
        'test_mode' => env('FAL_TEST_MODE', false),
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
        'start_point' => env('MMM_START_POINT', 'category'),
        'to_identity' => env('MMM_TO_IDENTITY', ''),
        'sender_identity' => env('MMM_SENDER_IDENTITY', ''),
        'url_return' => env('MMM_URL_RETURN', env('APP_URL') . '/api/integrations/mmm/return'),
        'return_redirect_url' => env('MMM_RETURN_REDIRECT_URL'),
        'timeout' => env('MMM_TIMEOUT', 20),
    ],

    // External Booking API (for Lovable / third-party sites)
    'external_booking' => [
        'api_key' => env('EXTERNAL_BOOKING_API_KEY'),
    ],

    // Cakemail Email API Integration
    'cakemail' => [
        'username' => env('CAKEMAIL_USERNAME', 'contact@reprophotos.com'),
        'password' => env('CAKEMAIL_PASSWORD'),
        'sender_id' => env('CAKEMAIL_SENDER_ID'),
        'list_id' => env('CAKEMAIL_LIST_ID'),
        'base_url' => env('CAKEMAIL_BASE_URL'),
        'webhook_secret' => env('CAKEMAIL_WEBHOOK_SECRET'),
    ],

];
