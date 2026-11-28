<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\ListingStudio\StripeSubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Producer replay (P2-10): Stripe is held during the freeze and retries by
 * itself. Afterwards the lead lists undelivered events, and a payment event
 * delivered five times must still give one payment.
 */
class StripeReplayAfterFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.stripe.webhook_secret' => 'whsec_local_replay_test',
            'services.stripe.secret_key' => null,
            'services.stripe.app_instance' => 'reprodashboard-testing',
            'app.url' => 'https://api.reprodashboard.com',
            'app.frontend_url' => 'https://reprodashboard.com',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A Stripe redelivery: the same event body, signed again with a later timestamp. */
    private function deliver(string $path, string $secret, array $event, int $age = 0)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time() - $age;

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret),
        ], $payload);
    }

    private function checkoutCompleted(int $shootId): array
    {
        return ['id' => 'evt_freeze_checkout', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_freeze', 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'complete',
            'payment_status' => 'paid', 'payment_intent' => 'pi_test_freeze', 'amount_total' => 10000, 'currency' => 'usd',
            'success_url' => 'https://reprodashboard.com/payment/success', 'cancel_url' => 'https://reprodashboard.com/payment/cancel',
            'metadata' => ['type' => 'single', 'shoot_id' => (string) $shootId, 'environment' => 'testing'],
        ]]];
    }

    private function pushedJobs(): int
    {
        return collect(Queue::pushedJobs())->flatten(1)->count();
    }

    public function test_dashboard_payment_event_delivered_five_times_gives_one_payment(): void
    {
        $shoot = Shoot::factory()->create(['total_quote' => 100, 'payment_status' => 'unpaid']);
        $event = $this->checkoutCompleted($shoot->id);

        $this->deliver('/api/webhooks/stripe', 'whsec_local_replay_test', $event, 240)->assertOk()->assertJsonPath('outcome', 'processed');
        $after = ['payments' => DB::table('payments')->get()->toArray(), 'shoot' => $shoot->fresh()->only(['payment_status']),
            'invoices' => DB::table('invoices')->get()->toArray(), 'jobs' => $this->pushedJobs()];
        foreach ([180, 120, 60, 0] as $age) {
            $this->deliver('/api/webhooks/stripe', 'whsec_local_replay_test', $event, $age)->assertOk()
                ->assertJsonPath('outcome', 'already_processed');
        }

        $this->assertSame(1, DB::table('payments')->where('stripe_payment_id', 'pi_test_freeze')->count());
        $this->assertSame('paid', $shoot->fresh()->payment_status);
        $this->assertEquals($after, ['payments' => DB::table('payments')->get()->toArray(), 'shoot' => $shoot->fresh()->only(['payment_status']),
            'invoices' => DB::table('invoices')->get()->toArray(), 'jobs' => $this->pushedJobs()]);
    }

    public function test_listing_studio_event_delivered_five_times_gives_one_subscription_and_one_setup(): void
    {
        config()->set(['listing-studio.stripe_enabled' => true, 'listing-studio.stripe_mode' => 'live',
            'listing-studio.stripe_account_id' => 'acct_15OuBBLsiebrQZS4', 'listing-studio.webhook_secret' => 'whsec_studio_replay']);
        $subscription = ['id' => 'sub_freeze', 'object' => 'subscription', 'livemode' => true, 'status' => 'active',
            'customer' => ['id' => 'cus_freeze', 'email' => 'buyer@example.test', 'name' => 'Listing Buyer'],
            'items' => ['data' => [['quantity' => 1, 'current_period_start' => 1790550000, 'current_period_end' => 1793142000,
                'price' => ['id' => 'price_1UKekLLsiebrQZS44LVexrW5', 'unit_amount' => 4900, 'currency' => 'usd',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            'cancel_at_period_end' => false,
            'latest_invoice' => ['id' => 'in_freeze', 'status' => 'paid', 'amount_paid' => 4900, 'livemode' => true, 'currency' => 'usd',
                'billing_reason' => 'subscription_create', 'customer' => 'cus_freeze',
                'parent' => ['subscription_details' => ['subscription' => 'sub_freeze']],
                'lines' => ['has_more' => false, 'data' => [['id' => 'il_main', 'type' => 'subscription', 'quantity' => 1, 'amount' => 4900,
                    'subscription' => 'sub_freeze', 'price' => ['id' => 'price_1UKekLLsiebrQZS44LVexrW5'],
                    'period' => ['start' => 1790550000, 'end' => 1793142000], 'proration' => false]]],
                'status_transitions' => ['paid_at' => 1790550000]]];
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('subscription')->andReturn($subscription);
        $gateway->shouldReceive('invoice')->andReturn($subscription['latest_invoice']);
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $event = ['id' => 'evt_freeze_studio', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => true,
            'data' => ['object' => ['id' => 'cs_freeze', 'mode' => 'subscription', 'subscription' => 'sub_freeze']]];

        foreach ([240, 180, 120, 60, 0] as $age) {
            $this->deliver('/api/webhooks/stripe/listing-studio', 'whsec_studio_replay', $event, $age)->assertOk();
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('listing_studio_subscriptions', 1);
        $this->assertDatabaseCount('listing_studio_account_setups', 1);
        $this->assertDatabaseCount('listing_studio_stripe_events', 1);
        $this->assertSame(1, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
        $this->assertDatabaseCount('payments', 0);
        // A repeated event re-queues the still-pending setup on purpose (recovery). Every
        // push names the one setup, and the job sends nothing once that setup is sent or
        // processing (ListingStudioStripeSyncTest covers the duplicate job as a no-op).
        $setup = DB::table('listing_studio_account_setups')->sole();
        $this->assertSame([$setup->id], Queue::pushed(\App\Jobs\SendListingStudioAccountSetup::class)
            ->map(fn ($job) => $job->setupId)->unique()->values()->all());
    }

    public function test_both_stripe_endpoints_are_held_with_a_retryable_503(): void
    {
        config(['producer_replay.ingress_hold' => true]);
        $shoot = Shoot::factory()->create(['total_quote' => 100, 'payment_status' => 'unpaid']);

        $this->deliver('/api/webhooks/stripe', 'whsec_local_replay_test', $this->checkoutCompleted($shoot->id))
            ->assertStatus(503)->assertHeader('Retry-After');
        $this->deliver('/api/webhooks/stripe/listing-studio', 'whsec_studio_replay', ['id' => 'evt_x', 'type' => 'invoice.paid'])
            ->assertStatus(503)->assertHeader('Retry-After');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
    }

    public function test_undelivered_events_are_listed_read_only_with_a_restricted_key(): void
    {
        config(['producer_replay.stripe_events_read_keys.dashboard' => 'rk_test_synthetic_events_reader']);
        $client = new class implements ClientInterface
        {
            public array $requests = [];

            public array $pages = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = compact('method', 'absUrl', 'headers', 'params');

                return [json_encode(array_shift($this->pages)), 200, []];
            }
        };
        $client->pages = [['object' => 'list', 'url' => '/v1/events', 'has_more' => true, 'data' => [
            ['id' => 'evt_pending_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'created' => 1795000100, 'pending_webhooks' => 2],
            ['id' => 'evt_done_1', 'object' => 'event', 'type' => 'invoice.paid', 'created' => 1795000050, 'pending_webhooks' => 0],
        ]], ['object' => 'list', 'url' => '/v1/events', 'has_more' => false, 'data' => [
            ['id' => 'evt_pending_2', 'object' => 'event', 'type' => 'charge.refunded', 'created' => 1795000010, 'pending_webhooks' => 1],
        ]]];
        ApiRequestor::setHttpClient($client);

        $exit = Artisan::call('stripe:undelivered-events', ['--since' => '2026-11-18T10:00:00Z', '--until' => '2026-11-18T12:00:00Z', '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit, 'Pending deliveries must fail the +30 min and +2 h check.');
        $this->assertSame(['evt_pending_1', 'evt_pending_2'], array_column($report['pending'], 'id'));
        $this->assertSame(2, $report['pending'][0]['pending_webhooks']);
        $this->assertCount(2, $client->requests);
        foreach ($client->requests as $request) {
            $this->assertSame('get', strtolower($request['method']));
            $this->assertSame('https://api.stripe.com/v1/events', $request['absUrl']);
            $this->assertContains('Authorization: Bearer rk_test_synthetic_events_reader', $request['headers']);
        }
        $query = $client->requests[0]['params'];
        $this->assertEquals(['gte' => strtotime('2026-11-18T10:00:00Z'), 'lte' => strtotime('2026-11-18T12:00:00Z')], $query['created']);
        $this->assertSame('false', $query['delivery_success']);
        $this->assertSame('evt_done_1', $client->requests[1]['params']['starting_after']);
        $this->assertStringNotContainsString('rk_test_synthetic_events_reader', Artisan::output());
    }

    public function test_listing_succeeds_when_nothing_is_pending_and_refuses_without_a_read_key(): void
    {
        $this->assertSame(2, Artisan::call('stripe:undelivered-events', ['--since' => '2026-11-18T10:00:00Z']));

        config(['producer_replay.stripe_events_read_keys.dashboard' => 'rk_test_synthetic_events_reader']);
        $client = new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [json_encode(['object' => 'list', 'url' => '/v1/events', 'has_more' => false, 'data' => []]), 200, []];
            }
        };
        ApiRequestor::setHttpClient($client);

        $this->assertSame(0, Artisan::call('stripe:undelivered-events', ['--since' => '2026-11-18T10:00:00Z', '--until' => '2026-11-18T12:00:00Z']));
    }
}
