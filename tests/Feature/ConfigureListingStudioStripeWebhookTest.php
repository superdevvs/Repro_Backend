<?php

namespace Tests\Feature;

use App\Console\Commands\ConfigureListingStudioStripeWebhook;
use App\Services\ListingStudio\StripeSubscriptionSync;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\AccountService;
use Stripe\Service\PriceService;
use Stripe\Service\WebhookEndpointService;
use Stripe\StripeClient;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class ConfigureListingStudioStripeWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'listing-studio.stripe_secret_key' => 'sk_live_local_command_test',
            'listing-studio.stripe_account_id' => 'acct_listing_test',
            'listing-studio.stripe_mode' => 'live',
            'listing-studio.stripe_enabled' => true,
            'listing-studio.plans' => [
                'starter' => ['name' => 'Starter', 'price_cents' => 4900, 'live_price' => 'price_listing_starter', 'test_price' => 'price_test_starter'],
                'pro' => ['name' => 'Pro', 'price_cents' => 9900, 'live_price' => 'price_listing_pro', 'test_price' => 'price_test_pro'],
                'studio' => ['name' => 'Studio', 'price_cents' => 24900, 'live_price' => 'price_listing_studio', 'test_price' => 'price_test_studio'],
            ],
            'services.stripe.webhook_secret' => 'whsec_local_command_test',
        ]);
    }

    public function test_default_invocation_only_reads_even_when_events_are_missing(): void
    {
        [$client, $endpoints] = $this->stripe();
        $endpoints->shouldNotReceive('update');
        $endpoints->shouldNotReceive('create');
        Schema::shouldReceive('hasTable')->never();

        $command = $this->executeCommand($client);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('invoice.paid', $command->getDisplay());
        $this->assertStringContainsString('No Stripe settings were changed.', $command->getDisplay());
        $this->assertSame('whsec_local_command_test', config('services.stripe.webhook_secret'));
    }

    public function test_apply_preserves_existing_events_and_only_updates_enabled_events(): void
    {
        $existing = ['checkout.session.completed', 'refund.created', 'refund.updated', 'refund.failed', 'charge.dispute.created'];
        [$client, $endpoints] = $this->stripe(['enabled_events' => $existing]);
        Schema::shouldReceive('hasTable')->andReturn(true);
        $endpoints->shouldReceive('update')->once()->with('we_listing_test', Mockery::on(function (array $payload) use ($existing): bool {
            $this->assertSame(['enabled_events'], array_keys($payload));
            $this->assertSame([], array_diff($existing, $payload['enabled_events']));
            $this->assertSame([], array_diff(StripeSubscriptionSync::EVENTS, $payload['enabled_events']));
            $this->assertSame(array_values(array_unique($payload['enabled_events'])), $payload['enabled_events']);
            return true;
        }))->andReturn((object) ['id' => 'we_listing_test']);
        $endpoints->shouldNotReceive('create');

        $command = $this->executeCommand($client, true);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('signing secret were preserved', $command->getDisplay());
        $this->assertSame('whsec_local_command_test', config('services.stripe.webhook_secret'));
    }

    public function test_wildcard_endpoint_remains_unchanged(): void
    {
        [$client, $endpoints] = $this->stripe(['enabled_events' => ['*']]);
        Schema::shouldReceive('hasTable')->andReturn(true);
        $endpoints->shouldNotReceive('update');
        $endpoints->shouldNotReceive('create');

        $command = $this->executeCommand($client, true);

        $this->assertSame(0, $command->getStatusCode());
        $this->assertStringContainsString('Events to add: none', $command->getDisplay());
    }

    public function test_wrong_stripe_account_is_rejected_before_prices_or_endpoint_are_read(): void
    {
        [$client, $endpoints] = $this->stripe([], 'acct_someone_else', false);
        $endpoints->shouldNotReceive('retrieve');
        $endpoints->shouldNotReceive('update');

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('account does not match', $command->getDisplay());
    }

    public static function unsafeEndpoints(): array
    {
        return [
            'test endpoint in live mode' => [['livemode' => false]],
            'disabled endpoint' => [['status' => 'disabled']],
            'lookalike hostname' => [['url' => 'https://api.reprodashboard.com.evil.test/api/webhooks/stripe']],
            'unexpected path' => [['url' => 'https://api.reprodashboard.com/api/webhooks/other']],
            'unencrypted endpoint' => [['url' => 'http://api.reprodashboard.com/api/webhooks/stripe']],
        ];
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_wrong_endpoint_mode_or_url_cannot_be_mutated(array $overrides): void
    {
        [$client, $endpoints] = $this->stripe($overrides);
        $endpoints->shouldNotReceive('update');

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('enabled dashboard webhook in the configured Stripe mode', $command->getDisplay());
    }

    public static function invalidPriceProperties(): array
    {
        return [
            'test price in live mode' => [['livemode' => false]],
            'archived price' => [['active' => false]],
            'one time price' => [['recurring' => null]],
        ];
    }

    #[DataProvider('invalidPriceProperties')]
    public function test_inactive_wrong_mode_and_nonrecurring_prices_are_rejected(array $overrides): void
    {
        [$client, $endpoints] = $this->stripe([], 'acct_listing_test', true, $overrides);
        $endpoints->shouldNotReceive('retrieve');
        $endpoints->shouldNotReceive('update');

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('inactive, non-recurring, or in the wrong mode', $command->getDisplay());
    }

    public static function mismatchedMonthlyPricing(): array
    {
        return [
            'different amount' => [['unit_amount' => 5000]],
            'different currency' => [['currency' => 'cad']],
            'yearly billing' => [['recurring' => (object) ['interval' => 'year', 'interval_count' => 1]]],
            'two month billing' => [['recurring' => (object) ['interval' => 'month', 'interval_count' => 2]]],
        ];
    }

    #[DataProvider('mismatchedMonthlyPricing')]
    public function test_price_must_match_configured_monthly_usd_pricing(array $overrides): void
    {
        [$client, $endpoints] = $this->stripe([], 'acct_listing_test', true, $overrides);
        $endpoints->shouldNotReceive('retrieve');
        $endpoints->shouldNotReceive('update');

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('does not match the configured monthly USD pricing', $command->getDisplay());
    }

    public function test_apply_refuses_when_sync_is_disabled(): void
    {
        config()->set('listing-studio.stripe_enabled', false);
        [$client, $endpoints] = $this->stripe();
        $endpoints->shouldNotReceive('update');
        Schema::shouldReceive('hasTable')->never();

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('Deploy the migration and enable', $command->getDisplay());
    }

    public static function requiredTables(): array
    {
        return array_map(fn (string $table) => [$table], [
            'listing_studio_subscriptions', 'listing_studio_stripe_events', 'listing_studio_account_setups',
            'listing_studio_subscription_refunds', 'listing_studio_credit_entries',
        ]);
    }

    #[DataProvider('requiredTables')]
    public function test_apply_requires_every_subscription_sync_table(string $missingTable): void
    {
        [$client, $endpoints] = $this->stripe();
        $endpoints->shouldNotReceive('update');
        Schema::shouldReceive('hasTable')->andReturnUsing(fn (string $table): bool => $table !== $missingTable);

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('Deploy the migration and enable', $command->getDisplay());
    }

    public function test_missing_signing_secret_is_rejected_without_mutation(): void
    {
        config()->set('services.stripe.webhook_secret', null);
        [$client, $endpoints] = $this->stripe();
        $endpoints->shouldNotReceive('update');

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('signing secret is missing', $command->getDisplay());
    }

    public function test_provider_error_details_are_not_printed(): void
    {
        $accounts = Mockery::mock(AccountService::class);
        $accounts->shouldReceive('retrieve')->once()->withNoArgs()->andThrow(ApiConnectionException::factory(
            'Authorization Bearer sk_live_DO_NOT_PRINT and alice.private@example.test',
            500,
            '{"private":"provider response body"}',
        ));
        $client = Mockery::mock(StripeClient::class)->makePartial();
        $client->shouldReceive('getService')->with('accounts')->andReturn($accounts);

        $command = $this->executeCommand($client, true);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('Stripe verification failed.', $command->getDisplay());
        foreach (['DO_NOT_PRINT', 'alice.private', 'provider response body'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $command->getDisplay());
        }
    }

    /** @return array{StripeClient, WebhookEndpointService} */
    private function stripe(array $endpointOverrides = [], string $account = 'acct_listing_test', bool $includePrices = true, array $priceOverrides = []): array
    {
        $accounts = Mockery::mock(AccountService::class);
        $accounts->shouldReceive('retrieve')->once()->withNoArgs()->andReturn((object) ['id' => $account]);
        $prices = Mockery::mock(PriceService::class);
        if ($includePrices) {
            $prices->shouldReceive('retrieve')->andReturnUsing(function (string $id) use ($priceOverrides): object {
                $plan = collect(config('listing-studio.plans'))->first(
                    fn (array $plan): bool => in_array($id, [$plan['live_price'], $plan['test_price']], true),
                );
                return (object) array_replace([
                    'id' => $id, 'active' => true, 'livemode' => true, 'currency' => 'usd', 'unit_amount' => $plan['price_cents'],
                    'recurring' => (object) ['interval' => 'month', 'interval_count' => 1],
                ], $priceOverrides);
            });
        }
        $endpoints = Mockery::mock(WebhookEndpointService::class);
        $endpoints->shouldReceive('retrieve')->with('we_listing_test')->zeroOrMoreTimes()->andReturn((object) array_replace([
            'id' => 'we_listing_test', 'url' => 'https://api.reprodashboard.com/api/webhooks/stripe',
            'status' => 'enabled', 'livemode' => true, 'enabled_events' => ['checkout.session.completed', 'refund.created'],
        ], $endpointOverrides));
        $client = Mockery::mock(StripeClient::class)->makePartial();
        $client->shouldReceive('getService')->with('accounts')->andReturn($accounts);
        $client->shouldReceive('getService')->with('prices')->andReturn($prices);
        $client->shouldReceive('getService')->with('webhookEndpoints')->andReturn($endpoints);
        return [$client, $endpoints];
    }

    private function executeCommand(StripeClient $client, bool $apply = false): CommandTester
    {
        $command = new class($client) extends ConfigureListingStudioStripeWebhook
        {
            public function __construct(private readonly StripeClient $testClient)
            {
                parent::__construct();
            }

            protected function stripeClient(string $key): StripeClient
            {
                return $this->testClient;
            }
        };
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['endpoint' => 'we_listing_test', '--apply' => $apply]);
        return $tester;
    }
}
