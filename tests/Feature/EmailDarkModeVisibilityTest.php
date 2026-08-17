<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\EmailBrandingConfig;
use Tests\TestCase;

class EmailDarkModeVisibilityTest extends TestCase
{
    public function test_database_template_layout_keeps_hero_and_footer_copy_visible_in_dark_mode(): void
    {
        $template = new MessageTemplate([
            'slug' => 'dark-mode-visibility-test',
            'channel' => 'EMAIL',
            'subject' => 'A simple email title',
            'body_html' => '<p>Visible body copy.</p>',
            'body_text' => 'Visible body copy.',
        ]);

        $html = app(TemplateRenderer::class)->render($template, [])['html'];
        $headingColorDark = preg_quote(
            app(EmailBrandingConfig::class)->defaults()['heading_color_dark'],
            '/'
        );

        $this->assertMatchesRegularExpression(
            "/@media \\(prefers-color-scheme: dark\\).*?\\.hero-title-primary,.*?color: {$headingColorDark} !important;/s",
            $html
        );
        $this->assertMatchesRegularExpression(
            "/\\[data-ogsc\\] \\.hero-title-primary,.*?color: {$headingColorDark} !important;/s",
            $html
        );
        $this->assertMatchesRegularExpression(
            '/\[data-ogsc\] \.button,\s*\[data-ogsc\] \.button-large\s*\{[^}]*color: #ffffff !important;/s',
            $html
        );
        $this->assertStringContainsString(
            '.footer-copy a { color: #071223; text-decoration: underline; }',
            $html
        );
        $this->assertStringNotContainsString(
            '.footer-copy a { color: #071223 !important;',
            $html
        );
    }

    public function test_blade_layout_and_photographer_steps_opt_into_dark_mode_text_colors(): void
    {
        $user = (object) [
            'name' => 'Alex Photographer',
            'role' => 'photographer',
            'company_name' => 'R/E Pro Photos',
            'email' => 'alex@example.com',
            'phonenumber' => '202-555-0123',
        ];

        $html = view('emails.account_created', [
            'user' => $user,
            'verificationLink' => 'https://example.com/verify',
            'resetLink' => 'https://example.com/reset',
            'includePasswordCreationLink' => true,
            'equipmentVerificationUrl' => 'https://example.com/equipment',
        ])->render();

        $this->assertMatchesRegularExpression('/class="dark-heading"[^>]*>Need help with a shoot, invoice, or account question\?/', $html);
        $this->assertMatchesRegularExpression('/class="dark-muted"[^>]*>\s*If you were not expecting this account/', $html);
        $this->assertMatchesRegularExpression('/class="legal-copy-dark"[^>]*>Thank you for the opportunity\./', $html);

        foreach ([
            '1. Verify your email',
            '2. Create your password',
            '3. Review and verify equipment',
            '4. Open your photographer dashboard',
        ] as $step) {
            $this->assertMatchesRegularExpression(
                '/class="dark-heading"[^>]*color:#071223;[^>]*>'.preg_quote($step, '/').'/',
                $html
            );
        }

        $this->assertWhiteCtaTextIsProtectedInDarkMode($html, 'Create Password');

        $invoiceHtml = view('emails.invoice_approved', [
            'invoice' => (object) [
                'invoice_number' => 'Invoice 00018',
                'total_amount' => 150,
                'approved_at' => null,
            ],
            'period' => 'August 2026',
            'roleLabel' => 'photographer',
        ])->render();

        $this->assertWhiteCtaTextIsProtectedInDarkMode($invoiceHtml, 'View Invoice');
    }

    public function test_shared_change_and_shoot_summary_surfaces_follow_dark_mode(): void
    {
        $changeHtml = view('emails.partials.change-summary', [
            'changesSummary' => "Date: Aug 16 -> Aug 17\nInstructions: Use the side entrance",
        ])->render();

        $this->assertSame(2, preg_match_all(
            '/<td class="note-card-bg"[^>]*background-color:#f8fbff;[^>]*>/',
            $changeHtml
        ));
        $this->assertMatchesRegularExpression(
            '/<td class="note-card-bg"[^>]*>\s*<p class="dark-heading"[^>]*>Date<\/p>/',
            $changeHtml
        );
        $this->assertMatchesRegularExpression(
            '/<td class="note-card-bg"[^>]*>\s*<p class="dark-heading"[^>]*>Instructions<\/p>/',
            $changeHtml
        );

        $shootHtml = view('emails.partials.shoot-summary', [
            'shoot' => (object) [
                'location' => '101 Preview Lane',
                'date' => 'Aug 17, 2026',
                'time' => 'TBD',
                'services' => [],
                'service_category' => null,
                'is_private_listing' => false,
                'client_name' => null,
                'rep_name' => null,
                'photographers_label' => 'TBD',
                'primary_photographer' => null,
                'photographers' => [],
                'property_highlights' => [
                    ['label' => 'Bedrooms', 'value' => '4'],
                ],
                'access_details' => [],
                'notes_lines' => [],
                'company_notes_lines' => [],
                'photographer_notes_lines' => [],
            ],
        ])->render();

        $this->assertMatchesRegularExpression(
            '/<td class="stat-card-bg"[^>]*background-color:#f5f9ff;[^>]*>\s*<p class="dark-muted"[^>]*>Bedrooms<\/p>/',
            $shootHtml
        );
    }

    public function test_footer_contacts_keep_scoped_ios_gmail_and_outlook_dark_mode_colors(): void
    {
        $template = new MessageTemplate([
            'slug' => 'footer-contact-client-visibility-test',
            'channel' => 'EMAIL',
            'subject' => 'Footer contact visibility',
            'body_html' => '<p>Visible body copy.</p>',
            'body_text' => 'Visible body copy.',
        ]);

        $databaseTemplateHtml = app(TemplateRenderer::class)->render($template, [])['html'];
        $bladeTemplateHtml = view('emails.invoice_approved', [
            'invoice' => (object) [
                'invoice_number' => '00018',
                'total_amount' => 150,
                'approved_at' => null,
            ],
            'period' => 'August 2026',
            'roleLabel' => 'photographer',
        ])->render();

        foreach ([$databaseTemplateHtml, $bladeTemplateHtml] as $html) {
            $this->assertStringContainsString('<meta name="format-detection" content="telephone=no">', $html);
            $this->assertMatchesRegularExpression('/<body\b[^>]*class="[^"]*email-body[^"]*"/', $html);
            $this->assertMatchesRegularExpression(
                '/\.footer-contact a\[x-apple-data-detectors\],\s*u \+ \.email-body \.footer-contact a\s*\{[^}]*color: #1463ff !important;[^}]*-webkit-text-fill-color: #1463ff !important;/s',
                $html
            );
            $this->assertMatchesRegularExpression(
                '/@media \(prefers-color-scheme: dark\).*?\.footer-contact a\[x-apple-data-detectors\],\s*u \+ \.email-body \.footer-contact a\s*\{[^}]*color: #6ba6ff !important;[^}]*-webkit-text-fill-color: #6ba6ff !important;/s',
                $html
            );
            $this->assertMatchesRegularExpression(
                '/\[data-ogsc\] \.footer-contact a\[x-apple-data-detectors\]\s*\{[^}]*color: #6ba6ff !important;[^}]*-webkit-text-fill-color: #6ba6ff !important;/s',
                $html
            );
        }
    }

    public function test_promoted_invoice_info_box_values_receive_explicit_dark_mode_colors(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - 421 Direct Avenue - Invoice 00028',
            'body_html' => <<<'HTML'
<div class="info-box">
    <div class="info-row"><span class="info-label">Invoice Number:</span> Invoice 00028</div>
    <div class="info-row"><span class="info-label">Amount Due:</span> <strong>$815.02</strong></div>
    <div class="info-row"><span class="info-label">Due Date:</span> 2026-06-15</div>
</div>
HTML,
            'body_text' => "Invoice Number: 00028\nAmount Due: $815.02\nDue Date: 2026-06-15",
        ]);

        $html = app(TemplateRenderer::class)->render($template, [])['html'];

        $this->assertStringContainsString('body-inner-after-wide', $html);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-color-scheme: dark\).*?\.info-box \{[^}]*color: #c4cfde !important;[^}]*-webkit-text-fill-color: #c4cfde !important;.*?\.info-box \.info-row \{[^}]*color: #c4cfde !important;[^}]*-webkit-text-fill-color: #c4cfde !important;.*?\.info-box \.info-label \{[^}]*color: #8b9cb4 !important;[^}]*-webkit-text-fill-color: #8b9cb4 !important;.*?\.info-box strong,\s*\.info-box b \{[^}]*color: #eef2f9 !important;[^}]*-webkit-text-fill-color: #eef2f9 !important;/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/\[data-ogsc\] \.info-box \{[^}]*color: #c4cfde !important;[^}]*-webkit-text-fill-color: #c4cfde !important;.*?\[data-ogsc\] \.info-box \.info-row \{[^}]*color: #c4cfde !important;[^}]*-webkit-text-fill-color: #c4cfde !important;.*?\[data-ogsc\] \.info-box \.info-label \{[^}]*color: #8b9cb4 !important;[^}]*-webkit-text-fill-color: #8b9cb4 !important;.*?\[data-ogsc\] \.info-box strong,\s*\[data-ogsc\] \.info-box b \{[^}]*color: #eef2f9 !important;[^}]*-webkit-text-fill-color: #eef2f9 !important;/s',
            $html
        );
        $this->assertMatchesRegularExpression('/class="info-row"[^>]*>.*?Invoice 00028<\/div>/s', $html);
        $this->assertMatchesRegularExpression('/class="info-row"[^>]*>.*?<strong[^>]*>\$815\.02<\/strong>/s', $html);
        $this->assertMatchesRegularExpression('/class="info-row"[^>]*>.*?2026-06-15<\/div>/s', $html);

        $branding = app(EmailBrandingConfig::class)->defaults();
        foreach (['body_color_dark', 'muted_color_dark', 'heading_color_dark', 'link_color_dark'] as $foreground) {
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($branding[$foreground], $branding['section_surface_dark']),
                "{$foreground} must remain readable on the promoted dark invoice panel."
            );
        }
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(
            static fn (int $offset): float => hexdec(substr($hex, $offset, 2)) / 255,
            [0, 2, 4]
        );
        $linearChannels = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return (0.2126 * $linearChannels[0])
            + (0.7152 * $linearChannels[1])
            + (0.0722 * $linearChannels[2]);
    }

    private function assertWhiteCtaTextIsProtectedInDarkMode(string $html, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-color-scheme: dark\).*?a\[style\*="color:#ffffff"\].*?color: #ffffff !important;/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/\[data-ogsc\] a\[style\*="color:#ffffff"\].*?color: #ffffff !important;/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*style="[^"]*color:#ffffff;[^"]*"[^>]*>'.preg_quote($label, '/').'<\/a>/',
            $html
        );
    }
}
