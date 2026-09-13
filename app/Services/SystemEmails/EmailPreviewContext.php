<?php

namespace App\Services\SystemEmails;

use Carbon\CarbonImmutable;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/** Fictional typed data for canonical editor previews, with no persisted IDs. */
class EmailPreviewContext
{
    public static function protectedPayload(string $alias): array
    {
        $role = match ($alias) {
            'PHOTOGRAPHER_EQUIPMENT_VERIFICATION', 'PHOTOGRAPHER_EQUIPMENT_APPROVED',
            'PHOTOGRAPHER_EQUIPMENT_REJECTED', 'PHOTOGRAPHER_CHANGED',
            'INVOICE_GENERATED', 'INVOICE_APPROVED', 'INVOICE_REJECTED' => 'photographer',
            'INVOICE_PENDING_APPROVAL', 'OFFLINE_PAYMENT_INTENT_SUBMITTED' => 'admin',
            default => 'client',
        };
        $now = self::date();
        $paid = in_array($alias, ['PAYMENT_CONFIRMATION', 'PAYMENT_COMPLETED', 'SHOOT_PAID', 'SHOOT_DELIVERED'], true);
        $recipient = self::person($role);
        $client = self::person('client');
        $photographer = self::person('photographer');
        $address = '123 Example Lane, Washington, DC 20016';
        $invoiceStatus = match ($alias) {
            'INVOICE_APPROVED' => 'approved', 'INVOICE_REJECTED' => 'rejected',
            'CANCELLATION_FEE_INVOICE' => 'unpaid', default => 'pending_approval',
        };

        return [
            'recipient' => $recipient,
            'account' => $client,
            'shoot' => [
                'id' => null, 'client_id' => null, 'photographer_id' => null,
                'address' => $address, 'location' => $address, 'city' => 'Washington', 'state' => 'DC',
                'date' => 'September 14, 2026', 'time' => '10:30 AM ET',
                'client_name' => $client['name'], 'client_email' => $client['email'],
                'rep_name' => 'Taylor Example', 'primary_photographer' => $photographer['name'],
                'photographers_label' => $photographer['name'], 'photographers' => [$photographer],
                'services' => [
                    ['id' => null, 'display_name' => 'Premium Photo Package', 'meta' => '48 edited photos · next-day delivery', 'formatted_total' => '$249.00', 'photographer_name' => $photographer['name']],
                    ['id' => null, 'display_name' => 'Aerial Drone Add-on', 'meta' => '10 aerial stills', 'formatted_total' => '$120.00', 'photographer_name' => $photographer['name']],
                ],
                'service_category' => 'Real Estate Photography', 'formatted_subtotal' => '$369.00',
                'formatted_tax' => '$0.00', 'formatted_grand_total' => '$369.00', 'tax' => 0, 'tax_rate' => 0,
                'total_quote' => 369, 'total_paid' => $paid ? 369 : 0,
                'notes_lines' => ['Please photograph the rear garden last.'],
                'photographer_notes_lines' => ['Call the client on arrival.'],
                'company_notes_lines' => ['Standard next-day delivery.'],
                'property_highlights' => [['label' => 'Home size', 'value' => '2,450 sq ft'], ['label' => 'Bedrooms', 'value' => '4'], ['label' => 'Listing type', 'value' => 'Residential']],
                'access_details' => [['label' => 'Entry', 'value' => 'Lockbox on side door']],
                'property_prep_url' => 'https://example.com/property-prep',
                'dashboard_url' => 'https://example.com/shoots/example',
                'payment_status' => $paid ? 'paid' : 'unpaid', 'remaining_balance' => $paid ? 0 : 369,
                'status' => $alias === 'SHOOT_DELIVERED' ? 'delivered' : 'scheduled',
                'workflow_status' => $alias === 'SHOOT_DELIVERED' ? 'delivered' : 'scheduled',
                'is_private_listing' => false, 'bypass_paywall' => false,
            ],
            'invoice' => [
                'id' => null, 'user_id' => null, 'shoot_id' => null, 'invoice_number' => '00028',
                'status' => $invoiceStatus, 'total' => 369, 'total_amount' => 369, 'amount_paid' => 0,
                'issue_date' => $now, 'due_date' => $now->addDays(14),
                'approved_at' => $now, 'modified_at' => $now->subHour(), 'rejected_at' => $now,
                'rejection_reason' => 'Please attach the mileage receipt.',
                'modification_notes' => 'Aerial line item updated to match the approved rate.',
                'items' => [
                    ['id' => null, 'type' => 'service', 'description' => 'Premium Photo Package', 'total_amount' => 249],
                    ['id' => null, 'type' => 'service', 'description' => 'Aerial Drone Add-on', 'total_amount' => 120],
                ],
            ],
            'payment' => [
                'id' => null, 'shoot_id' => null, 'amount' => 369,
                'payment_method' => 'Visa ending in 4242', 'transaction_id' => null,
                'created_at' => $now, 'is_grouped' => false,
                'items' => [['shoot_id' => null, 'label' => 'Property photography', 'address' => $address, 'amount' => 369, 'formatted_amount' => '$369.00', 'formatted_remaining_balance' => '$0.00']],
            ],
            'links' => [
                'dashboard' => 'https://example.com/dashboard', 'shoot' => 'https://example.com/shoots/example',
                'payment' => $paid ? null : 'https://example.com/invoices/example',
                'invoice' => 'https://example.com/invoices/example',
                'reset_password' => 'https://example.com/reset-password',
                'verification' => 'https://example.com/verify-email', 'settings' => 'https://example.com/settings',
                'equipment' => 'https://example.com/equipment', 'equipment_verification' => 'https://example.com/equipment/verify',
                'download' => 'https://example.com/shoots/example/download', 'tour' => 'https://example.com/tour/example',
                'message' => 'https://example.com/messages/example',
            ],
            'branding' => [],
            'meta' => [
                'recipient_type' => $role, 'recipient_role' => $role, 'event_version' => 'editor',
                'is_photographer' => $role === 'photographer', 'is_admin' => $role === 'admin',
                'pending_equipment_count' => 2, 'include_password_creation_link' => true,
                'equipment_name' => 'Sony A7 IV Camera Kit', 'equipment_serial_number' => 'EXAMPLE-A7IV',
                'verified_at' => $now, 'rejected_at' => $now,
                'rejection_reason' => 'Please upload a clearer serial-number photo.',
                'old_role_label' => 'Client', 'new_role_label' => 'Client + Photographer',
                'secondary_roles' => ['photographer'], 'scheduled_at' => $now->addDay(),
                'changes_summary' => "Date: September 13 → September 14\nTime: 9:00 AM → 10:30 AM",
                'decline_reason' => 'The requested time slot is unavailable.',
                'cancellation_reason' => 'The seller requested a new date.',
                'previous_photographer' => ['id' => null, 'name' => 'Sam Example'], 'is_assigned_after_change' => true,
                'amount' => 369, 'payment_method_label' => 'Cash', 'check_number' => null,
                'payment_date' => 'September 13, 2026', 'notes' => 'Received at the front desk.',
                'submitted_by_name' => 'Taylor Example', 'submitted_by_role' => 'Sales rep',
                'shoot_address' => $address, 'address' => $address,
                'period' => 'September 7–13, 2026', 'billing_period' => 'September 7–13, 2026',
                'role_label' => 'Photographer', 'role_heading' => 'Photographer payout',
                'message_preview' => 'I uploaded the final floor plan and left a note for the client.',
                'sender_name' => $photographer['name'], 'sender_role' => 'Photographer',
            ],
        ];
    }

    public static function directData(string $view): array
    {
        $now = self::date();
        $client = new Fluent(self::person('client'));
        $recipient = new Fluent(self::person(match ($view) {
            'emails.weekly_sales_report' => 'rep',
            'emails.payout-digest', 'emails.editing-request' => 'admin',
            'emails.contact_notification', 'emails.contact_confirmation', 'emails.terms_accepted' => 'client',
            default => 'photographer',
        }));
        $submission = new Fluent([
            'id' => null, 'client_id' => null, 'sender_name' => 'Casey Example', 'sender_email' => 'casey@example.com',
            'sender_phone' => '(202) 555-0199', 'created_at' => $now,
            'message' => 'I am listing a home in Georgetown next month. Could you send package options and availability?',
        ]);
        $summary = ['shoot_count' => 8, 'service_count' => 12, 'gross_total' => 2840, 'average_value' => 355, 'commission_rate' => 80, 'commission_total' => 2272];
        $rows = [['id' => null, 'user_id' => null, 'name' => $recipient->name] + $summary];
        $shared = [
            'branding' => [], 'recipient' => $recipient, 'recipientName' => $recipient->name,
            'user' => $recipient, 'client' => $client, 'salesRep' => new Fluent(self::person('rep')),
            'rangeStart' => $now->startOfWeek(), 'rangeEnd' => $now->endOfWeek(),
        ];
        $data = match ($view) {
            'emails.contact_notification' => ['submission' => $submission],
            'emails.contact_confirmation' => ['submission' => $submission, 'recipient' => new Fluent(['id' => null, 'name' => $submission->sender_name, 'email' => $submission->sender_email, 'role' => 'client']), 'recipientName' => $submission->sender_name],
            'emails.editing-request' => ['request' => new Fluent([
                'id' => null, 'shoot_id' => null, 'requester_id' => null, 'tracking_code' => 'EDIT-EXAMPLE',
                'requester' => $client, 'priority' => 'high', 'status' => 'pending', 'target_team' => 'editors',
                'summary' => 'Remove the garden hose from images 12 and 14.',
                'details' => 'Match the lawn texture and preserve the original crop.',
            ])],
            'emails.payout-report' => ['audience' => 'photographer', 'summary' => $summary],
            'emails.payout-digest' => [
                'editors' => $rows, 'photographers' => $rows, 'reps' => $rows,
                'totalEditorPayout' => 2840, 'totalPhotographerPayout' => 2840, 'totalRepPayout' => 2840,
            ],
            'emails.terms_accepted' => ['user' => $client, 'recipient' => $client, 'recipientName' => $client->name, 'acceptedAt' => $now, 'accepted_at' => $now],
            'emails.weekly_sales_report' => [
                'weekLabel' => 'September 7–13, 2026',
                'report' => [
                    'summary' => ['total_shoots' => 24, 'completion_rate' => 92, 'total_revenue' => 8642, 'completed_shoots' => 22, 'total_paid' => 7418, 'outstanding_balance' => 1224],
                    'clients' => [['client_id' => null, 'client_name' => 'Example Realty', 'shoot_count' => 6, 'total_paid' => 1845, 'total_revenue' => 2214]],
                    'top_shoots' => [['shoot_id' => null, 'client_name' => $client->name, 'workflow_status' => 'Delivered', 'scheduled_date' => 'September 10, 2026', 'total_quote' => 645]],
                ],
            ],
            default => throw new InvalidArgumentException('Unknown direct email preview view.'),
        };

        return array_replace($shared, $data);
    }

    private static function person(string $role): array
    {
        [$first, $email] = match ($role) {
            'photographer' => ['Alex', 'alex@example.com'],
            'rep' => ['Taylor', 'taylor@example.com'],
            'admin' => ['Morgan', 'morgan@example.com'],
            default => ['Jamie', 'jamie@example.com'],
        };

        return ['id' => null, 'name' => $first.' Example', 'first_name' => $first, 'last_name' => 'Example',
            'email' => $email, 'phone' => '(202) 555-0142', 'phonenumber' => '(202) 555-0142',
            'role' => $role, 'company_name' => 'Example Realty'];
    }

    private static function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-13 10:30:00', 'America/New_York');
    }
}
