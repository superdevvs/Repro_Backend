<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\DirectEmailTemplates;
use App\Services\SystemEmails\EditableEmailContent;
use App\Services\SystemEmails\EmailPreviewContext;
use App\Services\SystemEmails\EmailTypeRegistry;
use App\Services\SystemEmails\SystemEmailRenderer;
use Tests\TestCase;

class EmailEditableContentTest extends TestCase
{
    public function test_all_direct_and_protected_families_expose_real_copy_and_render_canonical_details(): void
    {
        $content = app(EditableEmailContent::class);
        $renderer = app(TemplateRenderer::class);
        $count = 0;
        foreach (DirectEmailTemplates::definitions() as $view => $definition) {
            $template = new MessageTemplate($definition);
            $blocks = $content->blocks($template);
            $this->assertNotEmpty($blocks, $view);
            $this->assertNotEmpty(array_filter($blocks, fn ($block) => preg_match('/[a-z]{3}/i', preg_replace('/\{\{.*?\}\}/', '', $block['body_text']))), $view);
            $data = EmailPreviewContext::directData($view);
            $originalBody = $renderer->editableBodyHtml(view($view, $data)->render());
            foreach (['html', 'text'] as $mode) {
                $body = $renderer->editableBodyHtml($content->render($view, $data, $template, $mode));
                $this->assertNotEmpty(strip_tags($body), $view);
                $this->assertStringNotContainsString('Preview example', $body);
                $this->assertStringNotContainsString('{{', $body);
                if ($mode === 'html') {
                    $this->assertSame(preg_replace('/\s+/', ' ', EditableEmailContent::plainText($originalBody)), preg_replace('/\s+/', ' ', EditableEmailContent::plainText($body)), $view);
                }
            }
            $count++;
        }
        foreach (app(EmailTypeRegistry::class)->definitions() as $alias => $definition) {
            $template = new MessageTemplate(['channel' => 'EMAIL', 'email_type' => $alias, 'body_html' => '{{system_body_html}}', 'body_text' => '{{system_body_text}}']);
            $this->assertNotEmpty($content->blocks($template), $alias);
            $result = app(SystemEmailRenderer::class)->scopedContent($definition, EmailPreviewContext::protectedPayload($alias), $template);
            foreach (['html', 'text'] as $mode) {
                $this->assertNotEmpty($result[$mode], $alias);
                $this->assertStringNotContainsString('Preview example', $result[$mode]);
                $this->assertStringNotContainsString('{{', $result[$mode]);
            }
            $count++;
        }
        $this->assertSame(37, $count);
    }

    public function test_existing_custom_body_stays_the_primary_editable_content(): void
    {
        $template = new MessageTemplate(['channel' => 'EMAIL', 'email_type' => 'CLIENT_EMAIL_VERIFIED', 'subject' => 'Our custom welcome', 'body_html' => '<p>Our authored copy</p>']);
        $this->assertSame([], app(EditableEmailContent::class)->blocks($template));
        $this->assertSame('Our custom welcome', app(EditableEmailContent::class)->editableSubject($template));
    }

    public function test_copy_overrides_apply_to_every_live_report_row_without_compiling_authored_markup(): void
    {
        $template = new MessageTemplate(DirectEmailTemplates::definitions()['emails.payout-digest']);
        $content = app(EditableEmailContent::class);
        $blocks = $content->blocks($template);
        $row = collect($blocks)->first(fn ($block) => str_contains($block['body_html'], '{{row_name}}'));
        $this->assertNotNull($row);
        $template->content_blocks_json = [$row['key'] => ['body_html' => '<td>Contributor: {{row_name}} @php throw new \\RuntimeException("must never execute"); @endphp</td>', 'body_text' => 'Contributor record: {{row_name}}']];
        $data = EmailPreviewContext::directData('emails.payout-digest');
        $data['editors'] = [
            ['name' => 'Live Editor One', 'shoot_count' => 2, 'service_count' => 3, 'gross_total' => 140],
            ['name' => 'Live Editor Two', 'shoot_count' => 4, 'service_count' => 6, 'gross_total' => 280],
        ];
        $html = $content->render('emails.payout-digest', $data, $template);
        $text = $content->render('emails.payout-digest', $data, $template, 'text');
        $this->assertStringContainsString('Contributor: Live Editor One', $html);
        $this->assertStringContainsString('Contributor: Live Editor Two', $html);
        $this->assertStringContainsString('$140.00', $html);
        $this->assertStringContainsString('$280.00', $html);
        $this->assertStringContainsString('Contributor record: Live Editor Two', $text);
        $this->assertStringContainsString('@php throw', $html);
    }

    public function test_unsupported_block_fields_are_reported_and_runtime_url_values_are_escaped(): void
    {
        $template = new MessageTemplate(['channel' => 'EMAIL', 'email_type' => 'CLIENT_EMAIL_VERIFIED', 'body_html' => '{{system_body_html}}']);
        $content = app(EditableEmailContent::class);
        $block = collect($content->blocks($template))->first(fn ($item) => str_contains($item['body_html'], '{{dashboard_url}}'));
        $this->assertNotNull($block);
        $template->content_blocks_json = [$block['key'] => ['body_html' => $block['body_html'].'<p>{{unknown_field}}</p>']];
        $this->assertContains($block['key'].'.unknown_field', $content->missingVariables($template));
        $payload = EmailPreviewContext::protectedPayload('CLIENT_EMAIL_VERIFIED');
        $payload['links']['dashboard'] = 'https://example.com/?value=" onmouseover="unexpected';
        $rendered = app(SystemEmailRenderer::class)->scopedContent(app(EmailTypeRegistry::class)->definition('CLIENT_EMAIL_VERIFIED'), $payload, $template);
        $this->assertStringNotContainsString(' onmouseover="unexpected', $rendered['html']);
        $document = new \DOMDocument;
        @$document->loadHTML($rendered['html']);
        foreach ($document->getElementsByTagName('a') as $link) {
            $this->assertFalse($link->hasAttribute('onmouseover'));
        }
        $this->assertStringContainsString('If you did not verify this email', $rendered['text']);
    }

    public function test_pasting_a_complete_document_into_a_section_does_not_nest_email_shells(): void
    {
        $template = new MessageTemplate(DirectEmailTemplates::definitions()['emails.contact_confirmation']);
        $content = app(EditableEmailContent::class);
        $block = $content->blocks($template)[0];
        $template->content_blocks_json = [$block['key'] => ['body_html' => '<!doctype html><html><head><title>Copied shell</title></head><body><p>Our revised confirmation.</p></body></html>']];
        $this->assertSame('<p>Our revised confirmation.</p>', $content->blocks($template)[0]['body_html']);
        $html = $content->render('emails.contact_confirmation', EmailPreviewContext::directData('emails.contact_confirmation'), $template);
        $this->assertSame(1, substr_count(strtolower($html), '<html'));
        $this->assertStringContainsString('Our revised confirmation.', $html);
        $this->assertStringNotContainsString('Copied shell', $html);
    }
}
