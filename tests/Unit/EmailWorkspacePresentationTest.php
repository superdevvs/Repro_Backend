<?php

namespace Tests\Unit;

use App\Services\Messaging\EmailTemplateDocument;
use App\Services\SystemEmails\EmailPresentation;
use Tests\TestCase;

class EmailWorkspacePresentationTest extends TestCase
{
    public function test_navy_cards_keep_readable_inline_colors_when_email_clients_strip_stylesheets(): void
    {
        $html = app(EmailTemplateDocument::class)->html([['id' => 'guide', 'type' => 'hero', 'heading' => 'Review your invoice', 'body' => 'Open **Earnings**.', 'tone' => 'accent', 'eyebrow' => 'YOUR INVOICE']]);
        foreach (['light', 'dark'] as $theme) {
            $presented = EmailPresentation::format($html, $theme, true, false);
            $this->assertStringContainsString('bgcolor="#09223c"', $presented);
            $this->assertStringContainsString('color:#edf4ff;', $presented);
            $this->assertStringContainsString('color:#9cbdff;', $presented);
            $this->assertStringContainsString('margin:0 0 20px;', $presented);
        }
    }

    public function test_formatted_text_links_keep_connected_fields_for_recipient_rendering(): void
    {
        $draft = app(EmailTemplateDocument::class)->prepare(['channel' => 'EMAIL', 'subject' => 'Your invoice', 'editor_document_json' => ['version' => 1, 'format' => 'sections', 'sections' => [
            ['id' => 'guide', 'type' => 'text', 'body' => 'Hi **{{client_name}}**, [open your invoice]({{portal_url}}).'],
        ]]]);
        $this->assertStringContainsString('href="{{portal_url}}"', $draft['body_html']);
        $rendered = app(\App\Services\Messaging\TemplateRenderer::class)->render(new \App\Models\MessageTemplate($draft), ['client_name' => 'Alex', 'portal_url' => 'https://reprodashboard.com/accounting']);
        $this->assertStringContainsString('<strong>Alex</strong>', $rendered['html']);
        $this->assertStringContainsString('href="https://reprodashboard.com/accounting"', $rendered['html']);
        $this->assertStringNotContainsString('%7B%7B', $rendered['html']);
    }
}
