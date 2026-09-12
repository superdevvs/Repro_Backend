<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use Illuminate\Support\Str;

class ProtectedEmailTemplates
{
    public static function installMissing(): void
    {
        foreach (app(EmailTypeRegistry::class)->definitions() as $alias => $definition) {
            if (MessageTemplate::where('channel', 'EMAIL')->where('email_type', $alias)->exists()) {
                continue;
            }
            $category = match ($definition->category) {
                'account', 'security' => 'ACCOUNT', 'booking' => 'BOOKING',
                'payment' => 'PAYMENT', 'invoice' => 'INVOICE', default => 'GENERAL',
            };
            MessageTemplate::firstOrCreate(['channel' => 'EMAIL', 'slug' => 'system-'.strtolower(str_replace('_', '-', $alias))], [
                'name' => Str::headline(strtolower($alias)), 'description' => 'Keep {{system_body_html}} for the live recipient-specific details. Enable the protected override to apply edited copy.',
                'category' => $category, 'subject' => '{{system_subject}}',
                'body_html' => '{{system_body_html}}', 'body_text' => '{{system_body_text}}',
                'variables_json' => ['system_subject', 'system_body_html', 'system_body_text', 'recipient_name'],
                'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
                'email_type' => $alias, 'override_enabled' => false,
            ]);
        }
    }

    public static function hasScopedRuntimeBlock(MessageTemplate $template): bool
    {
        return preg_match('/\{\{\s*system_body_html\s*\}\}|\[system_body_html\]/', (string) $template->body_html) === 1;
    }

    public function previewVariables(MessageTemplate $template, array $variables): array
    {
        if (! $template->email_type || ! app(EmailTypeRegistry::class)->has($template->email_type)) {
            return $variables;
        }
        $label = Str::headline(strtolower($template->email_type));
        $variables += [
            'system_subject' => $label,
            'system_body_html' => '<div class="info-box"><p><strong>Preview example</strong></p><p>The live '.e(strtolower($label)).' details and action links are inserted here for the recipient when sent.</p><p>Recipient: Jamie Example</p></div>',
            'system_body_text' => 'Preview example. Live '.strtolower($label).' details and action links are inserted for the recipient when sent.',
            'recipient_name' => 'Jamie Example',
        ];

        return $variables;
    }
}
