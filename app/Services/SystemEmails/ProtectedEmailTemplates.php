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
                'name' => Str::headline(strtolower($alias)), 'description' => 'Edit the message sections and enable the protected override to apply saved copy. Recipient-specific fields and conditional sections stay connected to current data.',
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
        $definition = app(EmailTypeRegistry::class)->definition($template->email_type);
        $payload = EmailPreviewContext::protectedPayload($template->email_type);
        $rendered = app(SystemEmailRenderer::class)->scopedContent($definition, $payload, $template);
        $variables = array_merge($variables, [
            'system_subject' => app(EditableEmailContent::class)->editableSubject($template),
            'system_body_html' => $rendered['html'],
            'system_body_text' => $rendered['text'],
            'email_footer_note' => $rendered['footer_note'],
            'recipient_name' => $payload['recipient']['name'],
        ]);

        return $variables;
    }
}
