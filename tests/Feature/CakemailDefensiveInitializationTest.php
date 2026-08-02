<?php

namespace Tests\Feature;

use App\Models\MessageChannel;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\Providers\CakemailProvider;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Cakemail must degrade, not detonate.
 *
 * Production ran `CAKEMAIL_PASSWORD` through `config('services.cakemail.password')`,
 * which resolves to null when the env var is absent — the config key itself always
 * exists, so the `''` default never applied. Assigning null to the typed
 * `string $password` property threw during construction, and because
 * MessagingService constructor-injected the provider, the failure took down every
 * messaging flow rather than just email. The scheduler logged it every minute until
 * the deploy's `config:cache` repopulated the value.
 *
 * No test here sends anything: with credentials absent the provider fails before it
 * builds a request, and the credentials-present case never calls send().
 */
class CakemailDefensiveInitializationTest extends TestCase
{
    private function channel(): MessageChannel
    {
        return new MessageChannel([
            'type' => 'EMAIL',
            'provider' => 'CAKEMAIL',
            'display_name' => 'R/E Pro Photos',
            'from_email' => 'contact@example.test',
        ]);
    }

    private function payload(): array
    {
        return [
            'to' => 'nobody@example.test',
            'subject' => 'Hotfix probe',
            'body_html' => '<p>unused</p>',
            'body_text' => 'unused',
        ];
    }

    /** Credential MISSING: config resolves to null. */
    public function test_a_missing_credential_does_not_throw_during_construction(): void
    {
        config([
            'services.cakemail.username' => null,
            'services.cakemail.password' => null,
            'services.cakemail.base_url' => 'https://api.example.test',
        ]);

        $provider = new CakemailProvider();

        $this->assertInstanceOf(CakemailProvider::class, $provider);
    }

    public function test_a_missing_credential_fails_delivery_with_a_provider_specific_error(): void
    {
        Http::fake();

        config([
            'services.cakemail.username' => null,
            'services.cakemail.password' => null,
            'services.cakemail.base_url' => 'https://api.example.test',
        ]);

        $provider = new CakemailProvider();

        try {
            $provider->send($this->channel(), $this->payload());
            $this->fail('Delivery should not be attempted without credentials.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cakemail credentials are not configured', $e->getMessage());
            $this->assertStringContainsString('CAKEMAIL_PASSWORD', $e->getMessage());
        }

        // The provider must give up before it reaches the network.
        Http::assertNothingSent();
    }

    /** Credential EMPTY: present in the environment but blank. */
    public function test_an_empty_credential_is_treated_as_absent_rather_than_valid(): void
    {
        Http::fake();

        config([
            'services.cakemail.username' => 'contact@example.test',
            'services.cakemail.password' => '   ',
            'services.cakemail.base_url' => 'https://api.example.test',
        ]);

        $provider = new CakemailProvider();

        try {
            $provider->send($this->channel(), $this->payload());
            $this->fail('A blank credential must not be treated as usable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cakemail credentials are not configured', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_empty_username_is_also_rejected(): void
    {
        Http::fake();

        config([
            'services.cakemail.username' => '',
            'services.cakemail.password' => 'placeholder-not-a-real-secret',
            'services.cakemail.base_url' => 'https://api.example.test',
        ]);

        try {
            (new CakemailProvider())->send($this->channel(), $this->payload());
            $this->fail('A blank username must not be treated as usable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cakemail credentials are not configured', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** Credential SET: current production state. Construction must stay unchanged. */
    public function test_a_configured_credential_constructs_cleanly_and_reports_no_configuration_issue(): void
    {
        config([
            'services.cakemail.username' => 'contact@example.test',
            'services.cakemail.password' => 'placeholder-not-a-real-secret',
            'services.cakemail.base_url' => 'https://api.example.test',
            'services.cakemail.sender_id' => '123',
            'services.cakemail.list_id' => '456',
        ]);

        $provider = new CakemailProvider();

        // configurationIssue() is protected; reflect rather than send, so no request is made.
        $issue = (new \ReflectionMethod($provider, 'configurationIssue'))->invoke($provider);

        $this->assertNull($issue, 'A fully configured provider must report no configuration issue.');
    }

    public function test_a_missing_base_url_still_reports_its_own_error_once_credentials_exist(): void
    {
        config([
            'services.cakemail.username' => 'contact@example.test',
            'services.cakemail.password' => 'placeholder-not-a-real-secret',
            'services.cakemail.base_url' => null,
        ]);

        $issue = (new \ReflectionMethod(CakemailProvider::class, 'configurationIssue'))
            ->invoke(new CakemailProvider());

        $this->assertNotNull($issue);
        $this->assertStringContainsString('CAKEMAIL_BASE_URL', $issue);
    }

    /**
     * Lazy resolution: a broken Cakemail binding must not prevent unrelated
     * messaging — this is the property that turns an email outage into an email
     * outage instead of a total messaging outage.
     */
    public function test_messaging_service_resolves_even_when_the_cakemail_provider_cannot_be_built(): void
    {
        $this->app->bind(CakemailProvider::class, function () {
            throw new RuntimeException('Cakemail deliberately unbuildable in this test.');
        });

        $service = $this->app->make(MessagingService::class);

        $this->assertInstanceOf(MessagingService::class, $service);
    }

    public function test_the_sms_provider_is_still_resolvable_while_cakemail_is_broken(): void
    {
        $this->app->bind(CakemailProvider::class, function () {
            throw new RuntimeException('Cakemail deliberately unbuildable in this test.');
        });

        $this->app->make(MessagingService::class);
        $sms = $this->app->make(TelnyxSmsProvider::class);

        $this->assertInstanceOf(TelnyxSmsProvider::class, $sms);
    }

    public function test_the_email_path_is_the_only_thing_that_fails_when_cakemail_is_broken(): void
    {
        $this->app->bind(CakemailProvider::class, function () {
            throw new RuntimeException('Cakemail deliberately unbuildable in this test.');
        });

        $service = $this->app->make(MessagingService::class);

        $this->expectException(RuntimeException::class);
        $service->getCakemailProvider();
    }
}
