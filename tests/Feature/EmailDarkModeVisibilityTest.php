<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\EmailPresentation;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDarkModeVisibilityTest extends TestCase
{
    public function test_both_render_paths_include_adaptive_text_surface_and_outlook_rules(): void
    {
        $database = app(TemplateRenderer::class)->render($this->template(), [])['html'];
        $blade = view('emails.invoice_approved', [
            'invoice' => (object) ['invoice_number' => '00018', 'total_amount' => 150, 'approved_at' => null],
            'period' => 'August 2026',
            'roleLabel' => 'photographer',
        ])->render();

        foreach ([$database, $blade] as $html) {
            $this->assertStringContainsString('content="light dark"', $html);
            $this->assertMatchesRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)/', $html);
            foreach (['.dark-heading' => '#f1f5fc', '.dark-body' => '#bdccdf', '.dark-muted' => '#8da5c4', '.footer-contact-link' => '#9cbdff'] as $selector => $color) {
                $this->assertColorRule($html, $selector, $color);
                $this->assertColorRule($html, '[data-ogsc] '.$selector, $color);
            }
            $this->assertColorRule($html, '.atelier-button', '#ffffff');
            $this->assertStringContainsString('background-color:#1b2a3e !important', $html);
            $this->assertStringContainsString('name="format-detection" content="telephone=no', $html);
            $this->assertStringContainsString('href="tel:+12028681663"', $html);
            $this->assertStringContainsString('href="mailto:contact@reprophotos.com"', $html);
        }
    }

    #[DataProvider('previewThemes')]
    public function test_forced_editor_theme_updates_inline_body_details_links_and_logo_without_os_dependency(string $theme, array $colors): void
    {
        $html = app(TemplateRenderer::class)->render($this->template(), [], $theme)['html'];
        $xpath = $this->xpath($html);

        $this->assertStringContainsString('content="'.$theme.'"', $html);
        $this->assertDoesNotMatchRegularExpression('/@media\s*\(prefers-color-scheme:\s*dark\)/', $html);
        $logos = $xpath->query('//img[contains(@class,"email-logo-universal")]');
        $this->assertCount(2, $logos);
        foreach ($logos as $logo) {
            $this->assertStringEndsWith('/images/repro-email-logo-grey.png', $logo->getAttribute('src'));
            $this->assertStringContainsString('display:block', $logo->getAttribute('style'));
        }
        $this->assertStringNotContainsString('/images/email-atelier/v6/logo-', $html);
        foreach ([
            '//*[@data-email-content]//p' => $colors['body'],
            '//*[@data-email-content]//strong' => $colors['ink'],
            '//*[@data-email-content]//span[contains(@class,"info-label")]' => $colors['muted'],
            '//*[@data-email-content]//a[@href="https://example.com/details"]' => $colors['accent'],
        ] as $selector => $color) {
            $node = $xpath->query($selector)->item(0);
            $this->assertInstanceOf(DOMElement::class, $node);
            $this->assertSame($color, $this->lastInlineColor($node), $selector);
        }
        $button = $xpath->query('//*[@data-email-content]//a[contains(@class,"atelier-button")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $button);
        $this->assertSame('#ffffff', $this->lastInlineColor($button));
        $this->assertStringContainsString('background-color:#155bdd', $button->getAttribute('style'));
        $this->assertStringContainsString('Invoice 00028', $html);
        $this->assertStringContainsString('$815.02', $html);
        $this->assertStringNotContainsString('body-inner-after-wide', $html);
    }

    public static function previewThemes(): array
    {
        return [
            'light' => ['light', ['ink' => '#14243a', 'body' => '#465971', 'muted' => '#5d6e84', 'accent' => '#195fe6']],
            'dark' => ['dark', ['ink' => '#f1f5fc', 'body' => '#bdccdf', 'muted' => '#8da5c4', 'accent' => '#9cbdff']],
        ];
    }

    public function test_photographer_onboarding_steps_keep_readable_dark_titles_and_white_actions(): void
    {
        $html = view('emails.account_created', [
            'user' => (object) ['name' => 'Alex Photographer', 'role' => 'photographer', 'company_name' => 'R/E Pro Photos', 'email' => 'alex@example.com', 'phonenumber' => '202-555-0123'],
            'verificationLink' => 'https://example.com/verify',
            'resetLink' => 'https://example.com/reset',
            'includePasswordCreationLink' => true,
            'equipmentVerificationUrl' => 'https://example.com/equipment',
            'emailPreviewTheme' => 'dark',
        ])->render();

        foreach (['1. Verify your email', '2. Create your password', '3. Review and verify equipment', '4. Open your photographer dashboard'] as $step) {
            $this->assertMatchesRegularExpression('/class="dark-heading"[^>]*color:#f1f5fc;[^>]*>'.preg_quote($step, '/').'/', $html);
        }
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//a[contains(@class,"atelier-button")]') as $button) {
            $this->assertSame('#ffffff', $this->lastInlineColor($button));
        }
        $this->assertStringContainsString('Thank you for the opportunity.', $html);
        $this->assertStringContainsString('If you were not expecting this account', $html);
    }

    public function test_change_summary_surfaces_keep_authored_values_after_shared_dark_formatting(): void
    {
        $html = EmailPresentation::format(view('emails.partials.change-summary', [
            'changesSummary' => "Date: Aug 16 -> Aug 17\nInstructions: Use the side entrance",
        ])->render(), 'dark');
        $xpath = $this->xpath($html);
        $cards = $xpath->query('//td[contains(@class,"note-card-bg")]');
        $this->assertCount(2, $cards);
        foreach ($cards as $card) {
            $this->assertStringContainsString('background-color:#1b2a3e', $card->getAttribute('style'));
        }
        $this->assertStringContainsString('Aug 16', $html);
        $this->assertStringContainsString('Aug 17', $html);
        $this->assertStringContainsString('Use the side entrance', $html);
    }

    private function template(): MessageTemplate
    {
        return new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - Invoice 00028',
            'body_html' => '<p>Visible body copy.</p><div class="info-box"><div class="info-row"><span class="info-label">Amount due</span><strong>$815.02</strong></div></div><a href="https://example.com/details">Details</a><a class="button" href="https://example.com/pay">Pay invoice</a>',
            'body_text' => 'Amount due: $815.02',
        ]);
    }

    private function assertColorRule(string $html, string $selector, string $color): void
    {
        $this->assertMatchesRegularExpression('/'.preg_quote($selector, '/').'(?=\s*[,\{])[^{}]*\{[^}]*color:\s*'.preg_quote($color, '/').'\s*!important;/s', $html);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function lastInlineColor(DOMElement $element): string
    {
        preg_match_all('/(?<![-\w])color:\s*(#[0-9a-f]{6})\s*(?:!important)?/i', $element->getAttribute('style'), $matches);
        $this->assertNotEmpty($matches[1]);

        return strtolower($matches[1][array_key_last($matches[1])]);
    }
}
