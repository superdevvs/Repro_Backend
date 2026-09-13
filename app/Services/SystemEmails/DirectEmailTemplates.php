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
                'description' => 'Edit the message sections. Recipient-specific details and repeated report rows use current data when sent.',
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
        $content = app(EditableEmailContent::class);
        $body = $renderer->editableBodyHtml($content->render($view, $data, $template));
        $htmlVariable = self::VIEWS[$view][2];
        $textVariable = str_replace('_html', '_text', $htmlVariable);
        $textBody = $renderer->editableBodyHtml($content->render($view, $data, $template, 'text'));
        $footerNote = $content->footerNote($view, $data);
        $variables = array_merge($data, [
            'email_subject' => $subject,
            'recipient_name' => $data['recipientName'] ?? data_get($data, 'user.name') ?? data_get($data, 'salesRep.name') ?? data_get($data, 'client.name') ?? '',
            $htmlVariable => $body,
            $textVariable => EditableEmailContent::plainText($textBody."\n\n".$footerNote),
            'email_footer_note' => $footerNote,
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
            $data = EmailPreviewContext::directData($view);
            $renderer = app(TemplateRenderer::class);
            $content = app(EditableEmailContent::class);
            $footerNote = $content->footerNote($view, $data);
            $variables = array_merge($variables, [
                'email_subject' => $content->editableSubject($template),
                'recipient_name' => 'Jamie Example',
                $htmlVariable => $renderer->editableBodyHtml($content->render($view, $data, $template)),
                $textVariable => EditableEmailContent::plainText($renderer->editableBodyHtml($content->render($view, $data, $template, 'text'))."\n\n".$footerNote),
                'email_footer_note' => $footerNote,
            ]);
        }

        return $variables;
    }
}
