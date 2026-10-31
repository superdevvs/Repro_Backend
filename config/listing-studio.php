<?php

return [
    // Paid ordering is separate from both legacy requests and free trial admission.
    'paid_enabled' => (bool) env('LISTING_STUDIO_PAID_ENABLED', false),
    'paid_checkout_enabled' => (bool) env('LISTING_STUDIO_PAID_CHECKOUT_ENABLED', false),
    'human_revisions_enabled' => (bool) env('LISTING_STUDIO_HUMAN_REVISIONS_ENABLED', false),
    'paid_services' => [
        'staging' => (bool) env('LISTING_STUDIO_PAID_STAGING_ENABLED', false),
        'decluttering' => (bool) env('LISTING_STUDIO_PAID_DECLUTTERING_ENABLED', false),
        'grass' => (bool) env('LISTING_STUDIO_PAID_GRASS_ENABLED', false),
        'twilight' => (bool) env('LISTING_STUDIO_PAID_TWILIGHT_ENABLED', false),
    ],
    'services' => [
        'staging' => ['name' => 'Virtual staging', 'ai_price_cents' => 1500, 'revision_price_cents' => 3000],
        'decluttering' => ['name' => 'Decluttering', 'ai_price_cents' => 1200, 'revision_price_cents' => 1500],
        'grass' => ['name' => 'Grass enhancement', 'ai_price_cents' => 700, 'revision_price_cents' => 1500],
        'twilight' => ['name' => 'Virtual twilight', 'ai_price_cents' => 1000, 'revision_price_cents' => 1500],
    ],
    // Enable after the matching account, webhook subscriptions and queue are configured.
    'stripe_enabled' => (bool) env('LISTING_STUDIO_STRIPE_ENABLED', false),
    'stripe_secret_key' => env('LISTING_STUDIO_STRIPE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
    'webhook_secret' => env('LISTING_STUDIO_STRIPE_WEBHOOK_SECRET'),
    'stripe_account_id' => env('LISTING_STUDIO_STRIPE_ACCOUNT_ID', 'acct_15OuBBLsiebrQZS4'),
    'stripe_mode' => env('LISTING_STUDIO_STRIPE_MODE', 'live'),
    'plans' => [
        'starter' => ['name' => 'Starter', 'live_price' => 'price_1UKekLLsiebrQZS44LVexrW5', 'test_price' => env('LISTING_STUDIO_STARTER_TEST_PRICE'), 'revision_discount_percent' => 0, 'monthly_credit_cents' => 6000, 'price_cents' => 4900, 'currency' => 'usd'],
        'pro' => ['name' => 'Pro', 'live_price' => 'price_1UKel0LsiebrQZS4jGdQ1odm', 'test_price' => env('LISTING_STUDIO_PRO_TEST_PRICE'), 'revision_discount_percent' => 20, 'monthly_credit_cents' => 13500, 'price_cents' => 9900, 'currency' => 'usd'],
        'studio' => ['name' => 'Studio', 'live_price' => 'price_1UKeliLsiebrQZS4kLxw418v', 'test_price' => env('LISTING_STUDIO_STUDIO_TEST_PRICE'), 'revision_discount_percent' => 30, 'monthly_credit_cents' => 37500, 'price_cents' => 24900, 'currency' => 'usd'],
    ],
];
