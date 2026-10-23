<?php

namespace Tests\Feature;

use App\Http\Controllers\StripePaymentController;
use App\Jobs\DispatchPaymentReceipt;
use App\Models\Payment;
use App\Models\PublicPaymentAccessToken;
use App\Models\Shoot;
use App\Models\StripeCheckoutAttempt;
use App\Models\User;
use App\Services\Payments\StripePaymentMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StripeConfirmationContractTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmationContractController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.app_instance' => 'confirmation-contract-test',
            'services.stripe.secret_key' => 'sk_test_fictional_contract',
            'services.stripe.webhook_secret' => 'whsec_contract_test', 'cache.default' => 'array']);
        Mail::fake();
        Queue::fake();
        $metadata = Mockery::mock(StripePaymentMetadataService::class)->makePartial();
        $metadata->shouldReceive('hydratePaymentRecord', 'hydratePaymentRecordIfNeeded')->andReturnUsing(fn (Payment $payment) => $payment);
        $this->app->instance(StripePaymentMetadataService::class, $metadata);
        $this->controller = app(ConfirmationContractController::class);
        $this->app->instance(StripePaymentController::class, $this->controller);
    }

    public function test_generic_confirmation_preserves_a_permanent_cross_client_forbidden_response(): void
    {
        [$shoot] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));

        $this->postJson('/api/payments/stripe-session/confirm', ['session_id' => 'cs_contract'])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_checkout_attempts', 0);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);
        $this->assertNotNull($shoot->fresh());
    }

    #[TestWith(['single'])]
    #[TestWith(['public'])]
    #[TestWith(['generic'])]
    public function test_every_confirmation_route_recovers_the_same_exact_session_after_a_busy_response(string $route): void
    {
        [$shoot, $client] = $this->fixture();
        Sanctum::actingAs($client);
        $endpoint = $this->endpoint($route, $shoot);
        $lock = Cache::lock('stripe_reconcile_shoot_'.$shoot->id, 10);
        $this->assertTrue($lock->get());
        try {
            $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
                ->assertJsonPath('data.outcome', 'busy')->assertJsonPath('data.session_id', 'cs_contract')
                ->assertJsonPath('data.session_payment_status', 'paid')->assertJsonPath('data.payment_recorded', false);
            $this->assertDatabaseCount('payments', 0);
        } finally {
            $lock->release();
        }
        $this->record($shoot);
        // The webhook can revoke a public token before the browser's next poll.
        $shoot->publicPaymentAccessTokens()->update(['revoked_at' => now()]);

        $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'already_processed')->assertJsonPath('data.session_id', 'cs_contract')
            ->assertJsonPath('data.session_payment_status', 'paid')->assertJsonPath('data.payment_recorded', true)
            ->assertJsonPath('data.reconciled', false);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('stripe_checkout_attempts', 0);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);
    }

    #[TestWith(['single'])]
    #[TestWith(['public'])]
    #[TestWith(['generic'])]
    public function test_a_paid_aggregate_balance_never_proves_a_different_unpaid_session(string $route): void
    {
        [$shoot, $client] = $this->fixture();
        Sanctum::actingAs($client);
        $endpoint = $this->endpoint($route, $shoot);
        $this->record($shoot, 'cs_other', 'pi_other');
        $this->controller->sessions['cs_contract']->payment_status = 'unpaid';

        $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'unpaid')->assertJsonPath('data.payment_recorded', false)
            ->assertJsonPath('data.session_id', 'cs_contract')->assertJsonPath('data.remaining_balance', 0);
        $this->assertDatabaseCount('payments', 1);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);
    }

    #[TestWith(['single'])]
    #[TestWith(['public'])]
    #[TestWith(['generic'])]
    public function test_paid_reconciliation_failure_can_recover_only_when_the_same_session_is_recorded(string $route): void
    {
        [$shoot, $client] = $this->fixture();
        Sanctum::actingAs($client);
        $endpoint = $this->endpoint($route, $shoot);
        $this->controller->failSettlement = true;
        $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'failed')->assertJsonPath('data.session_payment_status', 'paid')
            ->assertJsonPath('data.payment_recorded', false)->assertJsonPath('data.reconciled', false);
        $this->assertDatabaseCount('payments', 0);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);

        $this->controller->failSettlement = false;
        $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'processed')->assertJsonPath('data.session_id', 'cs_contract')
            ->assertJsonPath('data.session_payment_status', 'paid')->assertJsonPath('data.payment_recorded', true);
        $this->postJson($endpoint, ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'already_processed')->assertJsonPath('data.payment_recorded', true);
        $this->assertDatabaseCount('payments', 1);
        Queue::assertPushed(DispatchPaymentReceipt::class, 1);
    }

    #[TestWith(['expired'])]
    #[TestWith(['unknown'])]
    public function test_unavailable_public_tokens_never_reconcile_a_session(string $kind): void
    {
        [$shoot] = $this->fixture();
        $token = $kind === 'expired' ? PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id, 'expires_at' => now()->subMinute(),
        ])->token : 'unknown-payment-token';

        $this->postJson('/api/public/payments/'.$token.'/confirm', ['session_id' => 'cs_contract'])->assertGone();

        $this->assertSame(0, $this->controller->sessionLookups);
        $this->assertDatabaseCount('payments', 0);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_generic_batch_browser_and_webhook_replays_record_each_shoot_and_queue_one_receipt(): void
    {
        [$first, $client] = $this->fixture();
        $second = Shoot::factory()->create(['client_id' => $client->id, 'total_quote' => 200, 'payment_status' => 'unpaid']);
        $session = $this->controller->sessions['cs_contract'];
        $session->metadata->type = 'multiple';
        $session->metadata->shoot_ids = $first->id.','.$second->id;
        unset($session->metadata->shoot_id);
        $session->amount_total = 30000;
        $stripe = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $stripe->shouldNotReceive('create');
        $stripe->shouldReceive('allLineItems')->once()->with('cs_contract', ['limit' => 100])
            ->andReturn((object) ['data' => [(object) ['amount_total' => 10000], (object) ['amount_total' => 20000]]]);
        $webhook = Mockery::mock('alias:Stripe\\Webhook');
        $webhook->shouldReceive('constructEvent')->once()->andReturn((object) ['id' => 'evt_contract',
            'type' => 'checkout.session.completed', 'data' => (object) ['object' => $session]]);
        Sanctum::actingAs($client);

        $this->postJson('/api/payments/stripe-session/confirm', ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'processed')->assertJsonPath('data.payment_recorded', true);
        $this->postEvent($session)->assertOk()->assertJsonPath('outcome', 'already_processed');
        $this->postJson('/api/payments/stripe-session/confirm', ['session_id' => 'cs_contract'])->assertOk()
            ->assertJsonPath('data.outcome', 'already_processed')->assertJsonPath('data.session_id', 'cs_contract')
            ->assertJsonPath('data.session_payment_status', 'paid')->assertJsonPath('data.payment_recorded', true);

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['shoot_id' => $first->id, 'stripe_session_id' => 'cs_contract_shoot_'.$first->id, 'amount' => 100]);
        $this->assertDatabaseHas('payments', ['shoot_id' => $second->id, 'stripe_session_id' => 'cs_contract_shoot_'.$second->id, 'amount' => 200]);
        Queue::assertPushed(DispatchPaymentReceipt::class, 1);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[TestWith(['paid'])]
    #[TestWith(['unpaid'])]
    public function test_a_prior_complete_session_blocks_new_checkout_even_when_reconciliation_failed(string $paymentStatus): void
    {
        [$shoot, $client] = $this->fixture();
        $client->update(['metadata' => ['stripe_customer_id_test' => 'cus_contract']]);
        $attempt = StripeCheckoutAttempt::create(['client_id' => $client->id, 'scope' => 'single_embedded',
            'ui_mode' => 'embedded', 'expected_amount_cents' => 10000, 'currency' => 'USD',
            'status' => StripeCheckoutAttempt::STATUS_PROCESSING, 'request_fingerprint' => hash('sha256', 'prior'),
            'idempotency_key' => 'contract_prior_attempt', 'stripe_session_id' => 'cs_contract', 'expires_at' => now()->addHour()]);
        $attempt->items()->create(['shoot_id' => $shoot->id, 'position' => 0, 'expected_amount_cents' => 10000]);
        $session = $this->controller->sessions['cs_contract'];
        $session->metadata->checkout_attempt_id = (string) $attempt->id;
        $session->metadata->client_id = (string) $client->id;
        $session->payment_status = $paymentStatus;
        $this->controller->failSettlement = true;
        $stripe = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $stripe->shouldNotReceive('create');
        $stripe->shouldReceive('retrieve')->once()->with('cs_contract')->andReturn($session);
        $customer = Mockery::mock('alias:Stripe\\Customer');
        $customer->shouldReceive('retrieve')->once()->with('cus_contract')->andReturn((object) [
            'id' => 'cus_contract', 'deleted' => false, 'metadata' => (object) ['app_user_id' => (string) $client->id]]);
        $customer->shouldReceive('update')->once()->andReturn((object) ['id' => 'cus_contract']);
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/shoots/'.$shoot->id.'/create-stripe-embedded-checkout', ['amount' => 100]);
        $response->assertStatus($paymentStatus === 'paid' ? 500 : 422);
        $this->assertDatabaseCount('stripe_checkout_attempts', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(StripeCheckoutAttempt::STATUS_PROCESSING, $attempt->fresh()->status);
        Queue::assertNotPushed(DispatchPaymentReceipt::class);
    }

    private function fixture(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'total_quote' => 100, 'payment_status' => 'unpaid',
            'address' => '100 Contract Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201']);
        $this->controller->sessions['cs_contract'] = (object) ['id' => 'cs_contract', 'mode' => 'payment',
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_contract',
            'amount_total' => 10000, 'currency' => 'usd', 'metadata' => (object) [
                'type' => 'single', 'shoot_id' => (string) $shoot->id, 'app_instance' => 'confirmation-contract-test']];

        return [$shoot, $client];
    }

    private function endpoint(string $route, Shoot $shoot): string
    {
        if ($route === 'generic') {
            return '/api/payments/stripe-session/confirm';
        }
        if ($route === 'single') {
            return '/api/shoots/'.$shoot->id.'/confirm-stripe-session';
        }
        $token = PublicPaymentAccessToken::create(['shoot_id' => $shoot->id, 'expires_at' => now()->addDay()]);

        return '/api/public/payments/'.$token->token.'/confirm';
    }

    private function record(Shoot $shoot, string $session = 'cs_contract', string $intent = 'pi_contract'): void
    {
        Payment::factory()->create(['shoot_id' => $shoot->id, 'amount' => 100, 'payment_method' => 'stripe',
            'stripe_session_id' => $session, 'stripe_payment_id' => $intent, 'status' => Payment::STATUS_COMPLETED]);
    }

    private function postEvent(object $session)
    {
        $payload = json_encode(['id' => 'evt_contract', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => $session]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_contract_test');

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $payload);
    }
}

class ConfirmationContractController extends StripePaymentController
{
    public array $sessions = [];

    public bool $failSettlement = false;

    public int $sessionLookups = 0;

    protected function retrieveCheckoutSession(string $sessionId)
    {
        $this->sessionLookups++;

        return $this->sessions[$sessionId] ?? throw new \RuntimeException('Unexpected provider session lookup.');
    }

    protected function processSingleShootPayment($session)
    {
        return $this->failSettlement ? false : parent::processSingleShootPayment($session);
    }
}
