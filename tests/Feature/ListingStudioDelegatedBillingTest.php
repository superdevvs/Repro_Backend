<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Jobs\SendListingStudioBillingRequest;
use App\Models\ListingStudioBillingEmail;
use App\Models\ListingStudioBillingRequest;
use App\Models\ListingStudioCheckoutAttempt;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioSubscription;
use App\Models\Message;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioBillingEmailService;
use App\Services\ListingStudio\ListingStudioBillingRequestService;
use App\Services\ListingStudio\ListingStudioCatalogService;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\ListingStudioOrderService;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioQuoteService;
use App\Services\ListingStudio\StudioCheckoutService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ListingStudioDelegatedBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        config(['listing-studio.delegated_enabled' => true, 'listing-studio.paid_enabled' => true,
            'listing-studio.paid_checkout_enabled' => true, 'listing-studio.stripe_enabled' => true,
            'listing-studio.stripe_mode' => 'live', 'listing-studio.stripe_account_id' => 'acct_studio',
            'listing-studio.paid_services.grass' => true, 'app.frontend_url' => 'https://reprodashboard.com']);
        $disk = Storage::fake('listing_studio_orders', config('filesystems.disks.listing_studio_orders'));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->client = User::factory()->create(['role' => 'client', 'account_status' => 'active', 'email_verified_at' => now()]);
        $this->rep = User::factory()->create(['role' => 'salesRep', 'account_status' => 'active']);
        $this->app->instance(ListingStudioCatalogService::class, new class extends ListingStudioCatalogService
        {
            protected function aiReady(string $service): bool
            {
                return true;
            }
        });
    }

    public function test_primary_staff_policy_reads_any_active_client_without_enabling_mutations(): void
    {
        config(['listing-studio.delegated_enabled' => false]);
        Sanctum::actingAs($this->rep);
        $this->getJson('/api/listing-studio/staff/clients')->assertOk()->assertJsonPath('data.clients.0.id', (string) $this->client->id);
        $this->getJson($this->base().'/account')->assertOk()->assertJsonPath('data.client.id', (string) $this->client->id)
            ->assertJsonPath('data.management.canPurchase', false)->assertJsonPath('data.management.canManageSubscription', false)
            ->assertJsonPath('data.capabilities.canPayByCard', false)->assertJsonPath('data.capabilities.canManageBilling', false);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base().'/billing-requests', ['kind' => 'subscription_purchase', 'planCode' => 'starter'])->assertStatus(503);
        foreach (['representative', 'sales_rep', 'sales rep'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'account_status' => 'active']));
            $this->getJson($this->base().'/account')->assertOk();
        }
        foreach (['admin', 'client', 'editor'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'secondary_roles' => ['superadmin']]));
            $this->getJson($this->base().'/account')->assertForbidden();
        }
        Sanctum::actingAs($this->rep);
        $this->withHeader('X-Impersonate-User-Id', (string) $this->client->id)->getJson($this->base().'/account')->assertForbidden();
        $this->assertDatabaseCount('listing_studio_billing_requests', 0);
        Http::assertNothingSent();
    }

    public function test_locked_or_inactive_target_is_not_exposed_or_mutated(): void
    {
        $this->client->update(['locked_at' => now()]);
        Sanctum::actingAs($this->rep);
        $this->getJson('/api/listing-studio/staff/clients')->assertOk()->assertJsonCount(0, 'data.clients');
        $this->getJson($this->base().'/account')->assertForbidden();
        $this->assertDatabaseCount('listing_studio_orders', 0);
    }

    public function test_real_upload_quote_and_credit_order_keep_client_owner_and_actor_scope(): void
    {
        $subscription = $this->credit(700);
        $upload = $this->upload();
        $quote = $this->quote($upload->id);
        Sanctum::actingAs($this->rep);
        $key = (string) Str::uuid();
        $payload = ['quoteId' => $quote->id, 'fundingMethod' => 'credits'];
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/orders', $payload)->assertUnprocessable();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/orders', [...$payload, 'creditConsent' => true])->assertCreated();
        $order = ListingStudioOrder::sole();
        $this->assertSame((int) $this->client->id, (int) $order->client_id);
        $this->assertSame((int) $this->rep->id, (int) $order->actor_id);
        $this->assertSame((int) $this->client->id, (int) $order->item->client_id);
        $this->assertSame(0, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        $this->assertDatabaseHas('user_activity_logs', ['actor_user_id' => $this->rep->id, 'user_id' => $this->client->id, 'event_type' => 'listing_studio.delegated_order']);
        config(['listing-studio.delegated_enabled' => false]);
        $this->travel(20)->minutes();
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/orders', [...$payload, 'creditConsent' => true])->assertOk()->assertJsonPath('data.created', false);
        $this->assertDatabaseCount('listing_studio_orders', 1);
        $this->assertDatabaseCount('listing_studio_credit_reservations', 1);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->assertSame(1, DB::table('user_activity_logs')->where('event_type', 'listing_studio.delegated_order')->count());
        $other = User::factory()->create(['role' => 'salesrep']);
        Sanctum::actingAs($other);
        $this->getJson($this->base().'/orders/'.$order->id)->assertNotFound();
        $this->getJson($this->base().'/uploads/'.$upload->id.'/preview')->assertNotFound();
        $this->getJson($this->base().'/orders')->assertOk()->assertJsonCount(0, 'data.orders');
        Sanctum::actingAs($this->client);
        $this->getJson('/api/listing-studio/client/orders/'.$order->id)->assertOk();
        $response->assertJsonPath('data.order.actions.canCheckout', false);
        Http::assertNothingSent();
    }

    public function test_delegated_source_cannot_be_another_actor_or_client_private_photo(): void
    {
        $upload = app(ListingStudioPaidMedia::class)->upload($this->client, UploadedFile::fake()->image('client.jpg', 80, 60), (string) Str::uuid());
        Sanctum::actingAs($this->rep);
        $this->postJson($this->base().'/quotes', $this->quoteInput($upload->id))->assertNotFound();
        $this->getJson($this->base().'/uploads/'.$upload->id.'/preview')->assertNotFound();
        $own = $this->upload();
        $other = User::factory()->create(['role' => 'client']);
        $this->postJson('/api/listing-studio/staff/clients/'.$other->id.'/quotes', $this->quoteInput($own->id))->assertNotFound();
        $this->assertDatabaseCount('listing_studio_quotes', 0);
    }

    public function test_staff_card_order_and_payment_request_never_invoke_checkout_or_expose_stripe_urls(): void
    {
        $checkout = Mockery::mock(StudioCheckoutService::class);
        $checkout->shouldNotReceive('order');
        $checkout->shouldNotReceive('subscription');
        $this->app->instance(StudioCheckoutService::class, $checkout);
        $order = $this->cardOrder();
        Sanctum::actingAs($this->rep);
        $key = (string) Str::uuid();
        $payload = ['kind' => 'order_payment', 'orderId' => $order->id];
        $result = $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/billing-requests', $payload)->assertCreated()
            ->assertJsonPath('data.request.actions.canConfirm', false)->assertJsonPath('data.request.actions.canShare', true);
        $id = $result->json('data.request.id');
        $result->assertJsonPath('data.request.appUrl', 'https://reprodashboard.com/listing-studio/payment-requests/'.$id);
        $this->assertStringNotContainsString('stripe.com', $result->getContent());
        config(['listing-studio.delegated_enabled' => false]);
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/billing-requests', $payload)->assertOk()->assertJsonPath('data.created', false);
        $this->withHeader('Idempotency-Key', $key)->postJson($this->base().'/billing-requests', ['kind' => 'subscription_purchase', 'planCode' => 'starter'])->assertConflict();
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_client_review_and_explicit_confirmation_are_owned_stable_and_outside_transaction(): void
    {
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $review = $service->review($this->client, $request->id)['review'];
        $floor = DB::transactionLevel();
        $keys = [];
        $checkout = Mockery::mock(StudioCheckoutService::class);
        $checkout->shouldReceive('subscription')->twice()->andReturnUsing(function (User $client, $plan, $key) use ($request, $floor, &$keys) {
            $this->assertSame($this->client->id, $client->id);
            $this->assertSame('starter', $plan);
            $this->assertSame($request->checkout_key, $key);
            $this->assertSame($floor, DB::transactionLevel());
            $this->assertNotNull($request->fresh()->confirmed_at);
            $keys[] = $key;

            return ['status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/fake', 'expiresAt' => now()->addHour()->toIso8601String(), 'nextAction' => ['type' => 'redirect']];
        });
        $this->app->instance(StudioCheckoutService::class, $checkout);
        $confirmation = ['reviewId' => $review['reviewId'], 'confirmationToken' => $review['confirmationToken'], 'acceptCharges' => true];
        $this->expectCode('validation_failed', fn () => $service->confirm($this->client, $request->id, [...$confirmation, 'acceptCharges' => false]));
        $this->expectCode('review_stale', fn () => $service->confirm($this->client, $request->id, [...$confirmation, 'confirmationToken' => 'wrong']));
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/listing-studio/client/billing-requests/'.$request->id)->assertNotFound();
        Sanctum::actingAs($this->rep);
        $this->postJson('/api/listing-studio/client/billing-requests/'.$request->id.'/confirm', $confirmation)->assertForbidden();
        Sanctum::actingAs($this->client);
        $this->postJson('/api/listing-studio/client/billing-requests/'.$request->id.'/confirm', $confirmation)->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/fake');
        $service->confirm($this->client, $request->id, $confirmation);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertDatabaseCount('listing_studio_billing_requests', 1);
    }

    public function test_expired_or_cancelled_request_cannot_start_checkout_and_exact_replay_stays_same(): void
    {
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $review = $service->review($this->client, $request->id)['review'];
        $this->travel(25)->hours();
        $this->assertSame('expired', $service->review($this->client, $request->id)['request']['status']);
        $this->expectCode('billing_request_expired', fn () => $service->confirm($this->client, $request->id, [...$review, 'acceptCharges' => true]));
        $fresh = $this->billing();
        $service->cancel($fresh, $this->client);
        $this->assertSame('cancelled', $service->review($this->client, $fresh->id)['request']['status']);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
    }

    public function test_changed_plan_price_requires_new_review_and_new_requests_are_bounded(): void
    {
        $service = app(ListingStudioBillingRequestService::class);
        $request = $this->billing();
        $review = $service->review($this->client, $request->id)['review'];
        config(['listing-studio.plans.starter.price_cents' => 5900]);
        $this->expectCode('review_stale', fn () => $service->confirm($this->client, $request->id, [...$review, 'acceptCharges' => true]));
        $fresh = $service->review($this->client, $request->id)['review'];
        $this->assertSame(5900, $fresh['amountCents']);
        $this->assertNotSame($review['confirmationToken'], $fresh['confirmationToken']);
        $this->expectCode('billing_request_conflict', fn () => $this->billing());
        config(['listing-studio.billing_request_limit' => 1]);
        $this->expectCode('billing_request_limit', fn () => $this->billing());
    }

    public function test_registered_email_is_durable_deduplicated_rate_limited_and_branded(): void
    {
        $request = $this->billing();
        $service = app(ListingStudioBillingEmailService::class);
        $key = (string) Str::uuid();
        $first = $service->request($this->rep, $this->client, $request, $key);
        $this->assertTrue($first['created']);
        $this->assertFalse($service->request($this->rep, $this->client, $request, $key)['created']);
        $this->expectCode('email_rate_limited', fn () => $service->request($this->rep, $this->client, $request, (string) Str::uuid()));
        $this->assertDatabaseCount('listing_studio_billing_emails', 1);
        Queue::assertPushed(SendListingStudioBillingRequest::class);
        $floor = DB::transactionLevel();
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->withArgs(function (array $payload) use ($request, $floor) {
            $this->assertSame($this->client->email, $payload['to']);
            $this->assertSame($floor, DB::transactionLevel());
            $this->assertStringContainsString('data-email-design="atelier-v6"', $payload['body_html']);
            $this->assertStringContainsString('/listing-studio/payment-requests/'.$request->id, $payload['body_html']);
            $this->assertStringNotContainsString('stripe.com', $payload['body_html']);

            return true;
        })->andReturn(new Message(['status' => 'SENT']));
        $this->app->instance(MessagingService::class, $messaging);
        $delivery = ListingStudioBillingEmail::sole();
        $service->deliver($delivery->id);
        $service->deliver($delivery->id);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_uncertain_email_or_changed_registered_address_never_blindly_resends(): void
    {
        $request = $this->billing();
        $service = app(ListingStudioBillingEmailService::class);
        $service->request($this->rep, $this->client, $request, (string) Str::uuid());
        $delivery = ListingStudioBillingEmail::sole();
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andThrow(new \RuntimeException('Uncertain provider response'));
        $this->app->instance(MessagingService::class, $messaging);
        $service->deliver($delivery->id);
        $service->deliver($delivery->id);
        $this->assertSame('needs_attention', $delivery->fresh()->status);
        $this->travel(2)->hours();
        $service->request($this->rep, $this->client, $request, (string) Str::uuid());
        $queued = ListingStudioBillingEmail::where('status', 'queued')->sole();
        $this->client->update(['email' => 'new-account@example.com']);
        $service->deliver($queued->id);
        $this->assertSame('needs_attention', $queued->fresh()->status);
    }

    public function test_real_checkout_adapter_submits_only_after_client_consent_and_recovers_same_session(): void
    {
        require_once __DIR__.'/ListingStudioPaidPaymentsTest.php';
        config(['listing-studio.stripe_mode' => 'test', 'listing-studio.plans.starter.test_price' => 'price_starter']);
        $gateway = new FakeStudioStripeGateway;
        $gateway->transactionFloor = DB::transactionLevel();
        $this->app->instance(\App\Services\ListingStudio\StudioStripeGateway::class, $gateway);
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $review = $service->review($this->client, $request->id)['review'];
        $this->assertSame([], $gateway->calls);
        $confirmation = [...$review, 'acceptCharges' => true];
        $first = $service->confirm($this->client, $request->id, $confirmation);
        $this->assertSame('redirect', $first['nextAction']['type']);
        $this->assertSame(1, $gateway->count('createCheckout'));
        $this->assertSame($this->client->id, ListingStudioCheckoutAttempt::sole()->client_id);
        $this->assertSame($this->rep->id, ListingStudioCheckoutAttempt::sole()->actor_id);
        $this->travel(15)->minutes();
        config(['listing-studio.delegated_enabled' => false]);
        $second = $service->confirm($this->client, $request->id, $confirmation);
        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(1, $gateway->count('createCheckout'));
        $this->assertSame(1, $gateway->count('checkout'));
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 1);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 0);
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
        $this->expectCode('billing_request_conflict', fn () => $service->cancel($request->fresh(), $this->client));
    }

    public function test_unknown_checkout_preserves_request_and_same_provider_operation_without_settling(): void
    {
        require_once __DIR__.'/ListingStudioPaidPaymentsTest.php';
        config(['listing-studio.stripe_mode' => 'test', 'listing-studio.plans.starter.test_price' => 'price_starter']);
        $gateway = new FakeStudioStripeGateway;
        $gateway->transactionFloor = DB::transactionLevel();
        $gateway->loseCheckout = true;
        $this->app->instance(\App\Services\ListingStudio\StudioStripeGateway::class, $gateway);
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $confirmation = [...$service->review($this->client, $request->id)['review'], 'acceptCharges' => true];
        try {
            $service->confirm($this->client, $request->id, $confirmation);
            $this->fail('Expected ambiguous checkout response.');
        } catch (\App\Services\ListingStudio\StudioPaymentException $e) {
            $this->assertSame('awaiting_reconciliation', $e->errorCode);
        }
        $this->assertSame('processing', $request->fresh()->status);
        $attempt = ListingStudioCheckoutAttempt::sole();
        $this->assertNotNull($attempt->submission_started_at);
        $this->assertNull($attempt->session_id);
        $service->confirm($this->client, $request->id, $confirmation);
        $creates = array_values(array_filter($gateway->calls, fn ($call) => $call[0] === 'createCheckout'));
        $this->assertSame(1, count(array_unique(array_column($creates, 2))));
        $this->assertCount(1, $gateway->sessions);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 1);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 0);
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
    }

    public function test_private_actor_scope_is_rechecked_inside_core_accept_not_only_http(): void
    {
        $quote = $this->quote($this->upload()->id);
        $other = User::factory()->create(['role' => 'salesrep']);
        try {
            app(ListingStudioOrderService::class)->acceptQuote($this->client, $quote->id, 'card', (string) Str::uuid(), $other);
            $this->fail('Another actor cannot accept this quote.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('listing_studio_orders', 0);
        $this->assertNull($quote->fresh()->consumed_at);
    }

    public function test_revoked_initiator_and_literal_client_search_are_enforced(): void
    {
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $confirmation = [...$service->review($this->client, $request->id)['review'], 'acceptCharges' => true];
        $this->rep->update(['role' => 'admin']);
        $this->expectCode('delegated_billing_forbidden', fn () => $service->confirm($this->client, $request->id, $confirmation));
        $this->assertFalse($service->present($request, true)['actions']['canConfirm']);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
        $this->rep->update(['role' => 'salesRep']);
        $this->client->update(['name' => 'Client 100%_Ready']);
        Sanctum::actingAs($this->rep);
        $this->getJson('/api/listing-studio/staff/clients?q='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'data.clients');
        $this->getJson('/api/listing-studio/staff/clients?q='.urlencode('100%_Other'))->assertOk()->assertJsonCount(0, 'data.clients');
    }

    public function test_status_lists_and_pending_summary_refresh_definitive_expiry_without_network(): void
    {
        $request = $this->billing();
        $this->travel(25)->hours();
        Sanctum::actingAs($this->rep);
        $this->getJson($this->base().'/billing-requests')->assertOk()->assertJsonPath('data.requests.0.status', 'expired');
        $this->getJson($this->base().'/account')->assertOk()->assertJsonPath('data.pendingBilling.count', 0);
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertNull($request->fresh()->confirmed_at);
        Http::assertNothingSent();
    }

    public function test_actor_revocation_during_checkout_customer_lookup_blocks_first_provider_submission(): void
    {
        require_once __DIR__.'/ListingStudioPaidPaymentsTest.php';
        config(['listing-studio.stripe_mode' => 'test', 'listing-studio.plans.starter.test_price' => 'price_starter']);
        $gateway = new FakeStudioStripeGateway;
        $gateway->transactionFloor = DB::transactionLevel();
        $gateway->onCustomerCreated = fn () => $this->rep->update(['locked_at' => now()]);
        $this->app->instance(\App\Services\ListingStudio\StudioStripeGateway::class, $gateway);
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $confirmation = [...$service->review($this->client, $request->id)['review'], 'acceptCharges' => true];
        $this->expectCode('delegated_billing_forbidden', fn () => $service->confirm($this->client, $request->id, $confirmation));
        $this->assertSame(0, $gateway->count('createCheckout'));
        $this->assertNull(ListingStudioCheckoutAttempt::sole()->submission_started_at);
        $this->assertNull(ListingStudioCheckoutAttempt::sole()->parameters);
    }

    public function test_cancel_does_not_race_an_inflight_confirmation_and_recovery_is_bounded(): void
    {
        $request = $this->billing();
        $lock = \Illuminate\Support\Facades\Cache::store('database')->lock('listing-studio-billing-confirm:'.$request->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->expectCode('billing_request_in_progress', fn () => app(ListingStudioBillingRequestService::class)->cancel($request, $this->client));
        } finally {
            $lock->release();
        }
        $this->assertSame('pending', $request->fresh()->status);
        foreach (range(1, 3) as $i) {
            ListingStudioBillingEmail::create(['billing_request_id' => $request->id, 'client_id' => $this->client->id, 'actor_id' => $this->rep->id,
                'request_key' => (string) Str::uuid(), 'recipient' => $this->client->email, 'status' => 'submitting', 'submission_started_at' => now()->subHour()]);
        }
        app(ListingStudioBillingEmailService::class)->recover(1);
        $this->assertSame(1, ListingStudioBillingEmail::where('status', 'needs_attention')->count());
        $this->assertSame(2, ListingStudioBillingEmail::where('status', 'submitting')->count());
        Queue::assertNothingPushed();
    }

    public function test_http_card_order_does_not_require_credit_consent_and_never_starts_checkout(): void
    {
        $quote = $this->quote($this->upload()->id);
        Sanctum::actingAs($this->rep);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base().'/orders',
            ['quoteId' => $quote->id, 'fundingMethod' => 'card'])->assertCreated()
            ->assertJsonPath('data.order.actions.canCheckout', false)->assertJsonPath('data.order.actions.canRequestPayment', true);
        $this->assertSame('awaiting_funding', ListingStudioOrder::sole()->item->status);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 0);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        Http::assertNothingSent();
    }

    public function test_upgrade_recovery_url_is_client_only_and_survives_a_fresh_review(): void
    {
        $this->rep->update(['role' => 'superadmin']);
        $request = $this->billing();
        $request->update(['kind' => 'subscription_upgrade', 'plan_code' => 'pro', 'status' => 'processing', 'confirmed_at' => now()]);
        $action = Mockery::mock();
        $action->shouldReceive('review')->once()->andReturn(['status' => 'processing', 'review' => null,
            'nextAction' => ['type' => 'redirect'], 'url' => 'https://invoice.stripe.com/i/recovery']);
        $this->app->instance('App\\Services\\ListingStudio\\StudioSubscriptionActionService', $action);
        Sanctum::actingAs($this->client);
        $this->getJson('/api/listing-studio/client/billing-requests/'.$request->id)->assertOk()
            ->assertJsonPath('data.review', null)->assertJsonPath('data.nextAction.type', 'redirect')
            ->assertJsonPath('data.url', 'https://invoice.stripe.com/i/recovery');
        Sanctum::actingAs($this->rep);
        $response = $this->getJson($this->base().'/billing-requests/'.$request->id)->assertOk();
        $this->assertStringNotContainsString('invoice.stripe.com', $response->getContent());
    }

    public function test_conclusively_unsubmitted_confirmation_can_cancel_or_expire_but_busy_flow_is_preserved(): void
    {
        require_once __DIR__.'/ListingStudioPaidPaymentsTest.php';
        config(['listing-studio.stripe_mode' => 'test', 'listing-studio.plans.starter.test_price' => 'price_starter']);
        $gateway = new FakeStudioStripeGateway;
        $gateway->transactionFloor = DB::transactionLevel();
        $gateway->priceAmount = 9999;
        $this->app->instance(\App\Services\ListingStudio\StudioStripeGateway::class, $gateway);
        $request = $this->billing();
        $service = app(ListingStudioBillingRequestService::class);
        $confirmation = [...$service->review($this->client, $request->id)['review'], 'acceptCharges' => true];
        try {
            $service->confirm($this->client, $request->id, $confirmation);
            $this->fail();
        } catch (\App\Services\ListingStudio\StudioPaymentException $e) {
            $this->assertSame('checkout_unavailable', $e->errorCode);
        }
        $attempt = ListingStudioCheckoutAttempt::sole();
        $this->assertNull($attempt->submission_started_at);
        $this->assertNull($attempt->session_id);
        $this->assertTrue($service->present($request->fresh(), true)['actions']['canCancel']);
        $service->cancel($request->fresh(), $this->client);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame('cancelled', $request->fresh()->status);
        $next = $this->billing();
        $next->update(['status' => 'processing', 'confirmed_at' => now()]);
        $this->travel(25)->hours();
        $lock = \Illuminate\Support\Facades\Cache::store('database')->lock('listing-studio-billing-confirm:'.$next->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame('processing', $service->refresh($next->fresh())->status);
        } finally {
            $lock->release();
        }
        $this->assertSame('expired', $service->refresh($next->fresh())->status);
        $this->assertSame(0, $gateway->count('createCheckout'));
    }

    public function test_payment_exceptions_keep_safe_codes_and_statuses_over_new_http_routes(): void
    {
        $this->rep->update(['role' => 'superadmin']);
        $request = $this->billing();
        $request->update(['kind' => 'subscription_upgrade', 'plan_code' => 'pro']);
        $action = Mockery::mock();
        $action->shouldReceive('review')->once()->andThrow(new \App\Services\ListingStudio\StudioPaymentException('subscription_review_expired', 'Review the current adjustment.', 409));
        $action->shouldReceive('confirm')->once()->andThrow(new \App\Services\ListingStudio\StudioPaymentException('subscription_review_changed', 'The adjustment changed.', 409));
        $action->shouldReceive('execute')->once()->andThrow(new \App\Services\ListingStudio\StudioPaymentException('subscription_conflict', 'The subscription changed.', 409));
        $this->app->instance('App\\Services\\ListingStudio\\StudioSubscriptionActionService', $action);
        Sanctum::actingAs($this->client);
        $this->getJson('/api/listing-studio/client/billing-requests/'.$request->id)->assertConflict()->assertJsonPath('code', 'subscription_review_expired');
        $this->postJson('/api/listing-studio/client/billing-requests/'.$request->id.'/confirm',
            ['reviewId' => (string) Str::uuid(), 'confirmationToken' => 'review-token', 'acceptCharges' => true])
            ->assertConflict()->assertJsonPath('code', 'subscription_review_changed');
        Sanctum::actingAs($this->rep);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base().'/subscription-actions', ['action' => 'cancel_renewal'])
            ->assertConflict()->assertJsonPath('code', 'subscription_conflict');
        Http::assertNothingSent();
    }

    public function test_self_service_checkout_keeps_client_actor_and_delegated_actor_is_immutable_on_replay(): void
    {
        require_once __DIR__.'/ListingStudioPaidPaymentsTest.php';
        config(['listing-studio.stripe_mode' => 'test', 'listing-studio.plans.starter.test_price' => 'price_starter']);
        $gateway = new FakeStudioStripeGateway;
        $gateway->transactionFloor = DB::transactionLevel();
        $this->app->instance(\App\Services\ListingStudio\StudioStripeGateway::class, $gateway);
        $key = (string) Str::uuid();
        app(StudioCheckoutService::class)->subscription($this->client, 'starter', $key);
        $attempt = ListingStudioCheckoutAttempt::sole();
        $this->assertSame($this->client->id, $attempt->actor_id);
        app(StudioCheckoutService::class)->subscription($this->client, 'starter', $key);
        $this->assertSame($this->client->id, $attempt->fresh()->actor_id);
        $this->assertSame(1, $gateway->count('createCheckout'));
    }

    private function billing(): ListingStudioBillingRequest
    {
        return app(ListingStudioBillingRequestService::class)->create($this->rep, $this->client, ['kind' => 'subscription_purchase', 'planCode' => 'starter'], (string) Str::uuid())['request'];
    }

    private function upload()
    {
        return app(ListingStudioPaidMedia::class)->upload($this->client, UploadedFile::fake()->image('property.jpg', 80, 60), (string) Str::uuid(), $this->rep);
    }

    private function quoteInput(string $id): array
    {
        return ['kind' => 'ai_edit', 'service' => 'grass', 'source' => ['kind' => 'upload', 'id' => $id]];
    }

    private function quote(string $id)
    {
        return app(ListingStudioQuoteService::class)->create($this->client, $this->quoteInput($id), $this->rep);
    }

    private function cardOrder(): ListingStudioOrder
    {
        return app(ListingStudioOrderService::class)->acceptQuote($this->client, $this->quote($this->upload()->id)->id, 'card', (string) Str::uuid(), $this->rep)['order'];
    }

    private function base(): string
    {
        return '/api/listing-studio/staff/clients/'.$this->client->id;
    }

    private function credit(int $amount): ListingStudioSubscription
    {
        $sub = ListingStudioSubscription::create(['stripe_account_id' => 'acct_studio', 'livemode' => true, 'stripe_subscription_id' => 'sub_delegate',
            'stripe_customer_id' => 'cus_client', 'client_id' => $this->client->id, 'plan_code' => 'starter', 'status' => 'active', 'sync_status' => 'synced',
            'currency' => 'usd', 'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->addMonth()->startOfMonth()]);
        DB::table('listing_studio_credit_entries')->insert(['subscription_id' => $sub->id, 'stripe_invoice_id' => 'in_delegate', 'source_type' => 'invoice',
            'source_key' => 'in_delegate', 'plan_code' => 'starter', 'amount_cents' => $amount, 'currency' => 'usd',
            'period_start' => $sub->current_period_start, 'expires_at' => $sub->current_period_end, 'created_at' => now(), 'updated_at' => now()]);

        return $sub;
    }

    private function expectCode(string $code, callable $run): void
    {
        try {
            $run();
            $this->fail('Expected '.$code);
        } catch (PublicApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
