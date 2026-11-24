<?php

/*
 * Sales CRM settings.
 *
 * The whole module stays dark until CRM_ENABLED=true. Every /api/crm route and
 * every CRM scheduled job checks this flag, so the code can ship before the
 * team is ready to use it.
 */

return [
    'enabled' => (bool) env('CRM_ENABLED', false),

    /*
     * Placeholder business rules from the design. Owners can change these
     * without a code change.
     */
    'first_contact_hours' => (int) env('CRM_FIRST_CONTACT_HOURS', 24),
    'quote_discount_approval_percent' => (float) env('CRM_QUOTE_DISCOUNT_APPROVAL_PERCENT', 10),
    'quote_follow_up_days' => (int) env('CRM_QUOTE_FOLLOW_UP_DAYS', 5),
    'health_slowing_factor' => (float) env('CRM_HEALTH_SLOWING_FACTOR', 1.5),
    'health_at_risk_factor' => (float) env('CRM_HEALTH_AT_RISK_FACTOR', 3.0),
    'expiry_reminder_days' => (int) env('CRM_EXPIRY_REMINDER_DAYS', 30),

    /*
     * When a Dashboard client with no CRM contact books a shoot, create the
     * contact (owned by their account rep, or Unassigned).
     */
    'auto_create_from_shoots' => (bool) env('CRM_AUTO_CREATE_FROM_SHOOTS', true),

    /*
     * Email. Nothing is delivered for real until the transport is switched to
     * "resend" in the final integration stage; until then sends are recorded
     * with the exact content and marked as not delivered.
     */
    'email' => [
        'transport' => env('CRM_EMAIL_TRANSPORT', 'log'), // log | resend
        'sender_domain' => env('CRM_SENDER_DOMAIN', 'reprophotos.com'),
        'reply_domain' => env('CRM_REPLY_DOMAIN', 'reply.reprophotos.com'),
        'reply_tracking' => (bool) env('CRM_REPLY_TRACKING', false),
        'company_address' => env('CRM_COMPANY_ADDRESS', ''),
        'daily_send_cap_per_rep' => (int) env('CRM_DAILY_SEND_CAP_PER_REP', 300),
        'system_from' => env('CRM_SYSTEM_FROM', 'crm@reprophotos.com'),
        'logo_url' => env('CRM_EMAIL_LOGO_URL', 'https://reprodashboard.com/images/repro-email-logo-light.png'),
        // Where the public unsubscribe page lives (the Dashboard frontend).
        'public_base_url' => env('CRM_PUBLIC_BASE_URL', env('FRONTEND_URL', env('APP_URL', 'https://reprodashboard.com'))),
    ],

    /* Ask Robbie to review rep-written copy as well as the fixed rule list. */
    'content_check_ai' => (bool) env('CRM_CONTENT_CHECK_AI', false),

    /*
     * Robbie inside the CRM. The model is always passed explicitly: the
     * global defaults include an expensive model the owner has ruled out.
     */
    'robbie' => [
        'model' => env('CRM_ROBBIE_MODEL', 'gpt-6.1-terra'),
        'reasoning_effort' => env('CRM_ROBBIE_REASONING', 'medium'),
        'usage_feature' => 'crm_robbie',
        'review_usage_feature' => 'crm_content_check',
    ],

    'google_sync' => [
        'enabled' => (bool) env('CRM_GOOGLE_SYNC', false),
    ],
];
