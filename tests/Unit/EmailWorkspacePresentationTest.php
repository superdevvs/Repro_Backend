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
}
