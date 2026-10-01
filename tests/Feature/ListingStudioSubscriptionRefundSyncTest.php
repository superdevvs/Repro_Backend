<?php

namespace Tests\Feature;

use App\Models\ListingStudioSubscription;
use App\Models\Payment;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\StripeRefundGateway;
use App\Services\ListingStudio\StripeSubscriptionRefundSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingStudioSubscriptionRefundSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $refund;
    private array $payments;
    private array $subscription;
    private array $calls = [];
    private bool $providerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'listing-studio.stripe_enabled' => true, 'listing-studio.stripe_mode' => 'live',
            'listing-studio.stripe_account_id' => 'acct_listing_refund_test',
            'listing-studio.stripe_secret_key' => 'sk_live_local_refund_test',
            'services.stripe.webhook_secret' => 'whsec_refund_dashboard_test',
            'listing-studio.webhook_secret' => 'whsec_refund_studio_test',
            'services.stripe.secret_key' => null,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->refund = [
            'id' => 're_listing_test', 'payment_intent' => 'pi_listing_refund', 'metadata' => [],
            'amount' => 1200, 'currency' => 'usd', 'status' => 'succeeded', 'created' => 1790550000,
            'charge' => ['id' => 'ch_listing_refund', 'payment_intent' => 'pi_listing_refund',
                'customer' => 'cus_listing_refund', 'livemode' => true, 'currency' => 'usd'],
        ];
        $this->payments = ['has_more' => false, 'data' => [[
            'id' => 'inpay_listing_refund', 'livemode' => true, 'status' => 'paid',
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_listing_refund'],
            'invoice' => ['id' => 'in_listing_refund', 'livemode' => true, 'currency' => 'usd',
                'customer' => 'cus_listing_refund', 'parent' => ['subscription_details' => ['subscription' => 'sub_listing_refund']]],
        ]]];
        $this->subscription = [
            'id' => 'sub_listing_refund', 'livemode' => true, 'customer' => ['id' => 'cus_listing_refund'],
            'status' => 'active', 'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_1UKekLLsiebrQZS44LVexrW5']]]],
        ];
        $gateway = Mockery::mock(StripeRefundGateway::class);
        foreach (['refund', 'invoicePayments', 'subscription'] as $method) {
            $gateway->shouldReceive($method)->andReturnUsing(function (string $id) use ($method): array {
                $this->calls[] = [$method, $id];
                if ($this->providerFails) {
                    throw new \RuntimeException('Provider unavailable.');
                }
                return match ($method) {
                    'refund' => $this->refund, 'invoicePayments' => $this->payments, default => $this->subscription,
                };
            });
        }
        $this->app->instance(StripeRefundGateway::class, $gateway);
    }

    public function test_owned_refund_is_recorded_without_creating_client_subscription_or_shoot_accounting(): void
    {
        $result = $this->sync();
        $this->assertSame('listing_studio_refund_synced', $result['outcome']);
        $this->assertDatabaseHas('listing_studio_subscription_refunds', [
            'stripe_refund_id' => 're_listing_test', 'stripe_subscription_id' => 'sub_listing_refund',
            'stripe_invoice_id' => 'in_listing_refund', 'amount_cents' => 1200, 'currency' => 'usd', 'status' => 'succeeded',
        ]);
        $this->assertCount(3, $this->calls);
        foreach (['users', 'listing_studio_subscriptions', 'listing_studio_account_setups', 'payments', 'payment_refunds'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_common_and_dedicated_signed_ingresses_acknowledge_owned_refunds(): void
    {
        $this->postEvent('/api/webhooks/stripe', 'whsec_refund_dashboard_test')->assertOk()->assertJsonPath('outcome', 'listing_studio_refund_synced');
        $this->postEvent('/api/webhooks/stripe/listing-studio', 'whsec_refund_studio_test')->assertOk()->assertJsonPath('outcome', 'listing_studio_refund_synced');
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_duplicate_partial_refunds_and_delayed_events_use_canonical_status_without_inflating_amounts(): void
    {
        $this->refund['status'] = 'pending';
        $this->sync();
        $this->refund['status'] = 'failed';
        $this->sync(['type' => 'refund.failed', 'id' => 'evt_failed']);
        $this->sync(['type' => 'refund.created', 'id' => 'evt_delayed_pending']);
        $this->assertDatabaseHas('listing_studio_subscription_refunds', ['stripe_refund_id' => 're_listing_test', 'status' => 'failed', 'amount_cents' => 1200]);
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 1);
        $this->refund['id'] = 're_second_partial';
        $this->refund['amount'] = 800;
        $this->refund['status'] = 'succeeded';
        $this->sync(['id' => 'evt_second_partial', 'data' => ['object' => ['id' => 're_second_partial', 'payment_intent' => 'pi_listing_refund']]]);
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 2);
        $this->assertDatabaseHas('listing_studio_subscription_refunds', ['stripe_refund_id' => 're_second_partial', 'amount_cents' => 800, 'status' => 'succeeded']);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_shoot_owned_payment_intent_and_app_refund_metadata_skip_all_remote_reads(): void
    {
        $payment = Payment::factory()->create(['stripe_payment_id' => 'pi_listing_refund']);
        $before = $payment->fresh()->getRawOriginal();
        $this->assertNull($this->sync());
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        foreach (['app_instance', 'app_payment_id', 'app_refund_operation_key'] as $field) {
            $this->assertNull($this->sync(['data' => ['object' => ['id' => 're_listing_test', 'payment_intent' => 'pi_other', 'metadata' => [$field => 'existing_shoot_workflow']]]]));
        }
        $this->assertSame([], $this->calls);
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 0);
    }

    public function test_canonical_shoot_payment_reference_is_rechecked_when_event_omits_it(): void
    {
        Payment::factory()->create(['stripe_payment_id' => 'pi_listing_refund']);
        $this->assertNull($this->sync(['data' => ['object' => ['id' => 're_listing_test']]]));
        $this->assertCount(1, $this->calls);
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 0);
    }

    public function test_unsupported_prices_mixed_items_and_ambiguous_invoice_allocations_are_not_swallowed(): void
    {
        $original = $this->subscription;
        $this->subscription['items']['data'][0]['price']['id'] = 'price_other_application';
        $this->assertNull($this->sync());
        $this->subscription = $original;
        $this->subscription['items']['data'][] = $original['items']['data'][0];
        $this->assertNull($this->sync());
        $this->subscription = $original;
        $this->payments['has_more'] = true;
        $this->assertNull($this->sync());
        $this->payments['has_more'] = false;
        $this->payments['data'][] = $this->payments['data'][0];
        $this->assertNull($this->sync());
        $this->payments['data'] = [];
        $this->assertNull($this->sync());
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 0);
    }

    public function test_historical_refund_of_established_subscription_survives_later_price_change_without_mutating_subscription(): void
    {
        $row = ListingStudioSubscription::create([
            'stripe_account_id' => 'acct_listing_refund_test', 'livemode' => true,
            'stripe_subscription_id' => 'sub_listing_refund', 'stripe_customer_id' => 'cus_listing_refund',
            'status' => 'canceled', 'sync_status' => 'needs_attention',
        ]);
        $before = $row->fresh()->getRawOriginal();
        $credits = Mockery::mock();
        $credits->shouldReceive('reconcileInvoices')->once()->withArgs(function ($record, array $invoices) use ($row): bool {
            $this->assertSame($row->id, $record->id);
            $this->assertSame([$this->payments['data'][0]['invoice']], $invoices);
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertDatabaseHas('listing_studio_subscription_refunds', ['status' => 'succeeded', 'amount_cents' => 1200]);
            return true;
        });
        $this->app->instance(\App\Services\ListingStudio\ListingStudioCreditService::class, $credits);
        $this->subscription['items']['data'][0]['price']['id'] = 'price_custom_later';
        $this->assertTrue($this->sync()['handled']);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
    }

    public function test_wrong_event_mode_account_and_disabled_feature_have_no_effect(): void
    {
        $this->assertNull($this->sync(['livemode' => false]));
        $this->assertNull($this->sync(['account' => 'acct_foreign']));
        config()->set('listing-studio.stripe_enabled', false);
        $this->assertNull($this->sync());
        $this->assertSame([], $this->calls);
    }

    public function test_real_credit_ledger_tracks_succeeded_refund_and_restores_it_if_stripe_reports_failure(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20T00:00:00Z'));
        $start = CarbonImmutable::parse('2026-09-01T00:00:00Z');
        $end = CarbonImmutable::parse('2026-10-01T00:00:00Z');
        $row = ListingStudioSubscription::create([
            'stripe_account_id' => 'acct_listing_refund_test', 'livemode' => true,
            'stripe_subscription_id' => 'sub_listing_refund', 'stripe_customer_id' => 'cus_listing_refund',
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'status' => 'active', 'sync_status' => 'synced', 'plan_code' => 'starter',
            'current_period_start' => $start, 'current_period_end' => $end,
        ]);
        $invoice = $this->payments['data'][0]['invoice'] + [
            'status' => 'paid', 'amount_paid' => 4900, 'billing_reason' => 'subscription_create',
            'lines' => ['has_more' => false, 'data' => [[
                'id' => 'il_refund_grant', 'quantity' => 1, 'amount' => 4900,
                'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['subscription' => 'sub_listing_refund', 'proration' => false]],
                'pricing' => ['price_details' => ['price' => 'price_1UKekLLsiebrQZS44LVexrW5']],
                'period' => ['start' => $start->timestamp, 'end' => $end->timestamp],
            ]]],
        ];
        $this->payments['data'][0]['invoice'] = $invoice;
        $credits = app(ListingStudioCreditService::class);
        DB::transaction(fn () => $credits->reconcileInvoices($row, [$invoice]));
        $this->assertSame(6000, $credits->summary($row)['available_cents']);
        $this->sync();
        $this->sync();
        $this->assertSame(4531, $credits->summary($row)['available_cents']);
        $this->refund['status'] = 'failed';
        $this->sync(['type' => 'refund.failed', 'id' => 'evt_provider_refund_failed']);
        $this->assertSame(6000, $credits->summary($row)['available_cents']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('listing_studio_credit_entries', 2);
        $this->assertDatabaseCount('payments', 0);
        Queue::assertNothingPushed();
    }

    public static function ownershipMismatches(): array
    {
        return [
            ['refund', 'charge.livemode', false], ['refund', 'id', 're_another'],
            ['payments', 'data.0.payment.payment_intent', 'pi_another'],
            ['payments', 'data.0.invoice.customer', 'cus_another'],
            ['subscription', 'customer.id', 'cus_another'], ['subscription', 'livemode', false],
        ];
    }

    #[DataProvider('ownershipMismatches')]
    public function test_inconsistent_canonical_identity_is_retryable_without_mutation(string $fixture, string $field, mixed $value): void
    {
        data_set($this->{$fixture}, $field, $value);
        try {
            $this->sync();
            $this->fail('An inconsistent provider identity must fail.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('listing_studio_subscription_refunds', 0);
        }
    }

    public function test_api_failure_releases_lock_and_allows_retry_without_duplicate_rows(): void
    {
        $this->providerFails = true;
        try {
            $this->sync();
            $this->fail('An unavailable provider must retry.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('listing_studio_subscription_refunds', 0);
        }
        $this->providerFails = false;
        $this->assertTrue($this->sync()['handled']);
        $this->assertDatabaseCount('listing_studio_subscription_refunds', 1);
    }

    public function test_other_work_cannot_bypass_the_refund_lock(): void
    {
        $lock = Cache::lock('listing-studio-refund:'.hash('sha256', 'acct_listing_refund_test:1:re_listing_test'), 180);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(\RuntimeException::class);
            $this->sync();
        } finally {
            $lock->release();
            $this->assertSame([], $this->calls);
        }
    }

    public function test_gateway_uses_only_three_pinned_read_requests_with_exact_invoice_payment_filter(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/refunds/*' => Http::response($this->refund),
            'https://api.stripe.com/v1/invoice_payments*' => Http::response($this->payments),
            'https://api.stripe.com/v1/subscriptions/*' => Http::response($this->subscription),
        ]);
        $gateway = new StripeRefundGateway();
        $gateway->refund('re_listing_test');
        $gateway->invoicePayments('pi_listing_refund');
        $gateway->subscription('sub_listing_refund');
        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            return str_contains($request->url(), '/invoice_payments?') && $request->method() === 'GET'
                && $request->hasHeader('Stripe-Version', StripeRefundGateway::API_VERSION)
                && ($query['payment'] ?? null) === ['type' => 'payment_intent', 'payment_intent' => 'pi_listing_refund']
                && (int) ($query['limit'] ?? 0) === 2 && ($query['expand'] ?? null) === ['data.invoice'];
        });
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('Stripe-Version', StripeRefundGateway::API_VERSION));
        }
    }

    private function event(array $overrides = []): array
    {
        return array_replace([
            'id' => 'evt_listing_refund', 'object' => 'event', 'type' => 'refund.created', 'livemode' => true,
            'data' => ['object' => ['id' => 're_listing_test', 'charge' => 'ch_listing_refund', 'payment_intent' => 'pi_listing_refund']],
        ], $overrides);
    }

    private function sync(array $overrides = []): ?array
    {
        return app(StripeSubscriptionRefundSync::class)->handle($this->event($overrides));
    }

    private function postEvent(string $path, string $secret)
    {
        $payload = json_encode($this->event(), JSON_THROW_ON_ERROR);
        $timestamp = time();
        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }
}
