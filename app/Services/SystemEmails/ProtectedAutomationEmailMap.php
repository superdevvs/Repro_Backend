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
            'SHOOT_REQUEST_MODIFIED' => 'SHOOT_REQUEST_MODIFIED',
            'SHOOT_REQUESTED' => 'SHOOT_REQUESTED',
            'SHOOT_REQUEST_DECLINED' => 'SHOOT_REQUEST_DECLINED',
            'SHOOT_COMPLETED' => 'SHOOT_DELIVERED',
            'SHOOT_CANCELED' => 'SHOOT_CANCELLED',
            'SHOOT_CANCELLED' => 'SHOOT_CANCELLED',
            'SHOOT_REMOVED' => 'SHOOT_REMOVED',
            'PAYMENT_COMPLETED' => 'PAYMENT_COMPLETED',
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
     * The single seeded template that may override each protected email type.
     *
     * Keeping this map one-to-one avoids ambiguous "latest updated" selection
     * when multiple legacy templates represented the same protected workflow.
     *
     * @return array<string, string>
     */
    public function canonicalTemplateSlugToAlias(): array
    {
        return [
            'account-created' => 'ACCOUNT_CREATED',
            'shoot-scheduled' => 'SHOOT_SCHEDULED',
            'shoot-reminder' => 'SHOOT_REMINDER',
            'shoot-updated' => 'SHOOT_UPDATED',
            'shoot-request-modified' => 'SHOOT_REQUEST_MODIFIED',
            'shoot-requested' => 'SHOOT_REQUESTED',
            'shoot-request-declined' => 'SHOOT_REQUEST_DECLINED',
            'shoot-ready' => 'SHOOT_DELIVERED',
            'shoot-deleted' => 'SHOOT_REMOVED',
            'payment-thank-you' => 'PAYMENT_CONFIRMATION',
            'weekly-invoice-generated' => 'INVOICE_GENERATED',
            'shoot-cancelled' => 'SHOOT_CANCELLED',
            'photographer-changed' => 'PHOTOGRAPHER_CHANGED',
        ];
    }

    public function canonicalAliasForTemplateSlug(string $slug): ?string
    {
        return $this->canonicalTemplateSlugToAlias()[strtolower(trim($slug))] ?? null;
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
            'shoot-request-modified',
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
