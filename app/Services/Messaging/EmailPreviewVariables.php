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
            'invoice_next_step' => 'Preview example: review the itemized invoice in your dashboard.',
            'approval_note' => 'Preview example: the team will review this invoice.',
            'payment_details' => 'Preview receipt: $250.00 paid by card for the example shoot.',
            'support_link' => 'mailto:'.config('mail.contact_address', 'contact@reprophotos.com'),
        ]);
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
