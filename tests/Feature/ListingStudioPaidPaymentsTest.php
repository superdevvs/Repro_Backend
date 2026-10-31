<?php

namespace Tests\Feature;

use App\Models\ListingStudioCheckoutAttempt as Attempt;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioQuote;
use App\Models\ListingStudioRefundAttempt;
use App\Models\ListingStudioStripeCustomer;
use App\Models\User;
use App\Services\ListingStudio\StudioCheckoutService;
use App\Services\ListingStudio\StudioPaymentException;
use App\Services\ListingStudio\StudioRefundService;
use App\Services\ListingStudio\StudioStripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ListingStudioPaidPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private FakeStudioStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['listing-studio.paid_enabled' => true, 'listing-studio.paid_checkout_enabled' => true,
            'listing-studio.stripe_enabled' => true, 'listing-studio.stripe_account_id' => 'acct_studio',
            'listing-studio.stripe_mode' => 'test', 'listing-studio.webhook_secret' => 'whsec_fake',
            'listing-studio.plans.starter.test_price' => 'price_starter', 'app.url' => 'https://reprodashboard.com']);
        Http::preventStrayRequests();
        $this->client = User::factory()->create(['role' => 'client', 'secondary_roles' => [], 'account_status' => 'active']);
        $this->gateway = new FakeStudioStripeGateway;
        $this->gateway->transactionFloor = DB::transactionLevel();
        $this->app->instance(StudioStripeGateway::class, $this->gateway);
        $catalog = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioCatalogService');
        $catalog->shouldReceive('available')->andReturn(true)->byDefault();
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioCatalogService', $catalog);
    }

    public function test_disabled_checkout_has_no_network_or_durable_payment_attempt(): void
    {
        config()->set('listing-studio.paid_checkout_enabled', false);
        $this->error('checkout_unavailable', fn () => $this->checkout($this->order(), (string) Str::uuid()));
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
    }

    public function test_closed_service_gate_cannot_charge_a_previously_created_order(): void
    {
        $catalog = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioCatalogService');
        $catalog->shouldReceive('available')->with('ai_edit', 'grass')->once()->andReturn(false);
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioCatalogService', $catalog);
        $this->error('service_unavailable', fn () => $this->checkout($this->order(), (string) Str::uuid()));
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
    }

    public function test_active_primary_client_with_secondary_role_can_use_own_checkout_and_billing(): void
    {
        $this->client->update(['secondary_roles' => ['editor']]);
        $result = $this->checkout($this->order(), (string) Str::uuid());
        $this->assertSame('redirect', $result['nextAction']['type']);
        config()->set('listing-studio.paid_checkout_enabled', false);
        $portal = app(StudioCheckoutService::class)->portal($this->client);
        $this->assertSame('https://billing.stripe.com/p/session/fake', $portal['url']);
        $this->assertSame($this->client->id, ListingStudioStripeCustomer::sole()->client_id);
    }

    public function test_billing_ambiguity_blocks_new_spending_but_allows_exact_known_session_get_and_portal(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        $this->makeBillingAmbiguous();
        $this->assertSame('open', $this->checkout($order, $key)['status']);
        $this->assertSame(1, $this->gateway->count('createCheckout'));
        $calls = count($this->gateway->calls);
        $this->error('billing_attention', fn () => $this->checkout($order, (string) Str::uuid()));
        $this->error('billing_attention', fn () => app(StudioCheckoutService::class)->subscription($this->client, 'pro', (string) Str::uuid()));
        $this->assertSame($calls, count($this->gateway->calls));
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 1);
        $portal = app(StudioCheckoutService::class)->portal($this->client);
        $this->assertSame('redirect', $portal['nextAction']['type']);
    }

    public function test_billing_ambiguity_holds_unknown_accepted_checkout_without_another_create_post(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->gateway->loseCheckout = true;
        $this->error('awaiting_reconciliation', fn () => $this->checkout($order, $key));
        $this->makeBillingAmbiguous();
        $calls = count($this->gateway->calls);
        $this->error('awaiting_reconciliation', fn () => $this->checkout($order, $key));
        $this->assertSame($calls, count($this->gateway->calls));
        $this->assertSame(1, $this->gateway->count('createCheckout'));
        $this->assertNull(Attempt::sole()->session_id);
    }

    public function test_billing_ambiguity_after_allocation_blocks_first_submission_and_unsubmitted_replay(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->gateway->onCustomerCreated = fn () => $this->makeBillingAmbiguous();
        $this->error('billing_attention', fn () => $this->checkout($order, $key));
        $this->assertSame(0, $this->gateway->count('createCheckout'));
        $this->assertNull(Attempt::sole()->submission_started_at);
        $this->assertNull(Attempt::sole()->parameters);
        $calls = count($this->gateway->calls);
        $this->error('billing_attention', fn () => $this->checkout($order, $key));
        $this->assertSame($calls, count($this->gateway->calls));
    }

    public function test_checkout_replay_uses_one_session_and_does_not_create_shoot_payments(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $first = $this->checkout($order, $key);
        $second = $this->checkout($order, $key);
        $this->assertSame($first, $second);
        $this->assertSame('redirect', $first['nextAction']['type']);
        $this->assertSame(1, $this->gateway->count('createCheckout'));
        $this->assertSame(1, $this->gateway->count('createCustomer'));
        $this->assertSame('https://reprodashboard.com/listing-studio?tab=photos&order='.$order->id.'&checkout=returned', Attempt::sole()->parameters['success_url']);
        $this->error('checkout_in_progress', fn () => $this->checkout($order, (string) Str::uuid()));
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('shoots', 0);
        $this->assertStringNotContainsString('cus_', json_encode($first));
    }

    public function test_cancellation_between_order_read_and_attempt_allocation_prevents_any_checkout(): void
    {
        $order = $this->order();
        $catalog = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioCatalogService');
        $catalog->shouldReceive('available')->once()->andReturnUsing(function () use ($order) {
            $order->item()->update(['status' => 'cancelled']);

            return true;
        });
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioCatalogService', $catalog);
        $this->error('checkout_unavailable', fn () => $this->checkout($order, (string) Str::uuid()));
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_lost_response_retries_exact_stripe_body_and_key_without_transaction(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->gateway->loseCheckout = true;
        $this->error('awaiting_reconciliation', fn () => $this->checkout($order, $key));
        $stored = Attempt::sole();
        $this->assertSame('awaiting_reconciliation', $stored->status);
        $this->assertNotNull($stored->submission_started_at);
        $result = $this->checkout($order, $key);
        $creates = array_values(array_filter($this->gateway->calls, fn ($row) => $row[0] === 'createCheckout'));
        $this->assertSame($creates[0], $creates[1]);
        $this->assertSame('open', $result['status']);
        $this->assertSame(1, count($this->gateway->sessions));
    }

    public function test_aged_ambiguous_checkout_never_posts_again_and_inspection_keeps_database_unchanged(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->gateway->loseCheckout = true;
        $this->error('awaiting_reconciliation', fn () => $this->checkout($order, $key));
        Attempt::query()->update(['submission_started_at' => now()->subHours(24)]);
        $this->error('awaiting_reconciliation', fn () => $this->checkout($order, $key));
        $this->assertSame(1, $this->gateway->count('createCheckout'));
        $before = Attempt::sole()->getRawOriginal();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^(insert|update|delete|replace)/i', trim($query->sql))) {
                $writes[] = $query->sql;
            }
        });
        $this->artisan('listing-studio:reconcile-payments')->assertSuccessful();
        $this->assertSame($before, Attempt::sole()->getRawOriginal());
        $this->assertSame([], $writes);
        $this->assertSame(1, $this->gateway->count('createCheckout'));
    }

    public function test_expired_canonical_checkout_requires_explicit_new_key(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        $this->gateway->sessions['cs_fake_1']['status'] = 'expired';
        $this->gateway->sessions['cs_fake_1']['url'] = null;
        $error = $this->error('checkout_expired', fn () => $this->checkout($order, $key));
        $this->assertTrue($error->data['retryWithNewKey']);
        $this->checkout($order, (string) Str::uuid());
        $this->assertSame(2, $this->gateway->count('createCheckout'));
    }

    public function test_same_key_with_different_order_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->checkout($this->order(), $key);
        $this->error('idempotency_conflict', fn () => $this->checkout($this->order(), $key));
        $this->assertSame(1, $this->gateway->count('createCheckout'));
    }

    public function test_concurrent_client_checkout_cannot_reserve_or_call_provider(): void
    {
        $order = $this->order();
        $lock = \Illuminate\Support\Facades\Cache::store('database')->lock('studio-client-checkout:'.$this->client->id, 180);
        $this->assertTrue($lock->get());
        try {
            $this->error('checkout_in_progress', fn () => $this->checkout($order, (string) Str::uuid()));
        } finally {
            $lock->release();
        }
        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
    }

    public function test_canonical_customer_and_mode_mismatch_cannot_be_settled(): void
    {
        $this->checkout($this->order(), (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->sessions['cs_fake_1']['customer'] = 'cus_other';
        $this->webhook('checkout.session.completed', 'evt_customer_mismatch')->assertStatus(500);
        $this->gateway->sessions['cs_fake_1']['customer'] = 'cus_fake';
        $this->gateway->sessions['cs_fake_1']['payment_intent']['livemode'] = true;
        $this->webhook('checkout.session.completed', 'evt_mode_mismatch')->assertStatus(500);
        $this->assertNull(Attempt::sole()->payment_intent_id);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);
    }

    public function test_customer_recovery_does_not_adopt_an_email_match_without_random_binding(): void
    {
        $binding = ListingStudioStripeCustomer::create(['client_id' => $this->client->id, 'account_id' => 'acct_studio',
            'livemode' => false, 'create_parameters' => ['email' => $this->client->email], 'submission_started_at' => now()->subDays(2)]);
        $this->gateway->customerRows = [['id' => 'cus_unrelated', 'livemode' => false, 'metadata' => ['studio_client_id' => (string) $this->client->id]]];
        $this->assertFalse(app(StudioCheckoutService::class)->recoverCustomer($binding));
        $this->assertNull($binding->fresh()->customer_id);
        $this->gateway->customerRows[0]['metadata']['studio_customer_binding'] = $binding->id;
        $this->assertTrue(app(StudioCheckoutService::class)->recoverCustomer($binding));
        $this->assertSame('cus_unrelated', $binding->fresh()->customer_id);
        $this->assertSame(0, $this->gateway->count('createCustomer'));
    }

    public function test_unsupported_subscription_price_cannot_create_a_checkout(): void
    {
        $this->gateway->priceAmount = 9900;
        $this->error('checkout_unavailable', fn () => app(StudioCheckoutService::class)->subscription($this->client, 'starter', (string) Str::uuid()));
        $this->assertSame(0, $this->gateway->count('createCheckout'));
    }

    public function test_foreign_order_and_changed_account_cannot_reuse_checkout(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        config()->set('listing-studio.stripe_account_id', 'acct_other');
        $this->error('billing_attention', fn () => $this->checkout($order, $key));
        $this->assertSame(1, $this->gateway->count('createCheckout'));
        $order->update(['client_id' => User::factory()->create()->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->checkout($order, $key);
    }

    public function test_verified_async_payment_settles_once_and_duplicates_do_not_fetch_again(): void
    {
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldReceive('confirmPayment')->once()->with($order->id, Mockery::on(function ($evidence) {
            $this->assertSame($this->gateway->transactionFloor, DB::transactionLevel());
            $this->assertSame('pi_fake', $evidence['paymentIntentId']);
            $this->assertSame(700, $evidence['amountPaidCents']);
            $this->assertSame('acct_studio', $evidence['accountId']);
            $this->assertFalse($evidence['liveMode']);

            return true;
        }))->andReturn($order);
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        $this->webhook('checkout.session.async_payment_succeeded', 'evt_paid')->assertOk()->assertJsonPath('handled', true);
        $calls = count($this->gateway->calls);
        $this->webhook('checkout.session.async_payment_succeeded', 'evt_paid')->assertOk();
        $this->assertSame($calls, count($this->gateway->calls));
        $this->assertSame('paid', Attempt::sole()->status);
    }

    public function test_wrong_payment_amount_is_held_without_settlement(): void
    {
        $this->checkout($this->order(), (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->sessions['cs_fake_1']['payment_intent']['amount_received'] = 1;
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldNotReceive('confirmPayment');
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        $this->webhook('checkout.session.completed', 'evt_bad')->assertStatus(500);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);
        $this->assertSame('open', Attempt::sole()->status);
    }

    public function test_bad_webhook_signature_and_wrong_mode_never_fetch_or_settle(): void
    {
        $this->checkout($this->order(), (string) Str::uuid());
        $count = count($this->gateway->calls);
        $this->postJson('/api/webhooks/stripe/listing-studio', ['id' => 'evt_forged'])->assertStatus(400);
        $this->webhook('checkout.session.completed', 'evt_other_mode', true)->assertOk()->assertJsonPath('handled', false);
        $this->assertSame($count, count($this->gateway->calls));
    }

    public function test_unpaid_completed_checkout_waits_without_settlement(): void
    {
        $this->checkout($this->order(), (string) Str::uuid());
        $this->gateway->sessions['cs_fake_1']['status'] = 'complete';
        $this->gateway->sessions['cs_fake_1']['url'] = null;
        $this->webhook('checkout.session.completed', 'evt_unpaid')->assertOk();
        $this->assertSame('awaiting_payment', Attempt::sole()->status);
    }

    public function test_verified_definitive_async_failure_allows_explicit_replacement_checkout(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        $this->gateway->paid('cs_fake_1');
        $this->gateway->sessions['cs_fake_1']['payment_status'] = 'unpaid';
        $this->gateway->sessions['cs_fake_1']['payment_intent']['status'] = 'requires_payment_method';
        $this->gateway->sessions['cs_fake_1']['payment_intent']['amount_received'] = 0;
        $this->webhook('checkout.session.async_payment_failed', 'evt_async_failed')->assertOk();
        $error = $this->error('checkout_failed', fn () => $this->checkout($order, $key));
        $this->assertTrue($error->data['retryWithNewKey']);
        $this->checkout($order, (string) Str::uuid());
        $this->assertSame(2, $this->gateway->count('createCheckout'));
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
    }

    public function test_subscription_duplicate_remote_account_blocks_new_checkout(): void
    {
        $this->gateway->activeSubscription = true;
        $this->error('subscription_exists', fn () => app(StudioCheckoutService::class)->subscription($this->client, 'starter', (string) Str::uuid()));
        $this->assertSame(0, $this->gateway->count('createCheckout'));
        $this->assertDatabaseCount('listing_studio_stripe_customers', 1);
    }

    public function test_authenticated_subscription_and_portal_use_bound_customer_not_entered_email(): void
    {
        $result = app(StudioCheckoutService::class)->subscription($this->client, 'starter', (string) Str::uuid());
        $attempt = Attempt::sole();
        $this->assertSame('price_starter', $attempt->parameters['line_items'][0]['price']);
        $this->assertSame('https://reprodashboard.com/listing-studio?tab=billing&checkout=returned', $attempt->parameters['success_url']);
        $this->client->update(['email' => 'changed@example.test']);
        config()->set('listing-studio.paid_checkout_enabled', false);
        $portal = app(StudioCheckoutService::class)->portal($this->client->fresh());
        $this->assertSame('https://billing.stripe.com/p/session/fake', $portal['url']);
        $this->assertSame('cus_fake', ListingStudioStripeCustomer::sole()->customer_id);
        $this->assertSame(1, $this->gateway->count('createCustomer'));
    }

    public function test_paid_subscription_requires_exact_expanded_customer_before_existing_sync(): void
    {
        $key = (string) Str::uuid();
        app(StudioCheckoutService::class)->subscription($this->client, 'starter', $key);
        $attempt = Attempt::sole();
        $this->gateway->sessions['cs_fake_1']['status'] = 'complete';
        $this->gateway->sessions['cs_fake_1']['payment_status'] = 'paid';
        $this->gateway->sessions['cs_fake_1']['url'] = null;
        $this->gateway->sessions['cs_fake_1']['subscription'] = ['id' => 'sub_fake', 'livemode' => false, 'customer' => 'cus_other',
            'metadata' => ['studio_checkout_attempt' => $attempt->id, 'studio_kind' => 'subscription']];
        $sync = Mockery::mock(\App\Services\ListingStudio\StripeSubscriptionSync::class);
        $sync->shouldNotReceive('handle');
        $this->app->instance(\App\Services\ListingStudio\StripeSubscriptionSync::class, $sync);
        $this->webhook('checkout.session.completed', 'evt_bad_subscription')->assertStatus(500);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);
        $this->gateway->sessions['cs_fake_1']['subscription']['customer'] = 'cus_fake';
        $result = app(StudioCheckoutService::class)->subscription($this->client, 'starter', $key);
        $this->assertSame('awaiting_webhook', $result['status']);
        $this->assertSame(0, \App\Models\ListingStudioSubscription::count());
    }

    public function test_refund_timeout_reuses_exact_operation_then_pending_does_not_report_success(): void
    {
        $order = $this->paidOrder();
        $event = ListingStudioOutbox::create(['type' => 'refund_order', 'aggregate_id' => $order->id, 'idempotency_key' => Str::uuid(),
            'payload' => ['orderId' => $order->id], 'status' => 'pending', 'available_at' => now()]);
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldReceive('confirmRefund')->once()->with($order->id, Mockery::on(fn ($e) => $e['status'] === 'pending' && $e['amountCents'] === 700))->andReturn($order);
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        $this->gateway->loseRefund = true;
        $this->error('awaiting_reconciliation', fn () => app(StudioRefundService::class)->dispatch($event));
        app(StudioRefundService::class)->dispatch($event);
        $creates = array_values(array_filter($this->gateway->calls, fn ($r) => $r[0] === 'createRefund'));
        $this->assertSame($creates[0], $creates[1]);
        $this->assertSame('pending', ListingStudioRefundAttempt::sole()->status);
    }

    public function test_external_refund_is_bound_to_paid_intent_and_uses_canonical_status(): void
    {
        $order = $this->paidOrder();
        $payment = Attempt::sole();
        $this->gateway->refundRows['re_external'] = $this->gateway->refundFixture('re_external', [], 350, 'failed');
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldReceive('confirmRefund')->once()->with($order->id, Mockery::on(fn ($e) => $e['status'] === 'failed' && $e['amountCents'] === 350))->andReturn($order);
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        app(StudioRefundService::class)->reconcileExternal($payment, 're_external', 'evt_refund');
        $this->assertSame('failed', ListingStudioRefundAttempt::sole()->status);
        $this->assertSame(0, $this->gateway->count('createRefund'));
    }

    public function test_refund_older_than_stripe_idempotency_retention_never_creates_a_replacement(): void
    {
        $order = $this->paidOrder();
        $event = ListingStudioOutbox::create(['type' => 'refund_order', 'aggregate_id' => $order->id, 'idempotency_key' => Str::uuid(),
            'payload' => ['orderId' => $order->id], 'status' => 'pending', 'available_at' => now()]);
        $this->gateway->loseRefund = true;
        $this->error('awaiting_reconciliation', fn () => app(StudioRefundService::class)->dispatch($event));
        ListingStudioRefundAttempt::query()->update(['submission_started_at' => now()->subDays(2)]);
        $this->error('awaiting_reconciliation', fn () => app(StudioRefundService::class)->dispatch($event));
        $this->assertSame(1, $this->gateway->count('createRefund'));
    }

    public function test_browser_paid_session_waits_for_verified_event_without_settlement(): void
    {
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        $this->gateway->paid('cs_fake_1');
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldNotReceive('confirmPayment');
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        $result = $this->checkout($order, $key);
        $this->assertSame('awaiting_webhook', $result['status']);
        $this->assertSame('await_payment', $result['nextAction']['type']);
        $this->assertNull($result['url']);
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);
        $before = Attempt::sole()->getRawOriginal();
        $this->artisan('listing-studio:reconcile-payments')->assertSuccessful();
        $this->assertSame($before, Attempt::sole()->getRawOriginal());
    }

    public function test_explicit_recovered_event_uses_same_deduped_settlement_path(): void
    {
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->eventRows['evt_recovered'] = ['id' => 'evt_recovered', 'type' => 'checkout.session.completed', 'livemode' => false,
            'data' => ['object' => $this->gateway->sessions['cs_fake_1']]];
        $core = Mockery::mock('App\\Services\\ListingStudio\\ListingStudioOrderService');
        $core->shouldReceive('confirmPayment')->once()->with($order->id, Mockery::on(fn ($e) => $e['eventId'] === 'evt_recovered'))->andReturn($order);
        $this->app->instance('App\\Services\\ListingStudio\\ListingStudioOrderService', $core);
        $this->artisan('listing-studio:reconcile-payments', ['--event' => 'evt_recovered'])->assertSuccessful();
        $this->artisan('listing-studio:reconcile-payments', ['--event' => 'evt_recovered'])->assertSuccessful();
        $this->assertSame('paid', Attempt::sole()->status);
        $this->assertDatabaseCount('listing_studio_payment_events', 1);
    }

    public function test_signed_events_settle_real_core_once_after_browser_paid_lookup_stays_unfunded(): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $key = (string) Str::uuid();
        $this->checkout($order, $key);
        $this->gateway->paid('cs_fake_1');

        $lookup = $this->checkout($order, $key);
        $this->assertSame('awaiting_webhook', $lookup['status']);
        $this->assertSame('pending', $order->fresh()->funding_status);
        $this->assertSame('awaiting_funding', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 0);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);

        $this->webhook('checkout.session.async_payment_succeeded', 'evt_core_paid')->assertOk();
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame(700, $order->fresh()->paid_amount_cents);
        $this->assertSame('queued', $order->item()->first()->status);
        $firstAttempt = $order->item()->first()->processing_attempt_id;
        $this->assertNotNull($firstAttempt);
        $calls = count($this->gateway->calls);
        $this->webhook('checkout.session.async_payment_succeeded', 'evt_core_paid')->assertOk();
        $this->assertSame($calls, count($this->gateway->calls));
        $this->webhook('checkout.session.completed', 'evt_core_same_economic_payment')->assertOk();
        $this->assertSame($firstAttempt, $order->item()->first()->processing_attempt_id);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 1);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->assertDatabaseCount('listing_studio_payment_events', 2);
        $intent = ListingStudioOutbox::sole();
        $this->assertSame('process_item', $intent->type);
        $this->assertSame('pending', $intent->status);
        $this->assertSame($order->id, $intent->payload['orderId']);
        $this->assertSame($firstAttempt, $intent->payload['attemptId']);
        $this->assertSame(0, $this->gateway->count('createRefund'));
        Http::assertNothingSent();
    }

    public function test_signed_partial_refund_events_use_real_core_and_never_rewind_fulfillment_or_double_count(): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->webhook('checkout.session.completed', 'evt_core_refund_payment')->assertOk();
        $order->item()->update(['status' => 'processing', 'started_at' => now()]);
        $itemBefore = $order->item()->first()->getRawOriginal();
        $this->gateway->refundRows['re_partial'] = $this->gateway->refundFixture('re_partial', [], 200, 'pending');
        $eventObject = ['id' => 're_partial', 'payment_intent' => 'pi_fake', 'status' => 'succeeded'];
        // The signed delivery's status is still not authoritative: fetch the bound canonical refund.
        $this->webhook('refund.created', 'evt_core_refund_pending', false, $eventObject)->assertOk();
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
        $this->assertSame('refund_pending', $order->fresh()->funding_status);
        $this->gateway->refundRows['re_partial']['status'] = 'failed';
        $this->webhook('refund.failed', 'evt_core_refund_failed', false, $eventObject)->assertOk();
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->gateway->refundRows['re_partial']['status'] = 'succeeded';
        $this->webhook('refund.updated', 'evt_core_refund_succeeded', false, $eventObject)->assertOk();
        $this->assertSame(200, $order->fresh()->refunded_amount_cents);
        $this->assertSame('partially_refunded', $order->fresh()->funding_status);

        $this->gateway->refundRows['re_second'] = $this->gateway->refundFixture('re_second', [], 100, 'succeeded');
        $secondObject = ['id' => 're_second', 'payment_intent' => 'pi_fake', 'status' => 'pending'];
        $this->webhook('refund.updated', 'evt_core_refund_second', false, $secondObject)->assertOk();
        $calls = count($this->gateway->calls);
        $this->webhook('refund.updated', 'evt_core_refund_second', false, $secondObject)->assertOk();
        $this->assertSame($calls, count($this->gateway->calls));
        $this->gateway->refundRows['re_partial']['status'] = 'pending';
        $this->webhook('refund.created', 'evt_core_refund_reordered', false, $eventObject)->assertOk();
        $this->assertSame(300, $order->fresh()->refunded_amount_cents);
        $this->assertSame('partially_refunded', $order->fresh()->funding_status);
        $this->assertSame($itemBefore, $order->item()->first()->getRawOriginal());
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 3);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->assertSame(0, $this->gateway->count('createRefund'));
        Http::assertNothingSent();
    }

    private function useRealFinancialCore(): void
    {
        // Stub only file/provider boundary interfaces; settlement, ledger and outbox are real.
        $this->app->instance(\App\Contracts\ListingStudio\ListingStudioSourceResolver::class,
            Mockery::mock(\App\Contracts\ListingStudio\ListingStudioSourceResolver::class));
        $this->app->instance(\App\Contracts\ListingStudio\ListingStudioOutcomeGuard::class,
            Mockery::mock(\App\Contracts\ListingStudio\ListingStudioOutcomeGuard::class));
    }

    public function test_refund_before_payment_is_recovered_canonically_without_emitting_processing(): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->refundRows['re_early'] = $this->gateway->refundFixture('re_early', [], 700, 'pending');
        // The old subscription router receives an unknown PI; it must not be used to infer a purchase.
        $legacy = Mockery::mock(\App\Services\ListingStudio\StripeSubscriptionRefundSync::class);
        $legacy->shouldReceive('handle')->once()->andReturn(null);
        $this->app->instance(\App\Services\ListingStudio\StripeSubscriptionRefundSync::class, $legacy);
        $object = ['id' => 're_early', 'payment_intent' => 'pi_fake'];
        $this->webhook('refund.created', 'evt_refund_before_payment', false, $object)->assertOk();
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
        $this->assertNull(Attempt::sole()->payment_intent_id);
        $this->assertDatabaseCount('listing_studio_outbox', 0);

        $this->webhook('checkout.session.completed', 'evt_payment_after_refund')->assertOk();
        $this->assertSame(700, $order->fresh()->paid_amount_cents);
        $this->assertSame('refund_pending', $order->fresh()->funding_status);
        $this->assertSame('needs_attention', $order->item()->first()->status);
        $this->assertNull($order->item()->first()->processing_attempt_id);
        $this->assertTrue(Str::isUuid(ListingStudioRefundAttempt::sole()->operation_id));
        $this->assertDatabaseCount('listing_studio_outbox', 0);

        $this->gateway->refundRows['re_early']['status'] = 'succeeded';
        $this->webhook('refund.updated', 'evt_early_refund_succeeded', false, $object)->assertOk();
        $this->assertSame(700, $order->fresh()->refunded_amount_cents);
        $this->assertSame('refunded', $order->fresh()->funding_status);
        $this->assertSame('needs_attention', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->assertSame(0, $this->gateway->count('createRefund'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonReturningRefundStates')]
    public function test_failed_or_canceled_prefulfillment_refund_restores_single_processing_intent(string $state): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->refundRows['re_terminal'] = $this->gateway->refundFixture('re_terminal', [], 700, 'pending');
        $this->webhook('checkout.session.completed', 'evt_paid_pending_refund')->assertOk();
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->gateway->refundRows['re_terminal']['status'] = $state;
        $this->webhook('refund.updated', 'evt_terminal_refund', false, ['id' => 're_terminal', 'payment_intent' => 'pi_fake'])->assertOk();
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
        $this->assertSame('queued', $order->item()->first()->status);
        $this->gateway->refundRows['re_terminal']['status'] = 'pending';
        $this->webhook('refund.updated', 'evt_terminal_refund_stale_pending', false, ['id' => 're_terminal', 'payment_intent' => 'pi_fake'])->assertOk();
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame($state, ListingStudioRefundAttempt::sole()->status);
        $this->webhook('checkout.session.completed', 'evt_paid_after_terminal_refund')->assertOk();
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame('queued', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
    }

    public static function nonReturningRefundStates(): array
    {
        return [['failed'], ['canceled']];
    }

    public function test_all_canonical_refund_pages_are_checked_before_atomic_funding(): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $this->gateway->refundPages = [
            'first' => ['has_more' => true, 'data' => [$this->gateway->refundFixture('re_page_one', [], 50, 'failed')]],
            're_page_one' => ['has_more' => false, 'data' => [$this->gateway->refundFixture('re_page_two', [], 100, 'succeeded')]],
        ];
        $this->webhook('checkout.session.completed', 'evt_refund_all_pages')->assertOk();
        $this->assertSame(2, $this->gateway->count('refunds'));
        $this->assertSame(100, $order->fresh()->refunded_amount_cents);
        $this->assertSame('partially_refunded', $order->fresh()->funding_status);
        $this->assertSame('needs_attention', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 3);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRefundSnapshots')]
    public function test_incomplete_or_unbound_refund_snapshot_cannot_fund_or_enqueue(string $failure): void
    {
        $this->useRealFinancialCore();
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        $refund = $this->gateway->refundFixture('re_invalid', [], 100, 'succeeded');
        if ($failure === 'incomplete') {
            $this->gateway->refundPages = ['first' => ['has_more' => true, 'data' => []]];
        } elseif ($failure === 'excess') {
            $rows = [];
            for ($i = 0; $i < 101; $i++) {
                $rows[] = $this->gateway->refundFixture('re_excess_'.$i, [], 1, 'failed');
            }
            $this->gateway->refundPages = ['first' => ['has_more' => false, 'data' => $rows]];
        } else {
            match ($failure) {
                'customer' => $refund['payment_intent']['customer'] = 'cus_other',
                'mode' => $refund['charge']['livemode'] = true,
                'payment' => $refund['payment_intent']['id'] = 'pi_other',
                'amount' => $refund['amount'] = 701,
                'currency' => $refund['currency'] = 'eur',
            };
            $this->gateway->refundRows['re_invalid'] = $refund;
        }
        $this->webhook('checkout.session.completed', 'evt_refund_snapshot_invalid')->assertStatus(500);
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
        $this->assertSame('awaiting_funding', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 0);
        $this->assertDatabaseCount('listing_studio_payment_events', 0);
    }

    public static function invalidRefundSnapshots(): array
    {
        return [['incomplete'], ['excess'], ['customer'], ['mode'], ['payment'], ['amount'], ['currency']];
    }

    public function test_late_paid_old_attempt_after_replacement_and_cancellation_refunds_without_reviving_processing(): void
    {
        $this->useRealFinancialCore();
        $guard = Mockery::mock(\App\Contracts\ListingStudio\ListingStudioOutcomeGuard::class);
        $guard->shouldReceive('assertDefinitiveStop')->once();
        $this->app->instance(\App\Contracts\ListingStudio\ListingStudioOutcomeGuard::class, $guard);
        $order = $this->order();
        $oldKey = (string) Str::uuid();
        $this->checkout($order, $oldKey);
        $this->gateway->paid('cs_fake_1');
        $this->gateway->sessions['cs_fake_1']['payment_status'] = 'unpaid';
        $this->gateway->sessions['cs_fake_1']['payment_intent']['status'] = 'requires_payment_method';
        $this->gateway->sessions['cs_fake_1']['payment_intent']['amount_received'] = 0;
        $this->webhook('checkout.session.async_payment_failed', 'evt_old_attempt_failed')->assertOk();

        $replacementKey = (string) Str::uuid();
        $this->checkout($order, $replacementKey);
        $core = app(\App\Services\ListingStudio\ListingStudioOrderService::class);
        try {
            $core->cancelUnpaidOrder($this->client, $order->id);
            $this->fail('An open replacement checkout must block cancellation.');
        } catch (\App\Exceptions\PublicApiException $exception) {
            $this->assertSame('order_cancellation_unavailable', $exception->errorCode);
        }
        $this->gateway->sessions['cs_fake_2']['status'] = 'expired';
        $this->gateway->sessions['cs_fake_2']['url'] = null;
        $this->error('checkout_expired', fn () => $this->checkout($order, $replacementKey));
        $core->cancelUnpaidOrder($this->client, $order->id);
        $this->assertSame('cancelled', $order->item()->first()->status);
        $this->error('checkout_unavailable', fn () => $this->checkout($order, (string) Str::uuid()));

        $this->gateway->paid('cs_fake_1');
        $this->webhook('checkout.session.completed', 'evt_old_attempt_late_paid')->assertOk();
        $this->webhook('checkout.session.async_payment_succeeded', 'evt_old_attempt_late_paid_duplicate')->assertOk();
        $this->assertSame(700, $order->fresh()->paid_amount_cents);
        $this->assertSame('refund_pending', $order->fresh()->funding_status);
        $this->assertSame('cancelled', $order->item()->first()->status);
        $this->assertNull($order->item()->first()->processing_attempt_id);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 1);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->assertSame('refund_order', ListingStudioOutbox::sole()->type);
        $this->assertSame(2, $this->gateway->count('createCheckout'));
        $this->assertSame(0, $this->gateway->count('createRefund'));
    }

    private function paidOrder(): ListingStudioOrder
    {
        $order = $this->order();
        $this->checkout($order, (string) Str::uuid());
        $this->gateway->paid('cs_fake_1');
        Attempt::sole()->update(['status' => 'paid', 'payment_intent_id' => 'pi_fake']);
        $order->update(['paid_amount_cents' => 700, 'funding_status' => 'refund_pending']);

        return $order;
    }

    private function makeBillingAmbiguous(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $subscription = \App\Models\ListingStudioSubscription::create(['stripe_account_id' => 'acct_studio', 'livemode' => false,
                'stripe_subscription_id' => 'sub_ambiguous_'.$i, 'stripe_customer_id' => 'cus_fake', 'client_id' => $this->client->id,
                'plan_code' => 'starter', 'plan_name' => 'Starter', 'status' => 'active', 'sync_status' => 'synced', 'currency' => 'usd',
                'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(20)]);
            DB::table('listing_studio_credit_entries')->insert(['subscription_id' => $subscription->id, 'stripe_invoice_id' => 'in_ambiguous_'.$i,
                'source_type' => 'invoice', 'source_key' => 'in_ambiguous_'.$i, 'plan_code' => 'starter', 'amount_cents' => 6000,
                'currency' => 'usd', 'period_start' => $subscription->current_period_start, 'expires_at' => $subscription->current_period_end,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function checkout(ListingStudioOrder $order, string $key): array
    {
        return app(StudioCheckoutService::class)->order($this->client, $order->id, $key);
    }

    private function order(): ListingStudioOrder
    {
        $quote = ListingStudioQuote::create(['client_id' => $this->client->id, 'kind' => 'ai_edit', 'service' => 'grass', 'source_kind' => 'upload',
            'source_id' => Str::uuid(), 'source_snapshot' => [], 'options' => [], 'pricing_snapshot' => [], 'catalog_version' => 'v1',
            'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(15)]);

        $order = ListingStudioOrder::create(['client_id' => $this->client->id, 'quote_id' => $quote->id, 'kind' => 'ai_edit', 'service' => 'grass',
            'funding_method' => 'card', 'funding_status' => 'pending', 'currency' => 'usd', 'total_cents' => 700,
            'pricing_snapshot' => [], 'idempotency_key' => Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        $order->item()->create(['client_id' => $this->client->id, 'kind' => 'ai_edit', 'service' => 'grass',
            'status' => 'awaiting_funding', 'source_kind' => 'upload', 'source_id' => $quote->source_id,
            'source_snapshot' => [], 'options' => []]);

        return $order;
    }

    private function error(string $code, callable $call): StudioPaymentException
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (StudioPaymentException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }
    }

    private function webhook(string $type, string $id, bool $live = false, ?array $object = null)
    {
        $event = ['id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => $live,
            'data' => ['object' => $object ?? $this->gateway->sessions['cs_fake_1']]];
        $payload = json_encode($event);
        $time = time();

        return $this->call('POST', '/api/webhooks/stripe/listing-studio', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_fake')], $payload);
    }
}

class FakeStudioStripeGateway extends StudioStripeGateway
{
    public int $transactionFloor = 0;

    public ?\Closure $onCustomerCreated = null;

    public array $calls = [];

    public array $sessions = [];

    public array $refundRows = [];

    public array $refundPages = [];

    public array $customerRows = [];

    public array $eventRows = [];

    public int $priceAmount = 4900;

    public bool $loseCheckout = false;

    public bool $loseRefund = false;

    public bool $activeSubscription = false;

    private array $sessionKeys = [];

    private array $refundKeys = [];

    private function call(string $method, mixed ...$args): void
    {
        if (DB::transactionLevel() !== $this->transactionFloor) {
            throw new \LogicException('Network called inside a write transaction.');
        }
        $this->calls[] = [$method, ...$args];
    }

    public function count(string $name): int
    {
        return count(array_filter($this->calls, fn ($r) => $r[0] === $name));
    }

    public function account(): array
    {
        $this->call(__FUNCTION__);

        return ['id' => 'acct_studio'];
    }

    public function event(string $id): array
    {
        $this->call(__FUNCTION__, $id);

        return $this->eventRows[$id];
    }

    public function price(string $id): array
    {
        $this->call(__FUNCTION__, $id);

        return ['id' => $id, 'livemode' => false, 'active' => true, 'unit_amount' => $this->priceAmount, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]];
    }

    public function customers(string $email): array
    {
        $this->call(__FUNCTION__, $email);

        return ['data' => $this->customerRows, 'has_more' => false];
    }

    public function customer(string $id): array
    {
        $this->call(__FUNCTION__, $id);

        return ['id' => $id, 'livemode' => false];
    }

    public function createCustomer(array $parameters, string $key): array
    {
        $this->call(__FUNCTION__, $parameters, $key);
        $this->onCustomerCreated?->__invoke();

        return ['id' => 'cus_fake', 'livemode' => false];
    }

    public function subscriptions(string $customer): array
    {
        $this->call(__FUNCTION__, $customer);

        return ['has_more' => false, 'data' => $this->activeSubscription ? [['id' => 'sub_active', 'status' => 'active']] : []];
    }

    public function portal(string $customer, string $returnUrl): array
    {
        $this->call(__FUNCTION__, $customer, $returnUrl);

        return ['customer' => $customer, 'livemode' => false, 'url' => 'https://billing.stripe.com/p/session/fake'];
    }

    public function checkouts(string $customer, int $since): array
    {
        $this->call(__FUNCTION__, $customer, $since);

        return ['data' => array_values($this->sessions), 'has_more' => false];
    }

    public function checkout(string $id): array
    {
        $this->call(__FUNCTION__, $id);

        return $this->sessions[$id];
    }

    public function refunds(string $intent, ?string $after = null): array
    {
        $this->call(__FUNCTION__, $intent, $after);

        if ($this->refundPages !== []) {
            return $this->refundPages[$after ?? 'first'];
        }

        return ['data' => array_values($this->refundRows), 'has_more' => false];
    }

    public function createCheckout(array $p, string $key): array
    {
        $this->call(__FUNCTION__, $p, $key);
        $id = $this->sessionKeys[$key] ??= 'cs_fake_'.(count($this->sessionKeys) + 1);
        $amount = $p['mode'] === 'payment' ? $p['line_items'][0]['price_data']['unit_amount'] : 4900;
        $this->sessions[$id] ??= ['id' => $id, 'mode' => $p['mode'], 'livemode' => false, 'customer' => $p['customer'],
            'metadata' => $p['metadata'], 'client_reference_id' => $p['client_reference_id'], 'status' => 'open', 'payment_status' => 'unpaid',
            'currency' => 'usd', 'amount_total' => $amount, 'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'expires_at' => $p['expires_at'],
            'line_items' => ['has_more' => false, 'data' => [['quantity' => 1, 'amount_total' => $amount, 'price' => ['id' => 'price_starter']]]]];
        if ($this->loseCheckout) {
            $this->loseCheckout = false;
            throw new \RuntimeException('Simulated lost response');
        }

        return $this->sessions[$id];
    }

    public function paid(string $id): void
    {
        $this->sessions[$id]['status'] = 'complete';
        $this->sessions[$id]['payment_status'] = 'paid';
        $this->sessions[$id]['url'] = null;
        $this->sessions[$id]['payment_intent'] = ['id' => 'pi_fake', 'livemode' => false, 'status' => 'succeeded', 'amount' => 700,
            'amount_received' => 700, 'currency' => 'usd', 'customer' => 'cus_fake', 'metadata' => $this->sessions[$id]['metadata']];
    }

    public function createRefund(array $p, string $key): array
    {
        $this->call(__FUNCTION__, $p, $key);
        $id = $this->refundKeys[$key] ??= 're_fake_'.(count($this->refundKeys) + 1);
        $this->refundRows[$id] ??= $this->refundFixture($id, $p['metadata'], $p['amount'], 'pending');
        if ($this->loseRefund) {
            $this->loseRefund = false;
            throw new \RuntimeException('Simulated lost response');
        }

        return $this->refundRows[$id];
    }

    public function refund(string $id): array
    {
        $this->call(__FUNCTION__, $id);

        return $this->refundRows[$id];
    }

    public function refundFixture(string $id, array $metadata, int $amount, string $status): array
    {
        return ['id' => $id, 'payment_intent' => ['id' => 'pi_fake', 'livemode' => false, 'customer' => 'cus_fake', 'status' => 'succeeded', 'amount_received' => 700, 'currency' => 'usd'],
            'charge' => ['id' => 'ch_fake', 'payment_intent' => 'pi_fake', 'livemode' => false, 'customer' => 'cus_fake'],
            'metadata' => $metadata, 'amount' => $amount, 'currency' => 'usd', 'status' => $status, 'created' => now()->timestamp];
    }
}
