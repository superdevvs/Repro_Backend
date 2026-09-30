<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;

/** The shoot balance reminder is separate from invoice due/overdue notices. */
final class ShootPaymentReminderTemplate
{
    public const SLUG = 'shoot-payment-reminder';

    public static function installMissing(): MessageTemplate
    {
        // Template copy is editable operational data. Never reset saved edits or
        // reactivate a template an operator intentionally disabled.
        return MessageTemplate::firstOrCreate(['slug' => self::SLUG], self::attributes());
    }

    public static function attributes(): array
    {
        return [
            'name' => 'Shoot Payment Reminder',
            'description' => 'Remaining balance reminder for a delivered shoot.',
            'channel' => 'EMAIL',
            'category' => 'PAYMENT',
            'subject' => 'Payment Reminder: {{shoot_location}}',
            'body_html' => '<p>Your delivered shoot at <strong>{{shoot_address}}</strong> has a remaining balance of <strong>${{remaining_balance}}</strong>.</p>'
                .'<p><a href="{{payment_link}}">Pay the remaining balance</a></p>'
                .'<p>You can also <a href="{{dashboard_link}}">view your shoot</a> in the dashboard. Full downloads unlock after payment is complete.</p>'
                .'<p>If you have already paid, please disregard this reminder.</p>',
            'body_text' => 'Payment reminder for {{shoot_address}}.' . "\n"
                .'Remaining balance: ${{remaining_balance}}.' . "\n"
                .'Pay securely: {{payment_link}}' . "\n"
                .'View shoot: {{dashboard_link}}' . "\n"
                .'Full downloads unlock after payment is complete. If you have already paid, please disregard this reminder.',
            'variables_json' => ['shoot_location', 'shoot_address', 'remaining_balance', 'payment_link', 'dashboard_link'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ];
    }
}
