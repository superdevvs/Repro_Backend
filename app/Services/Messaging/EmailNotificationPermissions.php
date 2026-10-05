<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Services\RolePermissionService;

/** Check the recipient's current permissions, including individual overrides. */
class EmailNotificationPermissions
{
    public const CATEGORIES = [
        'bookingUpdates' => 'Shoot booking and changes',
        'shootReminders' => 'Shoot reminders',
        'deliveryUpdates' => 'Shoot delivery and media updates',
        'payments' => 'Payment and invoice notifications',
        'other' => 'Other shoot notifications',
    ];

    public function category(array $payload): ?string
    {
        // Personal correspondence and security/account emails are not shoot subscriptions.
        if (in_array(strtoupper((string) ($payload['send_source'] ?? 'MANUAL')), ['MANUAL', 'PASSWORD_RESET'], true)) {
            return null;
        }

        $category = app(SmsNotificationPreferences::class)->category($payload);
        if ($category === 'accountUpdates') {
            return null;
        }

        $registry = app(\App\Services\SystemEmails\EmailTypeRegistry::class);
        $alias = strtoupper((string) ($payload['send_source'] ?? ''));
        if ($registry->has($alias)) {
            $type = $registry->definition($alias)->category;
            if (in_array($type, ['account', 'security'], true)) {
                return null;
            }
            if ($category === 'other') {
                $category = match ($type) {
                    'booking' => 'bookingUpdates',
                    'delivery' => 'deliveryUpdates',
                    'payment', 'invoice' => 'payments',
                    default => 'other',
                };
            }
        }

        return $category !== 'other' || ! empty($payload['related_shoot_id']) ? $category : null;
    }

    public function allows(string $address, ?string $category): bool
    {
        if ($category === null) {
            return true;
        }

        foreach (User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($address))])->get() as $recipient) {
            if (! app(RolePermissionService::class)->userCan($recipient, 'email-notifications', $category)) {
                return false;
            }
        }

        return true;
    }
}
