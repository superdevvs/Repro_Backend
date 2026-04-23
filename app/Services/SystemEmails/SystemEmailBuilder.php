<?php

namespace App\Services\SystemEmails;

class SystemEmailBuilder
{
    public function __construct(
        private readonly EmailTypeRegistry $registry,
        private readonly SystemEmailRenderer $renderer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function build(string $emailAlias, array $payload): array
    {
        $definition = $this->registry->definition($emailAlias);
        $this->assertRequiredSections($definition, $payload);
        $subject = $this->buildSubject($definition, $payload);
        $rendered = $this->renderer->render($definition, $payload, $subject);

        return [
            'definition' => $definition,
            'subject' => $subject,
            'body_html' => $rendered['body_html'],
            'body_text' => $rendered['body_text'],
            'payload' => $payload,
            'view_data' => $rendered['view_data'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRequiredSections(EmailTypeDefinition $definition, array $payload): void
    {
        foreach ($definition->requiredSections as $section) {
            if (!array_key_exists($section, $payload)) {
                throw new \InvalidArgumentException("Missing required system email payload section [{$section}] for [{$definition->alias}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildSubject(EmailTypeDefinition $definition, array $payload): string
    {
        $meta = (array) ($payload['meta'] ?? []);

        return match ($definition->alias) {
            'ACCOUNT_CREATED' => 'New Account Information',
            'CLIENT_EMAIL_VERIFICATION' => 'Verify Your Email Address',
            'CLIENT_EMAIL_VERIFIED' => 'Your Email Is Verified',
            'PASSWORD_RESET' => 'Reset Your Password - R/E Pro Photos',
            'SHOOT_SCHEDULED' => 'New Shoot Scheduled',
            'SHOOT_UPDATED' => 'Scheduled Photo Shoot Updated',
            'SHOOT_REMINDER' => 'Shoot Reminder: 24 Hours to Go',
            'SHOOT_REMOVED' => 'Photo Shoot Removed from Schedule',
            'SHOOT_REQUEST_DECLINED' => 'Your Shoot Request Was Declined',
            'SHOOT_REQUESTED' => !empty($meta['is_admin']) ? 'New Shoot Request Needs Review' : 'We Received Your Shoot Request',
            'SHOOT_CANCELLATION_REQUESTED' => 'Shoot Cancellation Request Received',
            'SHOOT_DELIVERED' => 'Your Photos Are Ready',
            'PAYMENT_CONFIRMATION' => 'Thank You for Your Payment!',
            'INVOICE_GENERATED' => 'Weekly Invoice - ' . ($meta['period'] ?? 'Current Period'),
            'INVOICE_PENDING_APPROVAL' => 'Invoice Requires Approval - ' . ($meta['payee_name'] ?? 'Unknown') . ' - ' . ($meta['period'] ?? 'Current Period'),
            'INVOICE_APPROVED' => 'Invoice Approved - ' . ($meta['period'] ?? 'Current Period'),
            'INVOICE_REJECTED' => 'Invoice Rejected - ' . ($meta['period'] ?? 'Current Period'),
            'SHOOT_PAID' => 'Payment Confirmed for Your Shoot',
            'SHOOT_CANCELLED' => 'Your Shoot Has Been Cancelled',
            'PHOTOGRAPHER_CHANGED' => 'Photographer Assignment Updated',
            'CANCELLATION_FEE_INVOICE' => 'Cancellation Fee Invoice - ' . ($meta['address'] ?? 'Property'),
            default => $definition->alias,
        };
    }
}
