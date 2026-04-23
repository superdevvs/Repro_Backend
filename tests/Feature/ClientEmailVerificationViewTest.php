<?php

namespace Tests\Feature;

use App\Services\SystemEmails\EmailBrandingConfig;
use Tests\TestCase;

class ClientEmailVerificationViewTest extends TestCase
{
    public function test_client_email_verification_email_uses_the_shared_branded_layout(): void
    {
        $user = (object) [
            'name' => 'Shubham Prasad',
            'email' => 'shubham@example.com',
        ];

        $html = view('emails.client_email_verification', [
            'user' => $user,
            'verificationLink' => 'https://api.reprodashboard.com/api/email/verify/1/hash?token=verification-token',
            'dashboardUrl' => 'https://reprodashboard.com',
        ])->render();

        $this->assertStringContainsString('Verify your email to unlock normal updates.', $html);
        $this->assertStringContainsString('shubham@example.com', $html);
        $this->assertStringContainsString('Verify Email', $html);
        $this->assertStringContainsString('Open Dashboard', $html);
        $this->assertStringContainsString('hero-card-bg', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('max-width:720px', $html);
        $this->assertStringContainsString('https://reprodashboard.com', $html);
        $this->assertStringContainsString('https://api.reprodashboard.com/api/email/verify/1/hash', $html);
        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringNotContainsString('/images/Repro%20HQ%20dark.png', $html);
        $normalizedHtml = str_replace(' ', '', strtolower($html));
        $this->assertStringContainsString('background-color:#ffffff', $normalizedHtml);
        $this->assertStringContainsString('background-color:#ffffff;border:0;border-radius:24px24px00', $normalizedHtml);
        $this->assertStringContainsString('@media(prefers-color-scheme:dark)', $normalizedHtml);
        $this->assertStringContainsString('background-color:#111c2e!important', $normalizedHtml);
        $this->assertStringContainsString('color:#071223!important', $normalizedHtml);
        $this->assertStringContainsString('color:#e8edf5!important', $normalizedHtml);
        $this->assertStringNotContainsString('@extends(', $html);
    }

    public function test_account_created_email_uses_the_grey_logo_and_dark_safe_hero_title(): void
    {
        $user = (object) [
            'name' => 'Shubham Prasad',
            'email' => 'shubham@example.com',
            'company_name' => 'R/E Pro Photos',
            'phonenumber' => '202-555-0100',
        ];

        $html = view('emails.account_created', [
            'user' => $user,
            'resetLink' => 'https://reprodashboard.com/reset-password?token=test&email=shubham@example.com',
            'verificationLink' => 'https://api.reprodashboard.com/api/email/verify/1/hash?token=verification-token',
        ])->render();

        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringContainsString('Your dashboard access is ready.', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringNotContainsString('/images/repro-logo.png', $html);
        $this->assertStringNotContainsString('Create Password', $html);
        $this->assertStringContainsString('Verify Email', $html);
        $this->assertStringContainsString('https://api.reprodashboard.com/api/email/verify/1/hash?token=verification-token', $html);
        $this->assertTrue(
            strpos($html, 'Verify Email') < strpos($html, 'Open Dashboard'),
            'Verify Email CTA should appear before Open Dashboard.'
        );
    }

    public function test_client_email_verified_confirmation_uses_dashboard_and_settings_actions(): void
    {
        $user = (object) [
            'name' => 'Shubham Prasad',
            'email' => 'shubham@example.com',
        ];

        $html = view('emails.client_email_verified', [
            'user' => $user,
            'dashboardUrl' => 'https://reprodashboard.com',
            'settingsUrl' => 'https://reprodashboard.com/settings',
        ])->render();

        $this->assertStringContainsString('You are all set for updates.', $html);
        $this->assertStringContainsString('Notification Settings', $html);
        $this->assertStringContainsString('https://reprodashboard.com/settings', $html);
        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
    }

    public function test_verification_result_page_uses_the_light_logo_branding_token(): void
    {
        $branding = app(EmailBrandingConfig::class)->defaults();

        $html = view('email_verification_result', [
            'title' => 'Verification link invalid',
            'message' => 'This verification link is invalid or has expired.',
            'success' => false,
            'dashboardUrl' => 'https://reprodashboard.com',
            'branding' => $branding,
        ])->render();

        $this->assertStringContainsString('/images/repro-email-logo-light.png', $html);
        $this->assertStringNotContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringContainsString('#00141d', $html);
    }

    public function test_shoot_removed_email_uses_the_shared_theme_contract(): void
    {
        $shoot = (object) [
            'dashboard_url' => 'https://reprodashboard.com/shoots/1',
            'location' => '123 Main St, Washington, DC',
            'status_label' => 'Cancelled',
            'service_category' => 'Photography',
            'is_private_listing' => false,
            'date' => 'April 23, 2026',
            'time' => '10:00 AM',
            'client_name' => 'Shubham Prasad',
            'client_email' => 'shubham@example.com',
            'rep_name' => null,
            'photographers_label' => 'TBD',
            'primary_photographer' => null,
            'photographers' => [],
            'property_highlights' => [],
            'services' => [],
            'access_details' => [],
            'notes_lines' => [],
            'company_notes_lines' => [],
            'photographer_notes_lines' => [],
            'tax' => 0,
            'tax_rate' => 0,
            'formatted_subtotal' => '$0.00',
            'formatted_tax' => '$0.00',
            'formatted_grand_total' => '$0.00',
        ];

        $html = view('emails.shoot_removed', [
            'shoot' => $shoot,
        ])->render();

        $normalizedHtml = str_replace(' ', '', strtolower($html));

        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('background-color:#ffffff', $normalizedHtml);
        $this->assertStringContainsString('background-color:#111c2e!important', $normalizedHtml);
        $this->assertStringContainsString('background-color:#fff0f1', $normalizedHtml);
        $this->assertStringContainsString('background-color:#351b22!important', $normalizedHtml);
    }

    public function test_invoice_generated_email_uses_the_shared_theme_contract(): void
    {
        $invoice = (object) [
            'invoice_number' => 'INV-1001',
            'status' => 'pending',
            'total_amount' => 250.00,
            'items' => collect([
                (object) [
                    'description' => 'Photography package',
                    'type' => 'service',
                    'total_amount' => 250.00,
                ],
            ]),
        ];

        $html = view('emails.invoice_generated', [
            'invoice' => $invoice,
            'period' => 'Apr 16 - Apr 22, 2026',
            'recipientRole' => 'photographer',
            'photographer' => (object) ['name' => 'Shubham Prasad'],
        ])->render();

        $normalizedHtml = str_replace(' ', '', strtolower($html));

        $this->assertStringContainsString('content="light dark"', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('background-color:#f5f9ff', $normalizedHtml);
        $this->assertStringContainsString('background-color:#16233a!important', $normalizedHtml);
        $this->assertStringContainsString('background-color:#f7fbff', $normalizedHtml);
        $this->assertStringContainsString('background-color:#111c2e!important', $normalizedHtml);
    }
}
