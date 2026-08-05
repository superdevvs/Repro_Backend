<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\SystemEmails\EmailBrandingConfig;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * QA #10 — Email + SMS template audit.
 *
 * Guards the single-source-of-truth for the support phone so no template or code
 * path can reintroduce the obsolete number (202-868-1113). Canonical = 202-868-1663.
 */
class SupportContactConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_PHONE = '202-868-1663';
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
