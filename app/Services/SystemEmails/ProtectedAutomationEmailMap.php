<?php

namespace App\Services\SystemEmails;

class ProtectedAutomationEmailMap
{
    /**
     * @return array<string, string>
     */
    public function triggerToCanonicalAlias(): array
    {
        return [
            'ACCOUNT_CREATED' => 'ACCOUNT_CREATED',
            'PASSWORD_RESET' => 'PASSWORD_RESET',
            'SHOOT_BOOKED' => 'SHOOT_SCHEDULED',
            'SHOOT_SCHEDULED' => 'SHOOT_SCHEDULED',
            'SHOOT_REMINDER' => 'SHOOT_REMINDER',
            'SHOOT_UPDATED' => 'SHOOT_UPDATED',
            'SHOOT_REQUESTED' => 'SHOOT_REQUESTED',
            'SHOOT_REQUEST_DECLINED' => 'SHOOT_REQUEST_DECLINED',
            'SHOOT_COMPLETED' => 'SHOOT_DELIVERED',
            'SHOOT_CANCELED' => 'SHOOT_CANCELLED',
            'SHOOT_CANCELLED' => 'SHOOT_CANCELLED',
            'SHOOT_REMOVED' => 'SHOOT_REMOVED',
            'PAYMENT_COMPLETED' => 'PAYMENT_CONFIRMATION',
            'PHOTOGRAPHER_ASSIGNED' => 'SHOOT_SCHEDULED',
            'PHOTOGRAPHER_CHANGED' => 'PHOTOGRAPHER_CHANGED',
        ];
    }

    public function isProtectedTrigger(string $triggerType): bool
    {
        return array_key_exists(strtoupper($triggerType), $this->triggerToCanonicalAlias());
    }

    public function canonicalAliasForTrigger(string $triggerType): ?string
    {
        return $this->triggerToCanonicalAlias()[strtoupper($triggerType)] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function protectedTriggers(): array
    {
        return array_keys($this->triggerToCanonicalAlias());
    }

    /**
     * @return array<int, string>
     */
    public function legacyProtectedTemplateSlugs(): array
    {
        return [
            'account-created',
            'shoot-scheduled',
            'shoot-reminder',
            'shoot-updated',
            'shoot-requested',
            'shoot-request-declined',
            'shoot-ready',
            'shoot-deleted',
            'payment-thank-you',
            'photographer-assigned',
            'photographer-changed',
        ];
    }
}
