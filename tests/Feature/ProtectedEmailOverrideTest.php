<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\SystemEmails\EmailTypeRegistry;
use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2: protected (Blade) automated emails can be overridden by a DB template
 * once an admin opts in. Disabled by default => the hardcoded Blade view is used.
 */
class ProtectedEmailOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'recipient' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'company_name' => 'Acme Realty',
                'phonenumber' => '555-1212',
            ],
            'account' => ['company_name' => 'Acme Realty'],
            'branding' => [
                'product_name' => 'R/E Pro Photos',
                'support_email' => 'contact@reprophotos.com',
                'dashboard_url' => 'https://reprodashboard.com',
            ],
            'links' => ['dashboard' => 'https://reprodashboard.com'],
            'meta' => [],
        ];
    }

    private function definition()
    {
        return app(EmailTypeRegistry::class)->definition('ACCOUNT_CREATED');
    }

    public function test_enabled_override_template_replaces_blade_output(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Account Override',
            'slug' => 'account-created-override',
            'subject' => 'Welcome [client_first_name]',
            'body_html' => '<p>Custom override body for [client_first_name].</p>',
            'body_text' => 'Custom override body',
            'variables_json' => ['client_first_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => true,
        ]);

        $result = app(SystemEmailRenderer::class)
            ->render($this->definition(), $this->payload(), 'Default Subject');

        $this->assertStringContainsString('Custom override body for Jane', $result['body_html']);
        $this->assertSame('Welcome Jane', $result['subject']);
    }

    public function test_disabled_override_falls_back_to_blade(): void
    {
        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Account Override',
            'slug' => 'account-created-override',
            'subject' => 'Welcome [client_first_name]',
            'body_html' => '<p>Custom override body for [client_first_name].</p>',
            'body_text' => 'Custom override body',
            'variables_json' => ['client_first_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
            'email_type' => 'ACCOUNT_CREATED',
            'override_enabled' => false,
        ]);

        $result = app(SystemEmailRenderer::class)
            ->render($this->definition(), $this->payload(), 'Default Subject');

        $this->assertStringNotContainsString('Custom override body', $result['body_html']);
        // Blade hero eyebrow for the New Account email.
        $this->assertStringContainsString('Account Created', $result['body_html']);
        $this->assertSame('Default Subject', $result['subject']);
    }
}
