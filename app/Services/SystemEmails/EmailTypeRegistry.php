<?php

namespace App\Services\SystemEmails;

use InvalidArgumentException;

class EmailTypeRegistry
{
    /**
     * @var array<string, EmailTypeDefinition>|null
     */
    private ?array $definitions = null;

    public function definition(string $alias): EmailTypeDefinition
    {
        $definitions = $this->definitions();

        if (!isset($definitions[$alias])) {
            throw new InvalidArgumentException("Unknown protected email type [{$alias}].");
        }

        return $definitions[$alias];
    }

    public function has(string $alias): bool
    {
        return isset($this->definitions()[$alias]);
    }

    /**
     * @return array<int, string>
     */
    public function protectedAliases(): array
    {
        return array_keys($this->definitions());
    }

    public function isProtectedAlias(string $alias): bool
    {
        return $this->has($alias);
    }

    /**
     * @return array<string, EmailTypeDefinition>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $account = ['recipient', 'account', 'links', 'branding', 'meta'];
        $shoot = ['recipient', 'shoot', 'branding', 'meta'];
        $invoice = ['recipient', 'invoice', 'branding', 'meta'];

        return $this->definitions = [
            'ACCOUNT_CREATED' => new EmailTypeDefinition('ACCOUNT_CREATED', 1, 'account', 'emails.account_created', 'v1', $account, ['client']),
            'CLIENT_EMAIL_VERIFICATION' => new EmailTypeDefinition('CLIENT_EMAIL_VERIFICATION', 1, 'account', 'emails.client_email_verification', 'v1', $account, ['client']),
            'CLIENT_EMAIL_VERIFIED' => new EmailTypeDefinition('CLIENT_EMAIL_VERIFIED', 1, 'account', 'emails.client_email_verified', 'v1', $account, ['client']),
            'PHOTOGRAPHER_EQUIPMENT_VERIFICATION' => new EmailTypeDefinition('PHOTOGRAPHER_EQUIPMENT_VERIFICATION', 1, 'account', 'emails.photographer_equipment_verification', 'v1', $account, ['photographer']),
            'PASSWORD_RESET' => new EmailTypeDefinition('PASSWORD_RESET', 1, 'security', 'emails.password_reset', 'v1', $account, ['client', 'photographer', 'rep', 'admin']),
            'SHOOT_SCHEDULED' => new EmailTypeDefinition('SHOOT_SCHEDULED', 1, 'booking', 'emails.shoot_scheduled', 'v1', $shoot, ['client', 'photographer']),
            'SHOOT_UPDATED' => new EmailTypeDefinition('SHOOT_UPDATED', 1, 'booking', 'emails.shoot_updated', 'v1', $shoot, ['client', 'photographer']),
            'SHOOT_REMINDER' => new EmailTypeDefinition('SHOOT_REMINDER', 1, 'booking', 'emails.shoot_reminder', 'v1', $shoot, ['client', 'photographer']),
            'SHOOT_REMOVED' => new EmailTypeDefinition('SHOOT_REMOVED', 1, 'booking', 'emails.shoot_removed', 'v1', $shoot, ['client', 'photographer']),
            'SHOOT_REQUEST_DECLINED' => new EmailTypeDefinition('SHOOT_REQUEST_DECLINED', 1, 'booking', 'emails.shoot_request_declined', 'v1', $shoot, ['client']),
            'SHOOT_REQUESTED' => new EmailTypeDefinition('SHOOT_REQUESTED', 1, 'booking', 'emails.shoot_requested', 'v1', $shoot, ['client', 'admin']),
            'SHOOT_CANCELLATION_REQUESTED' => new EmailTypeDefinition('SHOOT_CANCELLATION_REQUESTED', 1, 'booking', 'emails.shoot_cancellation_requested', 'v1', $shoot, ['client', 'photographer']),
            'SHOOT_DELIVERED' => new EmailTypeDefinition('SHOOT_DELIVERED', 1, 'delivery', 'emails.shoot_delivered', 'v1', $shoot, ['client']),
            'PAYMENT_CONFIRMATION' => new EmailTypeDefinition('PAYMENT_CONFIRMATION', 1, 'payment', 'emails.payment_confirmation', 'v1', ['recipient', 'shoot', 'payment', 'branding', 'meta'], ['client']),
            'INVOICE_GENERATED' => new EmailTypeDefinition('INVOICE_GENERATED', 1, 'invoice', 'emails.invoice_generated', 'v1', $invoice, ['photographer', 'rep']),
            'INVOICE_PENDING_APPROVAL' => new EmailTypeDefinition('INVOICE_PENDING_APPROVAL', 1, 'invoice', 'emails.invoice_pending_approval', 'v1', $invoice, ['admin']),
            'INVOICE_APPROVED' => new EmailTypeDefinition('INVOICE_APPROVED', 1, 'invoice', 'emails.invoice_approved', 'v1', $invoice, ['photographer', 'rep']),
            'INVOICE_REJECTED' => new EmailTypeDefinition('INVOICE_REJECTED', 1, 'invoice', 'emails.invoice_rejected', 'v1', $invoice, ['photographer', 'rep']),
            'SHOOT_PAID' => new EmailTypeDefinition('SHOOT_PAID', 1, 'payment', 'emails.shoot_paid', 'v1', ['recipient', 'shoot', 'branding', 'meta'], ['client']),
            'SHOOT_CANCELLED' => new EmailTypeDefinition('SHOOT_CANCELLED', 1, 'booking', 'emails.shoot_cancelled', 'v1', $shoot, ['client', 'photographer']),
            'PHOTOGRAPHER_CHANGED' => new EmailTypeDefinition('PHOTOGRAPHER_CHANGED', 1, 'booking', 'emails.photographer_changed', 'v1', $shoot, ['photographer']),
            'CANCELLATION_FEE_INVOICE' => new EmailTypeDefinition('CANCELLATION_FEE_INVOICE', 1, 'invoice', 'emails.cancellation_fee_invoice', 'v1', ['recipient', 'invoice', 'shoot', 'branding', 'meta'], ['client']),
        ];
    }
}
