<?php

namespace App\Services\Messaging;

/** Short, editable SMS defaults. Email copy is intentionally managed separately. */
class SmsTemplateContent
{
    public static function automationDefinitions(): array
    {
        $labels = [
            'PHOTOGRAPHER_SHOOT_REMINDER' => 'Shoot reminder',
            'SHOOT_SCHEDULED' => 'Booking confirmed',
            'SHOOT_BOOKED' => 'Shoot assigned',
            'PHOTOGRAPHER_ASSIGNED' => 'Shoot assigned',
            'SHOOT_REQUEST_APPROVED' => 'Booking confirmed',
            'SHOOT_REQUEST_MODIFIED' => 'Booking updated',
            'SHOOT_UPDATED' => 'Shoot updated',
            'PHOTOGRAPHER_CHANGED' => '{{assignment_status}}',
            'SHOOT_COMPLETED' => 'Media ready',
            'SHOOT_CANCELED' => 'Shoot cancelled',
            'SHOOT_CANCELLED' => 'Shoot cancelled',
        ];
        $definitions = ['ACCOUNT_CREATED' => [
            'slug' => 'automation-account-created-sms',
            'body' => 'R/E Pro Photos: Account ready. Check your email for setup. Sign in: {{portal_url}}',
        ]];
        foreach ($labels as $trigger => $label) {
            $definitions[$trigger] = [
                'slug' => 'automation-'.strtolower(str_replace('_', '-', $trigger)).'-sms',
                'body' => self::shoot($label),
            ];
        }

        return $definitions;
    }

    public static function all(): array
    {
        $copy = array_column(self::automationDefinitions(), 'body', 'slug');

        return $copy + [
            'property-contact-reminder-sms' => self::shoot('Confirm property access'),
            'shoot-payment-reminder-sms' => self::shoot('Payment due'),
            'shoot-scheduled-sms' => self::shoot('Booking confirmed'),
            'shoot-requested-sms' => self::shoot('Shoot requested'),
            'shoot-updated-sms' => self::shoot('Shoot updated'),
            'shoot-reminder-sms' => self::shoot('Shoot reminder'),
            'photographer-changed-sms' => self::shoot('Photographer changed'),
            'shoot-on-hold-sms' => self::shoot('Shoot on hold'),
            'shoot-cancelled-sms' => self::shoot('Shoot cancelled'),
            'shoot-ready-sms' => self::shoot('Media ready'),
            'shoot-delivered-sms' => self::shoot('Media delivered'),
            'shoot-summary-sms' => self::shoot('Shoot summary'),
            'payment-due-sms' => self::shoot('Payment due'),
            'payment-receipt-sms' => self::shoot('Payment received'),
        ];
    }

    public static function forSlug(string $slug): ?string
    {
        return self::all()[$slug] ?? null;
    }

    public static function variables(string $body): array
    {
        preg_match_all('/{{([a-z_]+)}}/', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function shoot(string $label): string
    {
        return $label."\n{{shoot_address}}\n{{shoot_date}} {{shoot_time}}\nContact: {{sms_contact}}\nDetails: {{dashboard_link}}";
    }
}
