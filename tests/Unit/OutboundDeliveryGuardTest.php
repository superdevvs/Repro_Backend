<?php

namespace Tests\Unit;

use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The environment, not the presence of credentials, decides whether a message
 * leaves the building.
 *
 * Local QA reached the live Telnyx API because the local environment file holds
 * real credentials and every layer above assumed credentials meant "send".
 *
 * `$this->app['env']` is what `Application::environment()` reads, so setting it
 * exercises the real guard against each environment without booting a separate
 * app per case.
 */
class OutboundDeliveryGuardTest extends TestCase
{
    private function guardIn(string $environment): OutboundDeliveryGuard
    {
        $this->app['env'] = $environment;

        return new OutboundDeliveryGuard($this->app);
    }

    private function optIn(bool $enabled, string $allowlist = ''): void
    {
        Config::set('messaging.allow_external', $enabled);
        Config::set('messaging.allowlist', $allowlist);
    }

    public function test_testing_environment_never_delivers_even_with_opt_in_and_allowlist(): void
    {
        $this->optIn(true, '+14155559999,qa@realdomain.co');
        $guard = $this->guardIn('testing');

        $this->assertFalse($guard->allows('SMS', '+14155559999'));
        $this->assertFalse($guard->allows('EMAIL', 'qa@realdomain.co'));
        $this->assertSame(
            OutboundDeliveryGuard::REASON_BLOCKED_TESTING,
            $guard->decide('SMS', '+14155559999')['reason']
        );
    }

    public function test_local_blocks_by_default(): void
    {
        $this->optIn(false);
        $guard = $this->guardIn('local');

        $this->assertFalse($guard->allows('SMS', '+14155550123'));
        $this->assertSame(
            OutboundDeliveryGuard::REASON_BLOCKED_NO_OPT_IN,
            $guard->decide('EMAIL', 'someone@realdomain.co')['reason']
        );
    }

    public function test_development_blocks_by_default(): void
    {
        $this->optIn(false);
        $guard = $this->guardIn('development');

        $this->assertFalse($guard->allows('SMS', '+14155550123'));
    }

    public function test_production_delivers(): void
    {
        $this->optIn(false);
        $guard = $this->guardIn('production');

        $this->assertTrue($guard->allows('SMS', '+14155550123'));
        $this->assertTrue($guard->allows('EMAIL', 'client@realdomain.co'));
        $this->assertSame(
            OutboundDeliveryGuard::REASON_ALLOWED_PRODUCTION,
            $guard->decide('SMS', '+14155550123')['reason']
        );
    }

    public function test_local_opt_in_without_allowlist_delivers_to_nobody(): void
    {
        // "Allow real delivery" without naming a recipient must not be read as
        // "allow real delivery to everyone".
        $this->optIn(true, '');
        $guard = $this->guardIn('local');

        $this->assertFalse($guard->allows('EMAIL', 'client@realdomain.co'));
        $this->assertSame(
            OutboundDeliveryGuard::REASON_BLOCKED_NOT_ALLOWLISTED,
            $guard->decide('EMAIL', 'client@realdomain.co')['reason']
        );
    }

    public function test_local_opt_in_delivers_only_to_allowlisted_recipients(): void
    {
        // Note the 523 exchange: a 555 exchange is the reserved fictitious range
        // and is rejected as fixture data regardless of the allowlist.
        $this->optIn(true, 'qa@realdomain.co, +14155239999');
        $guard = $this->guardIn('local');

        $this->assertTrue($guard->allows('EMAIL', 'qa@realdomain.co'));
        $this->assertTrue($guard->allows('SMS', '+14155239999'));

        $this->assertFalse($guard->allows('EMAIL', 'someone.else@realdomain.co'));
        $this->assertFalse($guard->allows('SMS', '+14155231111'));
    }

    public function test_allowlisted_phone_matches_regardless_of_formatting(): void
    {
        $this->optIn(true, '(415) 523-9999');
        $guard = $this->guardIn('local');

        $this->assertTrue($guard->allows('SMS', '+14155239999'));
        $this->assertTrue($guard->allows('SMS', '4155239999'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureRecipients')]
    public function test_fixture_recipients_are_never_delivered_to_outside_production(string $channel, string $recipient): void
    {
        // Opt-in enabled AND the fixture explicitly allowlisted — still blocked.
        $this->optIn(true, $recipient);
        $guard = $this->guardIn('local');

        $this->assertFalse(
            $guard->allows($channel, $recipient),
            "Fixture recipient should never be delivered to: {$recipient}"
        );
        $this->assertSame(
            OutboundDeliveryGuard::REASON_BLOCKED_FIXTURE,
            $guard->decide($channel, $recipient)['reason']
        );
    }

    public static function fixtureRecipients(): array
    {
        return [
            // The exact number that reached Telnyx during Phase C.
            'phase C fixture number' => ['SMS', '5552223333'],
            'e164 fixture number' => ['SMS', '+15552223333'],
            'reserved 555 range' => ['SMS', '5550123456'],
            'all zeroes' => ['SMS', '+0000000000'],
            'sequential filler' => ['SMS', '1234567890'],
            'example.com email' => ['EMAIL', 'test.client@example.com'],
            'example.org email' => ['EMAIL', 'someone@example.org'],
            'reserved test tld' => ['EMAIL', 'qa@company.test'],
            'reserved invalid tld' => ['EMAIL', 'qa@company.invalid'],
            'localhost' => ['EMAIL', 'root@localhost'],
        ];
    }

    public function test_missing_recipient_is_blocked(): void
    {
        $this->optIn(true, 'qa@realdomain.co');
        $guard = $this->guardIn('local');

        $this->assertFalse($guard->allows('SMS', null));
        $this->assertFalse($guard->allows('SMS', '   '));
    }

    public function test_masking_keeps_logs_useful_without_leaking_contacts(): void
    {
        $guard = $this->guardIn('local');

        // 11 digits in, last 4 kept: 7 masked characters.
        $this->assertSame('*******3333', $guard->maskRecipient('+15552223333'));
        // "test.client" is 11 characters; the first 2 are kept, 9 are masked.
        $this->assertSame('te*********@example.com', $guard->maskRecipient('test.client@example.com'));
        $this->assertSame('(none)', $guard->maskRecipient(''));
    }
}
