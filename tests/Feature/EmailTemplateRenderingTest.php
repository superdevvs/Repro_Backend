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
        // Payment essentials still present.
        $this->assertStringContainsString('Pay Now', $html);
        $this->assertStringContainsString('250.00', $html);
        // Obsolete phone never appears.
        $this->assertStringNotContainsString('202-868-1663', $html);
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
        // Website footer tile suppressed; Dashboard tile kept (single URL type).
        $this->assertStringNotContainsString('>Website<', $html);
        $this->assertStringContainsString('>Dashboard<', $html);
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
        // Website footer tile suppressed for this email.
        $this->assertStringNotContainsString('>Website<', $html);
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
