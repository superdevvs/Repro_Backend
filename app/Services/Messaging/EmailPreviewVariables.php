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
