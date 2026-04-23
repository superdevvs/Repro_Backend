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
        $this->assertStringContainsString('content="light"', $html);
        $this->assertStringContainsString('max-width:720px', $html);
        $this->assertStringContainsString('https://reprodashboard.com', $html);
        $this->assertStringContainsString('https://api.reprodashboard.com/api/email/verify/1/hash', $html);
        $this->assertStringContainsString('/images/repro-email-logo-grey.png', $html);
        $this->assertStringNotContainsString('/images/Repro%20HQ%20dark.png', $html);
        $normalizedHtml = str_replace(' ', '', strtolower($html));
        $this->assertStringContainsString('background-color:#ffffff', $normalizedHtml);
        $this->assertStringContainsString('background-color:#111c2e', $normalizedHtml);
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
        $this->assertStringContainsString('color:#e8edf5', str_replace(' ', '', $html));
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
}
