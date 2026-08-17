<?php

namespace App\Services\SystemEmails;

class SystemEmailBuilder
{
    public function __construct(
        private readonly EmailTypeRegistry $registry,
        private readonly SystemEmailRenderer $renderer,
    ) {}

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
            'subject' => $rendered['subject'] ?? $subject,
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
            if (! array_key_exists($section, $payload)) {
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
            'PHOTOGRAPHER_EQUIPMENT_VERIFICATION' => 'Equipment Verification Required',
            'PHOTOGRAPHER_EQUIPMENT_APPROVED' => 'Equipment Verification Approved',
            'PHOTOGRAPHER_EQUIPMENT_REJECTED' => 'Equipment Verification Rejected',
            'ROLE_CHANGED' => 'Your Account Role Was Updated',
            'PASSWORD_RESET' => 'Reset Your Password - R/E Pro Photos',
            'SHOOT_SCHEDULED' => 'New Shoot Scheduled',
            'SHOOT_UPDATED' => 'Scheduled Photo Shoot Updated',
            'SHOOT_REMINDER' => 'Shoot Reminder: 24 Hours to Go',
            'SHOOT_REMOVED' => 'Photo Shoot Removed from Schedule',
            'SHOOT_REQUEST_DECLINED' => 'Your Shoot Request Was Declined',
            'SHOOT_REQUESTED' => ! empty($meta['is_admin']) ? 'New Shoot Request Needs Review' : 'We Received Your Shoot Request',
            'SHOOT_CANCELLATION_REQUESTED' => 'Shoot Cancellation Request Received',
            'SHOOT_DELIVERED' => 'Your Shoot Has Been Delivered',
            'PAYMENT_CONFIRMATION' => 'Thank You for Your Payment!',
            'INVOICE_GENERATED' => 'Weekly Invoice - '.($meta['period'] ?? 'Current Period'),
            'INVOICE_PENDING_APPROVAL' => 'Invoice Requires Approval - '.($meta['payee_name'] ?? 'Unknown').' - '.($meta['period'] ?? 'Current Period'),
            'INVOICE_APPROVED' => 'Invoice Approved - '.($meta['period'] ?? 'Current Period'),
            'INVOICE_REJECTED' => 'Invoice Rejected - '.($meta['period'] ?? 'Current Period'),
            'SHOOT_PAID' => 'Payment Confirmed for Your Shoot',
            'SHOOT_CANCELLED' => 'Your Shoot Has Been Cancelled',
            'PHOTOGRAPHER_CHANGED' => 'Photographer Assignment Updated',
            'CANCELLATION_FEE_INVOICE' => 'Cancellation Fee Invoice - '.($meta['address'] ?? 'Property'),
            'OFFLINE_PAYMENT_INTENT_SUBMITTED' => 'Offline Payment Submitted - '.(($meta['payment_method_label'] ?? 'Cash').' $'.number_format((float) ($meta['amount'] ?? 0), 2)),
            'OFFLINE_PAYMENT_INTENT_DECLINED' => 'Offline Payment Was Not Accepted',
            'INTERNAL_MESSAGE_NOTIFICATION' => 'New Dashboard Message from '.($meta['sender_name'] ?? 'R/E Pro Photos'),
            default => $definition->alias,
        };
    }
}
