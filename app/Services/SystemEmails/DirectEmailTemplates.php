<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use InvalidArgumentException;

/** Editable copy around recipient-scoped Blade terms, inquiries and report tables. */
class DirectEmailTemplates
{
    private const VIEWS = [
        'emails.terms_accepted' => ['Terms accepted', 'ACCOUNT', 'terms_details_html'],
        'emails.contact_confirmation' => ['Contact confirmation', 'GENERAL', 'contact_confirmation_html'],
        'emails.contact_notification' => ['Contact inquiry notification', 'GENERAL', 'contact_inquiry_html'],
        'emails.editing-request' => ['Special editing request', 'GENERAL', 'editing_request_html'],
        'emails.payout-report' => ['Payout report', 'INVOICE', 'payout_report_html'],
        'emails.payout-digest' => ['Accounting payout digest', 'INVOICE', 'payout_digest_html'],
        'emails.weekly_sales_report' => ['Weekly sales report', 'INVOICE', 'weekly_sales_report_html'],
    ];

    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::VIEWS as $view => [$name, $category, $htmlVariable]) {
            $textVariable = str_replace('_html', '_text', $htmlVariable);
            $definitions[$view] = [
                'channel' => 'EMAIL',
                'slug' => str_replace('_', '-', substr($view, strlen('emails.'))),
                'name' => $name,
                'description' => 'Editable copy with a live content block. Keep {{'.$htmlVariable.'}} to include the recipient-specific details.',
                'category' => $category,
                'subject' => '{{email_subject}}',
                'body_html' => '{{'.$htmlVariable.'}}',
                'body_text' => '{{'.$textVariable.'}}',
                'variables_json' => ['email_subject', 'recipient_name', $htmlVariable, $textVariable],
                'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
                'email_type' => null, 'override_enabled' => false,
            ];
        }

        return $definitions;
    }

    public static function installMissing(): void
    {
        foreach (self::definitions() as $definition) {
            MessageTemplate::firstOrCreate(['channel' => 'EMAIL', 'slug' => $definition['slug']], $definition);
        }
    }

    public function render(string $view, array $data, string $subject): ?array
    {
        $definition = self::definitions()[$view] ?? throw new InvalidArgumentException('Unknown direct email template.');
        $template = MessageTemplate::where('channel', 'EMAIL')->where('slug', $definition['slug'])->first()
            ?? new MessageTemplate($definition);
        if (! $template->is_active) {
            return null;
        }

        $renderer = app(TemplateRenderer::class);
        // The original view retains all dynamic tables and its existing data scope.
        // The helper is called at send sites, never from a Blade view/composer.
        $body = $renderer->editableBodyHtml(view($view, $data)->render());
        $htmlVariable = self::VIEWS[$view][2];
        $textVariable = str_replace('_html', '_text', $htmlVariable);
        $plainBody = preg_replace('/<\/(?:p|div|tr|h[1-6])>|<br\s*\/?>/i', "\n", $body);
        $variables = array_merge($data, [
            'email_subject' => $subject,
            'recipient_name' => $data['recipientName'] ?? data_get($data, 'user.name') ?? data_get($data, 'salesRep.name') ?? data_get($data, 'client.name') ?? '',
            $htmlVariable => $body,
            $textVariable => trim(html_entity_decode(strip_tags($plainBody), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ]);

        return $renderer->render($template, $variables);
    }

    /** Fictional examples are supplied only by editor preview/test-send endpoints. */
    public function previewVariables(MessageTemplate $template, array $variables): array
    {
        foreach (self::definitions() as $view => $definition) {
            $htmlVariable = self::VIEWS[$view][2];
            $textVariable = str_replace('_html', '_text', $htmlVariable);
            if ($template->slug !== $definition['slug'] && ! str_contains((string) $template->body_html, $htmlVariable) && ! str_contains((string) $template->body_text, $textVariable)) {
                continue;
            }
            $example = '<div class="info-box"><p><strong>Preview example</strong></p><p>Live recipient details are inserted here when this email is sent.</p><table role="presentation" width="100%"><tr><td>Recipient</td><td>Jamie Example</td></tr><tr><td>Record</td><td>'.e($definition['name']).' — sample record</td></tr><tr><td>Period</td><td>September 7–13, 2026</td></tr></table></div>';
            $variables += [
                'email_subject' => $definition['name'].' — preview example',
                'recipient_name' => 'Jamie Example',
                $htmlVariable => $example,
                $textVariable => 'Preview example. Live recipient details are inserted when sent. Recipient: Jamie Example. Record: '.$definition['name'].'.',
            ];
        }

        return $variables;
    }
}
