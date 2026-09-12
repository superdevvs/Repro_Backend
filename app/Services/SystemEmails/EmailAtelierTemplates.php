<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use RuntimeException;

/** Editor-backed defaults for the five messages formerly assembled inside AI flows. */
class EmailAtelierTemplates
{
    public static function definitions(): array
    {
        $messages = [
            'crm_thank_you' => ['Thank you for choosing R/E Pro Photos', 'Thank you for your recent shoot with us. We hope you love your photos.', 'Open your dashboard', 'portal_url'],
            'crm_feedback' => ['We would love your feedback', 'We hope you are enjoying your photos. Please take a moment to share your feedback with our team.', 'Share your feedback', 'support_link'],
            'crm_referral' => ['Know someone who needs great property photos?', 'Thank you for being a valued client. If you know someone who could use our services, we would love an introduction.', 'Introduce us', 'support_link'],
            'crm_rebook' => ['Ready for your next shoot?', 'We would love to work with you again. Let us know when you are ready to book your next property shoot.', 'Schedule a shoot', 'portal_url'],
        ];
        $definitions = [];
        foreach ($messages as $key => [$subject, $copy, $cta, $link]) {
            $definitions[$key] = self::definition($key, $subject, 'GENERAL',
                '<p>Hi {{recipient_name}},</p><p>'.$copy.'</p><p><a class="button" href="{{'.$link.'}}">'.$cta.'</a></p>',
                "Hi {{recipient_name}},\n\n".$copy."\n\n".$cta.': {{'.$link.'}}',
                ['recipient_name', 'portal_url', 'support_link']);
        }
        $definitions['client_invoice'] = self::definition('client_invoice', '{{invoice_label}} from R/E Pro Photos', 'INVOICE',
            '<p>Hi {{recipient_name}},</p><p>Your invoice details are ready to review.</p><div class="info-box"><div class="info-row"><span class="info-label">Invoice</span><span class="info-value">{{invoice_label}}</span></div><div class="info-row"><span class="info-label">Amount due</span><span class="info-value">{{amount_due}}</span></div><div class="info-row"><span class="info-label">Due date</span><span class="info-value">{{due_date}}</span></div></div><p><a class="button" href="{{payment_link}}">View invoice</a></p>',
            "Hi {{recipient_name}},\n\n{{invoice_label}}\nAmount due: {{amount_due}}\nDue date: {{due_date}}\nView invoice: {{payment_link}}",
            ['recipient_name', 'invoice_label', 'amount_due', 'due_date', 'payment_link']);

        return $definitions;
    }

    private static function definition(string $key, string $subject, string $category, string $html, string $text, array $variables): array
    {
        return [
            'channel' => 'EMAIL', 'slug' => str_replace('_', '-', $key),
            'name' => ucwords(str_replace('_', ' ', $key)), 'description' => 'Editable R/E Pro Photos '.str_replace('_', ' ', $key).' email.',
            'category' => $category, 'subject' => $subject, 'body_html' => $html, 'body_text' => $text,
            'variables_json' => $variables, 'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
            'email_type' => null, 'override_enabled' => false,
        ];
    }

    public static function installMissing(): void
    {
        foreach (self::definitions() as $definition) {
            MessageTemplate::firstOrCreate(['channel' => 'EMAIL', 'slug' => $definition['slug']], $definition);
        }
    }

    public function render(string $design, array $variables): array
    {
        $definition = self::definitions()[$design] ?? throw new RuntimeException('Unknown editable email design.');
        $template = MessageTemplate::where('channel', 'EMAIL')->where('slug', $definition['slug'])->first()
            ?? new MessageTemplate($definition);
        if (! $template->is_active) {
            throw new RuntimeException('This email template has been disabled.');
        }
        $brand = app(EmailBrandingConfig::class)->defaults();
        $variables += ['portal_url' => $brand['dashboard_url'], 'company_email' => $brand['support_email'], 'support_link' => 'mailto:'.$brand['support_email']];

        return app(TemplateRenderer::class)->render($template, $variables);
    }
}
