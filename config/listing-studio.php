<?php

return [
    // Enable after the matching account, webhook subscriptions and queue are configured.
    'stripe_enabled' => (bool) env('LISTING_STUDIO_STRIPE_ENABLED', false),
    'stripe_secret_key' => env('LISTING_STUDIO_STRIPE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
    'webhook_secret' => env('LISTING_STUDIO_STRIPE_WEBHOOK_SECRET'),
    'stripe_account_id' => env('LISTING_STUDIO_STRIPE_ACCOUNT_ID', 'acct_15OuBBLsiebrQZS4'),
    'stripe_mode' => env('LISTING_STUDIO_STRIPE_MODE', 'live'),
    'plans' => [
        'starter' => ['name' => 'Starter', 'live_price' => 'price_1UKekLLsiebrQZS44LVexrW5', 'test_price' => env('LISTING_STUDIO_STARTER_TEST_PRICE'), 'monthly_credit_cents' => 6000, 'price_cents' => 4900, 'currency' => 'usd'],
        'pro' => ['name' => 'Pro', 'live_price' => 'price_1UKel0LsiebrQZS4jGdQ1odm', 'test_price' => env('LISTING_STUDIO_PRO_TEST_PRICE'), 'monthly_credit_cents' => 13500, 'price_cents' => 9900, 'currency' => 'usd'],
        'studio' => ['name' => 'Studio', 'live_price' => 'price_1UKeliLsiebrQZS4kLxw418v', 'test_price' => env('LISTING_STUDIO_STUDIO_TEST_PRICE'), 'monthly_credit_cents' => 37500, 'price_cents' => 24900, 'currency' => 'usd'],
    ],
];
