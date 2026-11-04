<?php

namespace Tests\Feature;

use App\Jobs\SendListingStudioSubscriptionReceipt;
use App\Models\ListingStudioSubscription;
use App\Models\ListingStudioSubscriptionReceipt;
use App\Models\Message;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioSubscriptionReceiptService;
use App\Services\ListingStudio\StripeSubscriptionGateway;
use App\Services\ListingStudio\StudioStripeGateway;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingStudioClaimSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/listing-studio/trials/billing';
    private User $client;
    private array $session = [];
    private array $canonical = [];
    private array $checkoutBodies = [];
    private array $portalReturns = [];
    private bool $loseResponse = false;
    private int $floor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['listing-studio.paid_enabled' => true, 'listing-studio.paid_checkout_enabled' => true,
            'listing-studio.stripe_enabled' => true, 'listing-studio.stripe_account_id' => 'acct_claim',
            'listing-studio.stripe_mode' => 'test', 'listing-studio.webhook_secret' => 'whsec_claim_fixture',
            'listing-studio.subscription_receipts_enabled' => true, 'app.url' => 'https://dashboard.example.test']);
        foreach (['starter', 'pro', 'studio'] as $plan) {
            config(['listing-studio.plans.'.$plan.'.test_price' => 'price_'.$plan]);
        }
        Queue::fake(); Http::preventStrayRequests();
        DB::table('auth_security_rollouts')->updateOrInsert(['name' => 'email-verification'], ['started_at' => now()->subDays(30)]);
        $this->client = User::factory()->unverified()->create(['role' => 'client', 'account_status' => 'active', 'email' => 'buyer@example.test']);
        $this->client->forceFill(['email_verified_email' => null, 'email_verification_required_at' => now()->subDays(30)])->saveQuietly();
        $this->floor = DB::transactionLevel();
        $gateway = Mockery::mock(StudioStripeGateway::class);
        $gateway->shouldReceive('account')->andReturn(['id' => 'acct_claim']);
        $gateway->shouldReceive('createCustomer')->andReturn(['id' => 'cus_claim', 'livemode' => false]);
        $gateway->shouldReceive('customer')->andReturn(['id' => 'cus_claim', 'livemode' => false]);
        $gateway->shouldReceive('subscriptions')->andReturn(['has_more' => false, 'data' => []]);
        $gateway->shouldReceive('price')->andReturnUsing(fn ($id) => ['id' => $id, 'livemode' => false, 'active' => true,
            'unit_amount' => config('listing-studio.plans.'.substr($id, 6).'.price_cents'), 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]);
        $gateway->shouldReceive('createCheckout')->andReturnUsing(function ($parameters, $key) {
            $this->assertSame($this->floor, DB::transactionLevel());
            $this->checkoutBodies[] = [$parameters, $key];
            $amount = config('listing-studio.plans.'.substr($parameters['line_items'][0]['price'], 6).'.price_cents');
            $this->session = $this->session ?: ['id' => 'cs_claim', 'livemode' => false, 'mode' => 'subscription', 'customer' => 'cus_claim',
                'metadata' => $parameters['metadata'], 'client_reference_id' => $parameters['client_reference_id'], 'status' => 'open',
                'payment_status' => 'unpaid', 'currency' => 'usd', 'amount_total' => $amount, 'url' => 'https://checkout.stripe.com/c/pay/claim_fixture',
                'expires_at' => $parameters['expires_at'], 'line_items' => ['has_more' => false, 'data' => [['quantity' => 1, 'amount_total' => $amount, 'price' => ['id' => $parameters['line_items'][0]['price']]]]]];
            if ($this->loseResponse) { $this->loseResponse = false; throw new \RuntimeException('Lost fixture response'); }
            return $this->session;
        });
        $gateway->shouldReceive('checkout')->andReturnUsing(fn () => $this->session);
        $gateway->shouldReceive('portal')->andReturnUsing(function ($customer, $return) {
            $this->portalReturns[] = $return;
            return ['customer' => $customer, 'livemode' => false, 'url' => 'https://billing.stripe.com/p/session/claim_fixture'];
        });
        $this->app->instance(StudioStripeGateway::class, $gateway);
        $subscriptions = Mockery::mock(StripeSubscriptionGateway::class);
        $subscriptions->shouldReceive('subscription')->andReturnUsing(function () { $this->assertSame($this->floor, DB::transactionLevel()); return $this->canonical; });
        $subscriptions->shouldReceive('invoice')->andReturnUsing(fn () => $this->canonical['latest_invoice']);
        $this->app->instance(StripeSubscriptionGateway::class, $subscriptions);
    }

    public function test_unverified_claim_client_can_see_plans_checkout_and_manage_billing_without_extending_editor_access(): void
    {
        Sanctum::actingAs($this->client);
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(3, 'data.catalog.plans')->assertJsonPath('data.account.credits.availableCents', 0);
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::BASE.'/subscription-checkout', ['planCode' => 'starter', 'returnUrl' => 'https://evil.example'])
            ->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/claim_fixture');
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.pendingCheckout.requestKey', $key);
        $this->assertSame('https://dashboard.example.test/listing-studio/claim?checkout=returned', $this->checkoutBodies[0][0]['success_url']);
        $this->assertSame('https://dashboard.example.test/listing-studio/claim?checkout=cancelled', $this->checkoutBodies[0][0]['cancel_url']);
        $this->postJson(self::BASE.'/portal')->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/claim_fixture');
        $this->assertSame(['https://dashboard.example.test/listing-studio/claim'], $this->portalReturns);
        $this->getJson('/api/listing-studio/client/account')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->postJson('/api/listing-studio/client/subscription-checkout', ['planCode' => 'pro'])->assertForbidden();
        $this->assertDatabaseCount('listing_studio_subscriptions', 0);
        $this->assertDatabaseCount('listing_studio_subscription_receipts', 0);
        $this->assertNull($this->client->fresh()->email_verified_at);
        Queue::assertNothingPushed();
    }

    public function test_lost_checkout_response_reuses_its_exact_request_and_never_opens_another_plan(): void
    {
        Sanctum::actingAs($this->client); $key = (string) Str::uuid(); $this->loseResponse = true;
        $this->withHeader('Idempotency-Key', $key)->postJson(self::BASE.'/subscription-checkout', ['planCode' => 'pro'])->assertStatus(503);
        $this->withHeader('Idempotency-Key', $key)->postJson(self::BASE.'/subscription-checkout', ['planCode' => 'pro'])->assertOk();
        $this->assertSame($this->checkoutBodies[0], $this->checkoutBodies[1]);
        $this->assertDatabaseCount('listing_studio_checkout_attempts', 1);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::BASE.'/subscription-checkout', ['planCode' => 'studio'])->assertConflict();
        $this->assertCount(2, $this->checkoutBodies);
    }

    public function test_billing_requires_own_active_client_and_does_not_expose_another_clients_pending_checkout(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::BASE)->assertForbidden();
        }
        Sanctum::actingAs($this->client);
        $this->withHeader('X-Impersonate-User-Id', $this->client->id)->getJson(self::BASE)->assertForbidden(); $this->flushHeaders();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson(self::BASE.'/subscription-checkout', ['planCode' => 'starter'])->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.pendingCheckout', null)->assertDontSee($key);
    }

    #[DataProvider('plans')]
    public function test_verified_paid_event_updates_the_same_client_and_reserves_one_branded_confirmation(string $plan, int $credit): void
    {
        $this->start($plan); $this->canonical = $this->canonical($plan);
        $this->webhook('evt_paid')->assertOk(); $this->webhook('evt_paid')->assertOk(); $this->webhook('evt_paid_later')->assertOk();
        $this->assertSame($this->client->id, ListingStudioSubscription::sole()->client_id);
        $this->assertDatabaseCount('users', 1); $this->assertDatabaseCount('listing_studio_subscription_receipts', 1);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.account.subscription.planCode', $plan)
            ->assertJsonPath('data.account.credits.availableCents', $credit)->assertJsonPath('data.confirmationEmail.status', 'queued');
        $receipt = ListingStudioSubscriptionReceipt::sole(); $this->assertSame('buyer@example.test', $receipt->recipient);
        $this->assertSame($credit, $receipt->snapshot['creditCents']);
        Queue::assertPushed(SendListingStudioSubscriptionReceipt::class);
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->once()->andReturnUsing(function ($payload) use ($plan) {
            $this->assertSame($this->floor, DB::transactionLevel());
            $this->assertSame('buyer@example.test', $payload['to']);
            $this->assertStringContainsString(config('listing-studio.plans.'.$plan.'.name'), $payload['subject']);
            $this->assertStringContainsString('email-logo-universal', $payload['body_html']);
            $this->assertStringContainsString('Open Listing Studio', $payload['body_html']);
            $this->assertStringNotContainsString('Verify your email', $payload['body_html']);
            return new Message(['status' => 'SENT', 'sent_at' => now()]);
        });
        $emails = app(ListingStudioSubscriptionReceiptService::class); $emails->deliver($receipt->id); $emails->deliver($receipt->id);
        $this->assertSame('sent', $receipt->fresh()->status);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.confirmationEmail.status', 'sent');
        $this->assertDatabaseCount('shoots', 0); $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public static function plans(): array { return [['starter', 6000], ['pro', 13500], ['studio', 37500]]; }

    public function test_unpaid_and_forged_events_do_not_grant_credit_or_send_confirmation(): void
    {
        $this->start('starter'); $this->canonical = $this->canonical('starter');
        $this->canonical['latest_invoice']['status'] = 'open'; $this->canonical['latest_invoice']['amount_paid'] = 0; $this->canonical['status'] = 'incomplete';
        $this->webhook('evt_unpaid')->assertOk();
        $this->assertDatabaseCount('listing_studio_credit_entries', 0); $this->assertDatabaseCount('listing_studio_subscription_receipts', 0);
        $this->canonical = $this->canonical('starter');
        $this->postJson('/api/webhooks/stripe/listing-studio', ['id' => 'evt_forged', 'type' => 'invoice.paid'])->assertStatus(400);
        $this->assertDatabaseCount('listing_studio_subscription_receipts', 0); Queue::assertNothingPushed();
    }

    public function test_unknown_send_outcomes_never_cause_an_automatic_resend(): void
    {
        $this->start('starter'); $this->canonical = $this->canonical('starter'); $this->webhook('evt_ready')->assertOk();
        $receipt = ListingStudioSubscriptionReceipt::sole();
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->once()->andThrow(new \RuntimeException('Unknown fixture outcome'));
        $emails = app(ListingStudioSubscriptionReceiptService::class); $emails->deliver($receipt->id); $emails->deliver($receipt->id);
        $emails->recover(); $this->assertSame('needs_attention', $receipt->fresh()->status);
        $this->assertDatabaseCount('listing_studio_subscription_receipts', 1);
    }

    public function test_a_changed_account_email_cannot_receive_the_old_receipt(): void
    {
        $this->start('pro'); $this->canonical = $this->canonical('pro'); $this->webhook('evt_identity')->assertOk();
        $receipt = ListingStudioSubscriptionReceipt::sole();
        $this->client->update(['email' => 'replacement@example.test']);
        $this->mock(MessagingService::class)->shouldNotReceive('sendEmail');
        app(ListingStudioSubscriptionReceiptService::class)->deliver($receipt->id);
        $this->assertSame('needs_attention', $receipt->fresh()->status);
        $this->assertSame('receipt_account_changed', $receipt->fresh()->error_code);
    }

    public function test_deferred_email_recovery_tracks_the_existing_message_without_sending_again(): void
    {
        $this->start('pro'); $this->canonical = $this->canonical('pro'); $this->webhook('evt_deferred')->assertOk();
        $receipt = ListingStudioSubscriptionReceipt::sole();
        $message = Message::create(['channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'provider' => 'fixture',
            'to_address' => $this->client->email, 'status' => 'SCHEDULED', 'related_account_id' => $this->client->id,
            'metadata' => ['listing_studio_subscription_receipt_id' => $receipt->id]]);
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->once()->andReturn($message);
        $emails = app(ListingStudioSubscriptionReceiptService::class); $emails->deliver($receipt->id);
        $this->assertSame('awaiting_delivery', $receipt->fresh()->status);
        $emails->recover(); $this->assertSame('awaiting_delivery', $receipt->fresh()->status);
        $message->update(['status' => 'SENT', 'sent_at' => now()]);
        $emails->recover(); $emails->deliver($receipt->id);
        $this->assertSame('sent', $receipt->fresh()->status);
        $this->assertSame($message->id, $receipt->fresh()->message_id);
        $this->assertDatabaseCount('messages', 1);
    }

    private function start(string $plan): void
    {
        Sanctum::actingAs($this->client);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::BASE.'/subscription-checkout', ['planCode' => $plan])->assertOk();
    }

    private function canonical(string $plan): array
    {
        $definition = config('listing-studio.plans.'.$plan); $start = now()->startOfSecond()->timestamp; $end = now()->addMonth()->startOfSecond()->timestamp;
        return ['id' => 'sub_claim', 'livemode' => false, 'status' => 'active', 'customer' => ['id' => 'cus_claim', 'email' => 'changed-in-stripe@example.test', 'name' => 'Stripe billing name'],
            'metadata' => $this->session['metadata'], 'items' => ['data' => [['id' => 'si_claim', 'quantity' => 1, 'current_period_start' => $start, 'current_period_end' => $end,
                'price' => ['id' => 'price_'.$plan, 'unit_amount' => $definition['price_cents'], 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            'latest_invoice' => ['id' => 'in_claim', 'livemode' => false, 'status' => 'paid', 'billing_reason' => 'subscription_create', 'amount_paid' => $definition['price_cents'],
                'currency' => 'usd', 'customer' => 'cus_claim', 'parent' => ['subscription_details' => ['subscription' => 'sub_claim']], 'status_transitions' => ['paid_at' => $start],
                'lines' => ['has_more' => false, 'data' => [['id' => 'il_claim', 'type' => 'subscription', 'subscription' => 'sub_claim', 'price' => ['id' => 'price_'.$plan],
                    'quantity' => 1, 'amount' => $definition['price_cents'], 'period' => ['start' => $start, 'end' => $end]]]]]];
    }

    private function webhook(string $id)
    {
        $payload = json_encode(['id' => $id, 'type' => 'invoice.paid', 'livemode' => false, 'account' => 'acct_claim', 'created' => now()->timestamp, 'data' => ['object' => $this->canonical['latest_invoice']]]);
        $timestamp = now()->timestamp; $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_claim_fixture');
        return $this->call('POST', '/api/webhooks/stripe/listing-studio', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $payload);
    }
}
