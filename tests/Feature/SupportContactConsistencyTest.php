<?php

namespace Tests\Feature;

use App\Services\SystemEmails\EmailBrandingConfig;
use Database\Seeders\MessagingSystemSeeder;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * QA #10 — Email + SMS template audit.
 *
 * Guards the single-source-of-truth for the support phone so no template or code
 * path can reintroduce the obsolete number (202-868-1663). Canonical = 202-868-1113.
 */
class SupportContactConsistencyTest extends TestCase
{
    private const CANONICAL_PHONE = '202-868-1113';
    private const OBSOLETE_PHONE = '202-868-1663';

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
}
