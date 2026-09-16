<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\SystemEmails\EmailBrandingConfig;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailFooterContactRenderingTest extends TestCase
{
    private const CANONICAL_PHONE = '(202) 868-1663';

    private const CANONICAL_PHONE_HREF = 'tel:+12028681663';

    private const CANONICAL_EMAIL = 'contact@reprophotos.com';

    public function test_database_template_render_emits_visible_clickable_footer_contacts_and_canonical_copy(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - {{shoot_location}} - Invoice {{invoice_number}} - Call 202-868-1113',
            'body_html' => '<p>Questions? Call 202-868-1113 or email {{company_email}}.</p>',
            'body_text' => 'Questions? Call 202-868-1113 or email {{company_email}}.',
            'variables_json' => ['shoot_location', 'invoice_number', 'company_email'],
        ]);

        $rendered = app(TemplateRenderer::class)->render($template, [
            'shoot_location' => '421 Direct Avenue, Tampa, FL 33602',
            'invoice_number' => 'Invoice 00028',
            'company_email' => self::CANONICAL_EMAIL,
        ]);

        $this->assertSame(
            'Payment Reminder - 421 Direct Avenue, Tampa, FL 33602 - Invoice 00028 - Call '.self::CANONICAL_PHONE,
            $rendered['subject']
        );
        $this->assertStringContainsString(
            'Questions? Call '.self::CANONICAL_PHONE.' or email '.self::CANONICAL_EMAIL.'.',
            $rendered['html']
        );
        $this->assertStringContainsString(
            'Questions? Call '.self::CANONICAL_PHONE.' or email '.self::CANONICAL_EMAIL.'.',
            $rendered['text']
        );
        $this->assertStringNotContainsString(
            '202-868-1113',
            $rendered['subject'].' '.$rendered['html'].' '.$rendered['text']
        );
        $this->assertSame(1, substr_count($rendered['subject'], 'Invoice 00028'));

        $this->assertRenderedFooterContacts($rendered['html']);
    }

    public function test_blade_email_render_emits_the_same_visible_clickable_footer_contacts(): void
    {
        $html = view('emails.invoice_approved', [
            'invoice' => (object) [
                'invoice_number' => 'Invoice 00028',
                'total_amount' => 815.02,
                'approved_at' => null,
            ],
            'period' => 'August 2026',
            'roleLabel' => 'photographer',
        ])->render();

        $this->assertRenderedFooterContacts($html);
    }

    #[DataProvider('accessibleContactColorPairs')]
    public function test_contact_link_colors_meet_normal_text_contrast(
        string $foregroundKey,
        string $backgroundKey
    ): void {
        $branding = app(EmailBrandingConfig::class)->defaults();

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio($branding[$foregroundKey], $branding[$backgroundKey]),
            "{$foregroundKey} must remain readable against {$backgroundKey}."
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessibleContactColorPairs(): array
    {
        return [
            'light footer' => ['link_color_light', 'footer_surface_light'],
            'dark footer' => ['link_color_dark', 'footer_surface_dark'],
        ];
    }

    #[DataProvider('emailThemes')]
    public function test_full_footer_and_universal_logo_do_not_depend_on_dark_mode_image_swapping(?string $theme): void
    {
        $html = view('emails.client_email_verified', [
            'user' => (object) ['name' => 'Jamie Example', 'email' => 'jamie@example.com'],
            'dashboardUrl' => 'https://reprodashboard.com',
            'settingsUrl' => 'https://reprodashboard.com/settings',
            'emailPreviewTheme' => $theme,
        ])->render();
        $xpath = $this->xpath($html);
        $logos = $xpath->query('//img[contains(@class, "email-logo-universal")]');
        $providerFooters = $xpath->query('//td[@data-email-footer="atelier"]//div[@data-email-provider-footer="true"]');
        $this->assertCount(1, $providerFooters);
        $this->assertStringContainsString('dark-muted', $providerFooters->item(0)->getAttribute('class'));
        $this->assertStringContainsString('font-size:11px', $providerFooters->item(0)->getAttribute('style'));
        $this->assertStringNotContainsString('[CLIENTS.ADDRESS]', $html);
        $this->assertStringNotContainsString('[GLOBAL_UNSUBSCRIBE]', $html);
        $this->assertCount(2, $logos);
        foreach ($logos as $logo) {
            $this->assertStringEndsWith('/images/repro-email-logo-grey.png', $logo->getAttribute('src'));
            $this->assertStringContainsString('display:block', $logo->getAttribute('style'));
            $this->assertStringNotContainsString('display:none', $logo->getAttribute('style'));
            $this->assertStringNotContainsString('max-height:0', $logo->getAttribute('style'));
        }
        $this->assertStringNotContainsString('logo-light', $html);
        $this->assertStringNotContainsString('logo-dark', $html);
        $this->assertStringNotContainsString('[CLIENT.ADDRESS]', $html);
        foreach (['Need help with a shoot, invoice, or account question?', 'Website', 'View products and services to order.', 'Leave a Review', 'We are looking for 5 stars and nothing less.', 'Please keep this message for your records', 'Thank you for the opportunity.'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }
        $branding = app(EmailBrandingConfig::class)->defaults();
        foreach (['facebook', 'instagram', 'linkedin'] as $network) {
            $this->assertStringContainsString('href="'.$branding['social_'.$network.'_url'].'"', $html);
            $icons = $xpath->query('//img[@src="'.$branding['social_'.$network.'_icon_url'].'"]');
            $this->assertCount(1, $icons);
            $this->assertSame('28', $icons->item(0)->getAttribute('width'));
            $assetPath = public_path(ltrim(parse_url($icons->item(0)->getAttribute('src'), PHP_URL_PATH), '/'));
            $this->assertFileExists($assetPath);
            $dimensions = getimagesize($assetPath);
            $this->assertSame([96, 96, IMAGETYPE_PNG], array_slice($dimensions, 0, 3));
        }
    }

    public static function emailThemes(): array
    {
        return [['light'], ['dark'], [null]];
    }

    private function assertRenderedFooterContacts(string $html): void
    {
        $xpath = $this->xpath($html);

        $phone = $this->singleFooterContact($xpath, self::CANONICAL_PHONE_HREF);
        $email = $this->singleFooterContact($xpath, 'mailto:'.self::CANONICAL_EMAIL);

        $this->assertSame(self::CANONICAL_PHONE, trim($phone->textContent));
        $this->assertSame(self::CANONICAL_EMAIL, trim($email->textContent));

        foreach ([$phone, $email] as $contact) {
            $this->assertContains(
                'footer-contact-link',
                preg_split('/\s+/', trim($contact->getAttribute('class'))) ?: [],
                'The rendered contact anchor must opt into the dark-mode-safe footer link rules.'
            );
            $branding = app(EmailBrandingConfig::class)->defaults();
            $color = $branding['link_color_light'];
            $this->assertMatchesRegularExpression(
                '/(?:^|;)\s*color:\s*'.preg_quote($color, '/').'\s*;/i',
                $contact->getAttribute('style'),
                'The rendered contact anchor needs a readable inline fallback when a mail client strips embedded CSS.'
            );
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($color, $branding['footer_surface_light'])
            );
        }
        $this->assertMatchesRegularExpression('/(?:^|;)\s*text-decoration:\s*underline\s*;?/i', $email->getAttribute('style'));
    }

    private function singleFooterContact(DOMXPath $xpath, string $href): DOMElement
    {
        $escapedHref = str_replace("'", '&apos;', $href);
        $nodes = $xpath->query(
            "//a[@href='{$escapedHref}' and contains(concat(' ', normalize-space(@class), ' '), ' footer-contact-link ')]"
        );

        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Expected one rendered footer contact link for {$href}.");

        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded, 'Expected the rendered email to be valid enough for DOM inspection.');

        return new DOMXPath($dom);
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
        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
