<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Outbound external delivery
    |---------------------------------------------------------------------------
    |
    | Local QA reached the live Telnyx API because the local environment file
    | carries real provider credentials. Credentials alone must not decide
    | whether a message leaves the building — the environment does.
    |
    | Behaviour by environment:
    |   production            delivery allowed per normal provider configuration
    |   testing               always blocked; providers are faked as well
    |   local / development   blocked unless explicitly opted in, and then only
    |                         to allowlisted recipients
    |
    | `allow_external` is the explicit opt-in. It has no effect in `testing`,
    | which can never send, and it is ignored in `production`, which is governed
    | by provider configuration as before.
    |
    */

    'allow_external' => env('MESSAGING_ALLOW_EXTERNAL_DELIVERY', false),

    /*
    | Comma-separated recipients (phone numbers and/or email addresses) that may
    | receive real messages when the opt-in above is enabled outside production.
    | An empty allowlist means the opt-in delivers to nobody, which is the safe
    | reading of "allow real delivery" without naming a target.
    */

    'allowlist' => env('MESSAGING_DELIVERY_ALLOWLIST', ''),

    /*
    | Recipients that are never real, regardless of opt-in, outside production.
    | 555-01xx is the reserved North American fictitious range, and the reserved
    | example/invalid/test domains come from RFC 2606 / RFC 6761. Fixture data
    | uses these, and a fixture must not be able to reach a paid provider.
    */

    'fixture_phone_patterns' => [
        '/^\+?1?555\d{7}$/',
        '/^\+?1?\d{3}555\d{4}$/',
        '/^\+?0+$/',
        '/^\+?1?123456789\d?$/',
    ],

    'fixture_email_domains' => [
        'example.com',
        'example.org',
        'example.net',
        'example.test',
        'test',
        'invalid',
        'localhost',
        'local',
    ],

];
