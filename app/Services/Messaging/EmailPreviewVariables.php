<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;

/** Known fictional values for editor previews and explicit test sends only. */
class EmailPreviewVariables
{
    public function apply(MessageTemplate $template, array $resolved, array $provided = []): array
    {
        $samples = app(TemplateVariableResolver::class)->resolve([
            'client' => ['name' => 'Jamie Example', 'email' => 'jamie@example.com', 'company_name' => 'Example Realty', 'phonenumber' => '202-555-0142'],
            'photographer' => ['name' => 'Alex Example', 'email' => 'alex@example.com', 'phonenumber' => '202-555-0143'],
            'recipient' => ['name' => 'Jamie Example', 'email' => 'jamie@example.com'],
            'rep' => ['name' => 'Taylor Example', 'email' => 'taylor@example.com'],
            'shoot_location' => '123 Example Lane, Washington, DC',
            'shoot_date' => 'September 14, 2026', 'shoot_time' => '10:00 AM',
            'invoice_number' => 'Invoice 00028', 'amount_due' => '250.00', 'due_date' => 'September 21, 2026',
            'payment_method_label' => 'Cash', 'payment_amount' => '250.00',
            'payment_link' => 'https://example.com/preview/invoice',
            'services_provided' => 'Property photography — example package',
            'services_provided_html' => '<p>Property photography — example package</p>',
            'password_reset_link' => 'https://example.com/preview/create-password',
            'assigned_photographers' => 'Alex Example',
            'previous_photographer_name' => 'Taylor Example', 'new_photographer_name' => 'Alex Example',
            'shoot_total' => '250.00', 'shoot_address' => '123 Example Lane, Washington, DC',
            'shoot_notes' => 'Preview note: meet the contact at the front entrance.',
            'decline_reason' => 'Preview example: the requested time is unavailable.',
            'small_zip_link' => 'https://example.com/preview/media/web',
            'full_zip_link' => 'https://example.com/preview/media/full',
            'mls_tour_link' => 'https://example.com/preview/tour/unbranded',
            'branded_tour_link' => 'https://example.com/preview/tour/branded',
            'recipient_role' => 'photographer', 'billing_period' => 'September 7–13, 2026',
            'invoice_status' => 'Pending approval', 'invoice_total' => '250.00',
            'invoice_items_html' => '<table role="presentation" width="100%"><tr><td>Example photography service</td><td>$250.00</td></tr></table>',
            'invoice_items_text' => 'Example photography service: $250.00',
            'dashboard_url' => 'https://example.com/preview/dashboard',
            'dashboard_link' => 'https://example.com/preview/shoot',
            'map_link' => 'https://example.com/preview/map',
            'property_contact_name' => 'Morgan Example', 'property_contact_phone' => '202-555-0144',
            'access_instructions' => 'Preview example: meet the contact at the front entrance.',
            'access_warning' => 'Preview example: please confirm property access before the shoot.',
            'assignment_message' => 'You are assigned to this example shoot. Please review its schedule and access details.',
            'assignment_status' => 'Assigned to shoot',
            'payment_status' => 'Partially paid', 'remaining_balance' => '125.00',
            'payment_method' => 'Card', 'payment_items' => 'Example photography service: $250.00',
            'receipt_link' => 'https://example.com/preview/receipt',
            'cancellation_reason' => 'Preview example: the property is no longer available.',
            'refund_amount' => '125.00', 'original_payment_reference' => 'Example payment 00028',
            'refund_method' => 'Original card', 'refund_settlement_timing' => 'Preview example: allow 5–10 business days.',
            'summary_start' => 'September 7, 2026', 'summary_end' => 'September 13, 2026',
            'summary_invoice_count' => '2', 'summary_total_invoiced' => '500.00',
            'summary_total_paid' => '375.00', 'summary_total_outstanding' => '125.00',
            'summary_invoices_html' => '<table role="presentation"><tr><td>Example invoice 00028</td><td>$250.00</td></tr></table>',
            'summary_invoices_text' => 'Example invoice 00028: $250.00',
            'report_period_start' => 'September 7, 2026', 'report_period_end' => 'September 13, 2026',
            'report_total_shoots' => '4', 'report_completed_shoots' => '3', 'report_completion_rate' => '75',
            'report_total_revenue' => '1000.00', 'report_total_paid' => '750.00',
            'report_outstanding_balance' => '250.00', 'report_average_shoot_value' => '250.00',
            'invoice_next_step' => 'Preview example: review the itemized invoice in your dashboard.',
            'approval_note' => 'Preview example: the team will review this invoice.',
            'payment_details' => 'Preview receipt: $250.00 paid by card for the example shoot.',
            'support_link' => 'mailto:'.config('mail.contact_address', 'contact@reprophotos.com'),
        ]);
        if (! array_key_exists('sms_contact', $provided) && ($resolved['sms_contact'] ?? '') === 'See shoot details') {
            $resolved['sms_contact'] = $samples['sms_contact'];
        }
        foreach (app(TemplateRenderer::class)->variableKeys($template) as $key) {
            if (array_key_exists($key, $provided) || (isset($resolved[$key]) && $resolved[$key] !== '')) {
                continue;
            }
            if (isset($samples[$key]) && is_scalar($samples[$key]) && $samples[$key] !== '') {
                $resolved[$key] = $samples[$key];
            }
        }

        return $resolved;
    }
}
