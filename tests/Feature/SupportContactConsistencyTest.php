<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\EmailBrandingConfig;
use App\Support\SupportContact;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * QA #10 — Email + SMS template audit.
 *
 * Guards the single-source-of-truth for the support phone so no template or code
 * path can reintroduce the obsolete number (202-868-1113). Canonical display
 * format = (202) 868-1663.
 */
class SupportContactConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_PHONE = '(202) 868-1663';
    private const OBSOLETE_PHONE = '202-868-1113';

    public function test_mail_config_uses_canonical_support_phone(): void
    {
        $this->assertSame(self::CANONICAL_PHONE, config('mail.contact_phone'));
    }

    public function test_email_branding_defaults_use_canonical_support_phone(): void
    {
        $branding = (new EmailBrandingConfig())->defaults();

        $this->assertSame(self::CANONICAL_PHONE, $branding['support_phone']);
        $this->assertNotSame(self::OBSOLETE_PHONE, $branding['support_phone']);
    }

    public function test_stale_config_and_branding_overrides_cannot_reintroduce_old_support_phone(): void
    {
        config(['mail.contact_phone' => self::OBSOLETE_PHONE]);

        $branding = (new EmailBrandingConfig())->defaults([
            'support_phone' => self::OBSOLETE_PHONE,
        ]);
        $variables = app(TemplateVariableResolver::class)->resolve([
            'company_phone' => self::OBSOLETE_PHONE,
        ]);

        $this->assertSame(SupportContact::PHONE_DISPLAY, $branding['support_phone']);
        $this->assertSame(SupportContact::PHONE_DISPLAY, $variables['company_phone']);
    }

    public function test_account_created_plain_text_has_no_obsolete_phone_or_stale_brand(): void
    {
        $seeder = new MessagingSystemSeeder();
        $method = new ReflectionMethod($seeder, 'getAccountCreatedPlainText');
        $method->setAccessible(true);

        /** @var string $body */
        $body = $method->invoke($seeder);

        $this->assertStringNotContainsString(self::OBSOLETE_PHONE, $body);
        $this->assertStringContainsString(self::CANONICAL_PHONE, $body);
        // Stale "REPRO HQ" / reprohq.com branding must be gone.
        $this->assertStringNotContainsString('reprohq.com', $body);
        // The reset-link placeholder must be preserved.
        $this->assertStringContainsString('[password_resetlink]', $body);
    }

    public function test_seeder_brand_phone_constant_is_canonical(): void
    {
        $reflection = new ReflectionClass(MessagingSystemSeeder::class);
        $constants = $reflection->getConstants();

        $this->assertSame(self::CANONICAL_PHONE, $constants['BRAND_PHONE']);
    }

    public function test_admin_edited_template_with_legacy_phone_is_normalized_without_mutating_saved_copy(): void
    {
        $template = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Production-edited account email',
            'slug' => 'production-edited-account-email',
            'category' => 'ACCOUNT',
            'subject' => 'Call 202-868-1113 for help',
            'body_html' => '<p>Questions? Call (202) 868-1113.</p>',
            'body_text' => 'Questions? Call 202.868.1113.',
            'variables_json' => [],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        $rendered = app(TemplateRenderer::class)->render($template, []);

        $renderedCopy = $rendered['subject'].' '.$rendered['html'].' '.$rendered['text'];
        $this->assertStringNotContainsString(self::OBSOLETE_PHONE, $renderedCopy);
        $this->assertStringContainsString(self::CANONICAL_PHONE, $rendered['subject']);
        $this->assertStringContainsString(self::CANONICAL_PHONE, $rendered['html']);
        $this->assertStringContainsString(self::CANONICAL_PHONE, $rendered['text']);

        $template->refresh();
        $this->assertSame('Call 202-868-1113 for help', $template->subject);
        $this->assertSame('<p>Questions? Call (202) 868-1113.</p>', $template->body_html);
        $this->assertSame('Questions? Call 202.868.1113.', $template->body_text);
    }

    public function test_render_normalizes_static_support_copy_without_changing_recipient_phone_variables(): void
    {
        $template = MessageTemplate::create([
            'channel' => 'SMS',
            'name' => 'Support and recipient phone isolation',
            'slug' => 'support-and-recipient-phone-isolation',
            'category' => 'ACCOUNT',
            'subject' => null,
            'body_html' => null,
            'body_text' => 'Client: {{client_phone}}. Support: 202-868-1113. Company: {{company_phone}}.',
            'variables_json' => ['client_phone', 'company_phone'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        $rendered = app(TemplateRenderer::class)->render($template, [
            'client_phone' => self::OBSOLETE_PHONE,
            'company_phone' => self::OBSOLETE_PHONE,
        ]);

        $this->assertStringContainsString('Client: '.self::OBSOLETE_PHONE, $rendered['text']);
        $this->assertStringContainsString('Support: '.self::CANONICAL_PHONE, $rendered['text']);
        $this->assertStringContainsString('Company: '.self::CANONICAL_PHONE, $rendered['text']);
    }

    public function test_deployment_migration_updates_only_saved_template_copy(): void
    {
        $template = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Legacy support formats',
            'slug' => 'legacy-support-formats',
            'description' => 'Support: 202 868 1113',
            'category' => 'ACCOUNT',
            'subject' => 'Call +1 202-868-1113',
            'body_html' => '<a href="tel:+12028681113">(202) 868-1113</a>',
            'body_text' => 'Call 202.868.1663',
            'variables_json' => [],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        $migration = require database_path('migrations/2026_08_16_000002_normalize_support_phone_in_message_templates.php');
        $migration->up();

        $template->refresh();

        $this->assertSame('Call '.self::CANONICAL_PHONE, $template->subject);
        $this->assertSame(
            '<a href="tel:'.SupportContact::PHONE_E164.'">'.self::CANONICAL_PHONE.'</a>',
            $template->body_html
        );
        $this->assertSame('Call '.self::CANONICAL_PHONE, $template->body_text);
        $this->assertSame('Support: '.self::CANONICAL_PHONE, $template->description);
    }

    /**
     * The reported bug was "the number is right in one template but not in all
     * emails", so per-template spot checks are not enough: every seeded EMAIL
     * template must render the canonical number, and the shared company tokens
     * must resolve to real contact details rather than being left literal or
     * blanked out.
     */
    public function test_every_seeded_email_template_renders_canonical_support_contact(): void
    {
        $this->seed(MessagingSystemSeeder::class);

        $variables = app(TemplateVariableResolver::class)->resolve([]);
        $this->assertSame(self::CANONICAL_PHONE, $variables['company_phone']);
        $this->assertSame(config('mail.contact_address'), $variables['company_email']);

        $renderer = app(TemplateRenderer::class);
        $templates = MessageTemplate::where('channel', 'EMAIL')->get();
        $this->assertNotEmpty($templates, 'Expected the messaging seeder to create EMAIL templates.');

        foreach ($templates as $template) {
            $rendered = $renderer->render($template, $variables);
            $body = $rendered['html'] . $rendered['text'];

            $this->assertStringContainsString(
                self::CANONICAL_PHONE,
                $body,
                "Template '{$template->slug}' must render the canonical support phone."
            );
            $this->assertStringNotContainsString(
                self::OBSOLETE_PHONE,
                $body,
                "Template '{$template->slug}' must not render the obsolete support phone."
            );

            foreach (['company_email', 'company_phone', 'company_name'] as $token) {
                $this->assertStringNotContainsString(
                    '{{' . $token . '}}',
                    $body,
                    "Template '{$template->slug}' left the {$token} shortcode unresolved."
                );
                $this->assertStringNotContainsString(
                    '[' . $token . ']',
                    $body,
                    "Template '{$template->slug}' left the {$token} shortcode unresolved."
                );
            }
        }
    }
}
