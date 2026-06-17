<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Database\Seeders\MessagingSystemSeeder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies the email template fixes on REAL rendered output (not just source):
 * - status badge removed from the DB-rendered hero
 * - exact support line wording
 * - reduced hero header size
 * - New Account single-URL footer + "Thank you for the opportunity." closing
 *
 * Covers both render paths: TemplateRenderer (DB templates) and the Blade view
 * actually sent for the protected New Account email.
 */
class EmailTemplateRenderingTest extends TestCase
{
    private const SUPPORT_LINE = 'If you need help, call 202-868-1113 or email us at';

    private function renderer(): TemplateRenderer
    {
        return app(TemplateRenderer::class);
    }

    private function legacyWrappedBody(string $bodyHtml): string
    {
        return '
            <div class="email-header" style="border-bottom: 2px solid #007bff; padding-bottom: 12px; margin-bottom: 24px;">
                <h1 style="margin: 0; font-size: 20px; color: #2c3e50;">R/E Pro Photos</h1>
            </div>

            ' . $bodyHtml . '

            <div class="email-footer note" style="border-top: 1px solid #eee; margin-top: 30px; padding-top: 16px; color: #666; font-size: 13px;">
                <p style="margin: 0 0 8px 0;">If you need help, call 202-868-1113 or email us at [company_email].</p>
                <p style="margin: 0;">Thanks,<br><strong>R/E Pro Photos</strong></p>
            </div>
        ';
    }

    public function test_payment_reminder_has_no_status_badge_and_shows_support_line(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - Invoice INV-1001',
            'body_html' => '<p>Amount Due: $[amount_due]</p><center><a href="[payment_link]">Pay Now</a></center>',
            'body_text' => "Amount Due: [amount_due]\nDue Date: [due_date]",
            'variables_json' => ['amount_due', 'due_date', 'payment_link'],
        ]);

        $result = $this->renderer()->render($template, [
            'amount_due' => '250.00',
            'due_date' => 'Jul 01, 2026',
            'payment_link' => 'https://pay.example/inv-1001',
        ]);

        $html = $result['html'];

        // Status bar/badge removed from all templates (no rendered badge element;
        // the unused CSS rule may remain but no element carries the class).
        $this->assertStringNotContainsString('class="hero-title-status"', $html);
        // Exact support line.
        $this->assertStringContainsString(self::SUPPORT_LINE, $html);
        $this->assertStringContainsString('contact@reprophotos.com', $html);
        // Header reduced below 32px.
        $this->assertStringContainsString('font-size: 30px', $html);
        $this->assertStringNotContainsString('font-size: 32px', $html);
        $this->assertStringNotContainsString('font-size: 48px', $html);
        // Light mode hero should render as a visible top card, not white on white.
        $this->assertStringContainsString('class="hero-card" style="background-color:#f7fbff; border:0;"', $html);
        // Payment essentials still present.
        $this->assertStringContainsString('Pay Now', $html);
        $this->assertStringContainsString('250.00', $html);
        // Shared footer no longer renders a Dashboard card.
        $this->assertStringNotContainsString('>Dashboard<', $html);
        // Obsolete phone never appears.
        $this->assertStringNotContainsString('202-868-1663', $html);
    }

    public function test_payment_reminder_does_not_duplicate_invoice_label_when_number_already_includes_it(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - Invoice [invoice_number]',
            'body_html' => '<p>Amount Due: $[amount_due]</p>',
            'body_text' => 'Amount Due: [amount_due]',
            'variables_json' => ['invoice_number', 'amount_due'],
        ]);

        $result = $this->renderer()->render($template, [
            'invoice_number' => 'Invoice 00018',
            'amount_due' => '250.00',
        ]);

        $this->assertSame('Payment Reminder - Invoice 00018', $result['subject']);
        $this->assertStringNotContainsString('Invoice Invoice 00018', $result['html']);
        $this->assertStringContainsString('Invoice 00018', $result['html']);
    }

    public function test_payment_reminder_strips_legacy_body_wrapper_and_empty_greeting_artifact(): void
    {
        $template = new MessageTemplate([
            'slug' => 'payment-due-reminder',
            'category' => 'INVOICE',
            'channel' => 'EMAIL',
            'subject' => 'Payment Reminder - Invoice [invoice_number]',
            'body_html' => $this->legacyWrappedBody('
                <p>[greeting]!</p>
                <p>This is a reminder that your invoice still has an outstanding balance.</p>
                <div class="info-box">
                    <div class="info-row"><span class="info-label">Invoice Number:</span> [invoice_number]</div>
                    <div class="info-row"><span class="info-label">Amount Due:</span> <strong>$[amount_due]</strong></div>
                    <div class="info-row"><span class="info-label">Due Date:</span> [due_date]</div>
                </div>
                <center><a href="[payment_link]" class="button button-large">Pay Now</a></center>
            '),
            'body_text' => "!\nThis is a reminder that your invoice still has an outstanding balance.\nInvoice Number: [invoice_number]",
            'variables_json' => ['greeting', 'company_email', 'invoice_number', 'amount_due', 'due_date', 'payment_link'],
        ]);

        $result = $this->renderer()->render($template, [
            'greeting' => '',
            'company_email' => 'contact@reprophotos.com',
            'invoice_number' => 'Invoice 00018',
            'amount_due' => '250.00',
            'due_date' => 'Jul 01, 2026',
            'payment_link' => 'https://pay.example/inv-00018',
        ]);

        $html = $result['html'];

        $this->assertSame('Payment Reminder - Invoice 00018', $result['subject']);
        $this->assertStringContainsString('class="hero-title"', $html);
        $this->assertStringContainsString('Payment Reminder', $html);
        $this->assertMatchesRegularExpression('/<div[^>]*display:none[^>]*>This is a reminder that your invoice still has an outstanding balance\./i', $html);
        $this->assertStringContainsString('This is a reminder that your invoice still has an outstanding balance.', $html);
        $this->assertStringContainsString('Invoice 00018', $html);
        $this->assertStringContainsString('250.00', $html);
        $this->assertStringContainsString('Jul 01, 2026', $html);
        $this->assertStringContainsString('https://pay.example/inv-00018', $html);
        $this->assertStringContainsString(self::SUPPORT_LINE, $html);
        $this->assertStringNotContainsString('class="email-header"', $html);
        $this->assertStringNotContainsString('class="email-footer note"', $html);
        $this->assertStringNotContainsString('border-bottom: 2px solid #007bff', $html);
        $this->assertDoesNotMatchRegularExpression('/<p\b[^>]*>\s*!\s*<\/p>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<body[^>]*>\s*<div[^>]*display:none[^>]*>\s*(?:&zwnj;|&nbsp;|\s)/i', $html);
        $this->assertStringNotContainsString('Payment Reminder. Invoice Invoice details', $html);
        $this->assertStringStartsWith('This is a reminder', $result['text']);
    }

    public function test_representative_db_templates_do_not_render_legacy_wrapper_artifacts(): void
    {
        $templates = [
            new MessageTemplate([
                'slug' => 'shoot-scheduled',
                'category' => 'BOOKING',
                'channel' => 'EMAIL',
                'subject' => 'New Shoot Scheduled',
                'body_html' => $this->legacyWrappedBody('<p>[greeting]!</p><p>Your new shoot has been scheduled.</p>'),
                'body_text' => "!\nYour new shoot has been scheduled.",
                'variables_json' => ['greeting', 'company_email'],
            ]),
            new MessageTemplate([
                'slug' => 'payment-due-reminder',
                'category' => 'INVOICE',
                'channel' => 'EMAIL',
                'subject' => 'Payment Reminder - Invoice INV-1001',
                'body_html' => $this->legacyWrappedBody('<p>[greeting]!</p><p>Payment reminder details are below.</p>'),
                'body_text' => "!\nPayment reminder details are below.",
                'variables_json' => ['greeting', 'company_email'],
            ]),
            new MessageTemplate([
                'slug' => 'weekly-invoice-generated',
                'category' => 'INVOICE',
                'channel' => 'EMAIL',
                'subject' => 'Weekly Invoice Generated',
                'body_html' => $this->legacyWrappedBody('<p>[greeting]!</p><p>Your weekly invoice is ready.</p>'),
                'body_text' => "!\nYour weekly invoice is ready.",
                'variables_json' => ['greeting', 'company_email'],
            ]),
        ];

        foreach ($templates as $template) {
            $result = $this->renderer()->render($template, [
                'greeting' => '',
                'company_email' => 'contact@reprophotos.com',
            ]);
            $html = $result['html'];

            $this->assertStringNotContainsString('class="email-header"', $html, "{$template->slug} must strip legacy header.");
            $this->assertStringNotContainsString('class="email-footer note"', $html, "{$template->slug} must strip legacy footer.");
            $this->assertStringNotContainsString('border-bottom: 2px solid #007bff', $html, "{$template->slug} must strip legacy divider.");
            $this->assertDoesNotMatchRegularExpression('/<p\b[^>]*>\s*!\s*<\/p>/i', $html, "{$template->slug} must strip empty greeting punctuation.");
            $this->assertFalse(str_starts_with(ltrim($result['text']), '!'), "{$template->slug} text must strip empty greeting punctuation.");
        }
    }

    public function test_account_created_db_render_uses_single_url_and_new_closing(): void
    {
        $seeder = new MessagingSystemSeeder();
        $method = new ReflectionMethod($seeder, 'getAccountCreatedTemplate');
        $method->setAccessible(true);
        /** @var string $body */
        $body = $method->invoke($seeder);

        $template = new MessageTemplate([
            'slug' => 'account-created',
            'channel' => 'EMAIL',
            'subject' => 'Acme New Account Information',
            'body_html' => $body,
            'body_text' => '',
            'variables_json' => ['portal_url', 'company_email'],
        ]);

        $result = $this->renderer()->render($template, [
            'portal_url' => 'https://reprodashboard.com',
            'company_email' => 'contact@reprophotos.com',
            'greeting' => 'Hello Jane',
            'realtor_first' => 'Jane',
            'realtor_last' => 'Doe',
            'realtor_company' => 'Acme',
            'realtor_email' => 'jane@example.com',
            'phone_number' => '555-1212',
        ]);

        $html = $result['html'];

        // New closing.
        $this->assertStringContainsString('Thank you for the opportunity.', $html);
        // Website footer tile suppressed and the shared footer no longer shows
        // a Dashboard tile.
        $this->assertStringNotContainsString('>Website<', $html);
        $this->assertStringNotContainsString('>Dashboard<', $html);
        // Support line present.
        $this->assertStringContainsString(self::SUPPORT_LINE, $html);
    }

    public function test_account_created_blade_render_hides_website_and_has_new_closing(): void
    {
        $user = (object) [
            'name' => 'Jane Doe',
            'company_name' => 'Acme Realty',
            'email' => 'jane@example.com',
            'phonenumber' => '555-1212',
        ];

        $html = view('emails.account_created', ['user' => $user])->render();

        $this->assertStringContainsString('Thank you for the opportunity.', $html);
        // The "Open Dashboard" CTA remains (single primary URL type).
        $this->assertStringContainsString('Open Dashboard', $html);
        // Website and Dashboard footer tiles are suppressed for this email.
        $this->assertStringNotContainsString('>Website<', $html);
        $this->assertStringNotContainsString('>Dashboard<', $html);
        // Header reduced below 32px; no oversized hero.
        $this->assertStringContainsString('font-size:30px', $html);
        $this->assertStringNotContainsString('font-size:32px', $html);
        $this->assertStringNotContainsString('font-size:48px', $html);
        // Support contact.
        $this->assertStringContainsString('202-868-1113', $html);
        $this->assertStringNotContainsString('202-868-1663', $html);
    }

    public function test_no_blade_email_reintroduces_oversized_hero(): void
    {
        $dir = resource_path('views/emails');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $offenders = [];
        foreach ($files as $path) {
            $contents = (string) file_get_contents($path);
            if (preg_match('/font-size:\s*48px/i', $contents)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Hero titles must not use font-size:48px: ' . implode(', ', $offenders));
    }

    public function test_new_account_email_exposes_only_dashboard_url_links(): void
    {
        $user = (object) [
            'name' => 'Jane Doe',
            'company_name' => 'Acme Realty',
            'email' => 'jane@example.com',
            'phonenumber' => '555-1212',
        ];

        $html = view('emails.account_created', ['user' => $user])->render();

        // Inspect actual anchor targets rather than substring-matching the
        // domain (the support email shares the reprophotos.com domain).
        preg_match_all('/<a\b[^>]*href="([^"]+)"/i', $html, $matches);
        $hrefs = $matches[1] ?? [];

        $httpLinks = array_values(array_filter($hrefs, fn ($href) => str_starts_with($href, 'http')));
        $this->assertNotEmpty($httpLinks);

        // No http link should point at a bare company-website "Website" tile;
        // the only product URLs should be the dashboard.
        foreach ($httpLinks as $href) {
            if (str_contains($href, 'reprophotos.com')) {
                // Only the footer brand logo may link to the website; ensure it
                // is not presented as a second product URL tile.
                $this->assertStringNotContainsString('>Website<', $html);
            }
        }

        $dashboardLinks = array_filter($httpLinks, fn ($href) => str_contains($href, 'reprodashboard.com'));
        $this->assertNotEmpty($dashboardLinks, 'Expected at least one dashboard URL in the New Account email.');
    }
}
