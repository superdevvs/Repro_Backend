<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PublicPaymentAccessToken;
use App\Models\Shoot;
use App\Models\StripeCheckoutAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class StripeCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_embedded_checkout_requires_and_sends_owner_customer_property_and_billing_details(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_checkout');
        config()->set('services.stripe.currency', 'USD');

        $client = User::factory()->create([
            'name' => 'Jane Agent',
            'company_name' => 'VYBE Realty',
            'email' => 'listings@example.test',
            'metadata' => ['stripe_customer_id_test' => 'cus_existing'],
        ]);
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'address' => '2 Topwood Court',
            'city' => 'Parkville',
            'state' => 'MD',
            'zip' => '21234',
            'total_quote' => 200.39,
            'payment_status' => 'unpaid',
        ]);

        $customerMock = Mockery::mock('alias:Stripe\\Customer');
        $customerMock->shouldReceive('retrieve')
            ->once()
            ->with('cus_existing')
            ->andReturn((object) [
                'id' => 'cus_existing',
                'deleted' => false,
                'metadata' => (object) ['app_user_id' => (string) $client->id],
            ]);
        $customerMock->shouldReceive('update')
            ->once()
            ->with('cus_existing', Mockery::on(function (array $params): bool {
                return ($params['name'] ?? null) === 'VYBE Realty'
                    && ($params['business_name'] ?? null) === 'VYBE Realty'
                    && ($params['email'] ?? null) === 'listings@example.test'
                    && data_get($params, 'metadata.app_user_id') !== null;
            }))
            ->andReturn((object) ['id' => 'cus_existing']);

        $sessionMock = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $sessionMock->shouldReceive('create')
            ->once()
            ->with(
                Mockery::on(function (array $params) use ($client, $shoot): bool {
                    return ($params['ui_mode'] ?? null) === 'embedded'
                        && ($params['billing_address_collection'] ?? null) === 'required'
                        && data_get($params, 'name_collection.business.enabled') === true
                        && data_get($params, 'name_collection.business.optional') === false
                        && ($params['customer'] ?? null) === 'cus_existing'
                        && data_get($params, 'customer_update.address') === 'auto'
                        && data_get($params, 'customer_update.name') === 'auto'
                        && data_get($params, 'payment_intent_data.description') === '2 Topwood Court, Parkville, MD 21234'
                        && data_get($params, 'payment_intent_data.receipt_email') === 'listings@example.test'
                        && data_get($params, 'payment_intent_data.metadata.client_id') === (string) $client->id
                        && data_get($params, 'payment_intent_data.metadata.shoot_id') === (string) $shoot->id
                        && data_get($params, 'payment_intent_data.metadata.customer_email') === null
                        && data_get($params, 'payment_intent_data.metadata.property_address') === null
                        && data_get($params, 'payment_intent_data.metadata.return_to') === null
                        && data_get($params, 'metadata.return_to') === '/shoot-history?source=receipt#paid';
                }),
                Mockery::on(fn (array $options): bool => str_starts_with(
                    (string) ($options['idempotency_key'] ?? ''),
                    'repro_checkout_single_embedded_'
                ))
            )
            ->andReturn((object) [
                'id' => 'cs_test_required_details',
                'client_secret' => 'cs_secret_required_details',
                'status' => 'open',
                'payment_status' => 'unpaid',
                'expires_at' => now()->addHours(2)->timestamp,
            ]);

        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-embedded-checkout", [
            'amount' => 200.39,
            'return_to' => '/shoot-history?source=receipt#paid',
        ])->assertOk()
            ->assertJsonPath('sessionId', 'cs_test_required_details')
            ->assertJsonPath('clientSecret', 'cs_secret_required_details');
    }

    public function test_checkout_rejects_an_incomplete_property_address_before_contacting_stripe(): void
    {
        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'city' => '',
            'total_quote' => 100,
            'payment_status' => 'unpaid',
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-embedded-checkout", [
            'amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('shoot.address');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_delayed_ambiguous_checkout_creation_retries_the_same_attempt_expiry_and_idempotency_key(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_checkout');
        config()->set('services.stripe.currency', 'USD');

        $client = User::factory()->create([
            'name' => 'Retry Client',
            'email' => 'retry@example.test',
            'metadata' => ['stripe_customer_id_test' => 'cus_retry'],
        ]);
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'address' => '10 Retry Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'total_quote' => 100,
            'payment_status' => 'unpaid',
        ]);

        $customerMock = Mockery::mock('alias:Stripe\\Customer');
        $customerMock->shouldReceive('retrieve')
            ->twice()
            ->with('cus_retry')
            ->andReturn((object) [
                'id' => 'cus_retry',
                'deleted' => false,
                'metadata' => (object) ['app_user_id' => (string) $client->id],
            ]);
        $customerMock->shouldReceive('update')->twice()->andReturn((object) ['id' => 'cus_retry']);

        $keys = [];
        $expirations = [];
        $calls = 0;
        $sessionMock = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $sessionMock->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function (array $params, array $options) use (&$keys, &$expirations, &$calls) {
                $keys[] = $options['idempotency_key'] ?? null;
                $expirations[] = $params['expires_at'] ?? null;
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('Simulated connection timeout after request dispatch.');
                }

                return (object) [
                    'id' => 'cs_test_retry_same_attempt',
                    'client_secret' => 'cs_secret_retry_same_attempt',
                    'status' => 'open',
                    'payment_status' => 'unpaid',
                    'expires_at' => $params['expires_at'],
                ];
            });

        Sanctum::actingAs($client);
        $endpoint = "/api/shoots/{$shoot->id}/create-stripe-embedded-checkout";
        $this->postJson($endpoint, ['amount' => 100])->assertStatus(500);
        $this->travel(5)->minutes();
        $this->postJson($endpoint, ['amount' => 100])
            ->assertOk()
            ->assertJsonPath('sessionId', 'cs_test_retry_same_attempt');

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($expirations[0], $expirations[1]);
        $this->assertGreaterThanOrEqual(now()->addMinutes(110)->timestamp, $expirations[1]);
        $this->assertDatabaseCount('stripe_checkout_attempts', 1);
        $this->assertDatabaseHas('stripe_checkout_attempts', [
            'stripe_session_id' => 'cs_test_retry_same_attempt',
            'status' => 'open',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_checkout_rejects_an_amount_that_became_stale_before_session_creation(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_checkout');
        config()->set('services.stripe.currency', 'USD');

        $client = User::factory()->create([
            'name' => 'Stale Balance Client',
            'email' => 'stale-balance@example.test',
            'metadata' => ['stripe_customer_id_test' => 'cus_stale_balance'],
        ]);
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'address' => '20 Race Condition Road',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'total_quote' => 100,
            'payment_status' => 'unpaid',
        ]);

        $customerMock = Mockery::mock('alias:Stripe\\Customer');
        $customerMock->shouldReceive('retrieve')
            ->once()
            ->with('cus_stale_balance')
            ->andReturn((object) [
                'id' => 'cus_stale_balance',
                'deleted' => false,
                'metadata' => (object) ['app_user_id' => (string) $client->id],
            ]);
        $customerMock->shouldReceive('update')
            ->once()
            ->andReturnUsing(function () use ($shoot) {
                // Simulate a previous paid Session finalizing after this request
                // calculated its amount but before it enters managed creation.
                Payment::factory()->create([
                    'shoot_id' => $shoot->id,
                    'amount' => 100,
                    'currency' => 'USD',
                    'payment_method' => 'stripe',
                    'stripe_payment_id' => 'pi_test_racing_payment',
                    'stripe_session_id' => 'cs_test_racing_payment',
                    'status' => Payment::STATUS_COMPLETED,
                    'processed_at' => now(),
                ]);

                return (object) ['id' => 'cus_stale_balance'];
            });

        $sessionMock = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $sessionMock->shouldReceive('create')->never();

        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-embedded-checkout", [
            'amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseCount('stripe_checkout_attempts', 0);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_paid_webhook_waits_while_checkout_amount_is_being_revalidated(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'total_quote' => 100,
            'payment_status' => 'unpaid',
        ]);

        $webhookMock = Mockery::mock('alias:Stripe\\Webhook');
        $webhookMock->shouldReceive('constructEvent')
            ->once()
            ->andReturn((object) [
                'id' => 'evt_test_checkout_lifecycle_busy',
                'type' => 'checkout.session.completed',
                'data' => (object) [
                    'object' => (object) [
                        'id' => 'cs_test_checkout_lifecycle_busy',
                        'mode' => 'payment',
                        'status' => 'complete',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_test_checkout_lifecycle_busy',
                        'amount_total' => 10000,
                        'currency' => 'usd',
                        'metadata' => (object) [
                            'type' => 'single',
                            'shoot_id' => (string) $shoot->id,
                        ],
                    ],
                ],
            ]);

        $lifecycleLock = Cache::lock('stripe_checkout_attempt_creation', 180);
        $this->assertTrue($lifecycleLock->get());

        try {
            $this->withHeader('Stripe-Signature', 'test_signature')
                ->postJson('/api/webhooks/stripe', [])
                ->assertStatus(500)
                ->assertJsonPath('status', 'retry')
                ->assertJsonPath('outcome', 'busy');
        } finally {
            $lifecycleLock->release();
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_client_cannot_create_or_confirm_checkout_for_another_clients_shoot(): void
    {
        $client = User::factory()->create();
        $otherClient = User::factory()->create();
        $shoot = Shoot::factory()->for($otherClient, 'client')->create([
            'payment_status' => 'unpaid',
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-embedded-checkout", [
            'amount' => 10,
        ])->assertForbidden();

        $this->postJson("/api/shoots/{$shoot->id}/confirm-stripe-session", [
            'session_id' => 'cs_test_other_client',
        ])->assertForbidden();
    }

    public function test_refund_requires_a_stable_operation_id_and_cent_precision(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_refund_validation',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'amount' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('refund_operation_id');

        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'amount' => 10.005,
            'refund_operation_id' => '1defeb75-a20b-4d31-bc91-5040d219cc2e',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_paid_attempt_is_fully_refunded_after_the_shoot_billing_client_changes(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.secret_key', 'sk_test_checkout');

        $originalClient = User::factory()->create();
        $newClient = User::factory()->create();
        $shoot = Shoot::factory()->for($newClient, 'client')->create([
            'total_quote' => 100,
            'payment_status' => 'unpaid',
        ]);
        $attempt = StripeCheckoutAttempt::create([
            'client_id' => $originalClient->id,
            'scope' => 'single_embedded',
            'ui_mode' => 'embedded',
            'expected_amount_cents' => 10000,
            'currency' => 'USD',
            'status' => StripeCheckoutAttempt::STATUS_OPEN,
            'request_fingerprint' => hash('sha256', 'reassigned-client'),
            'idempotency_key' => 'repro_checkout_reassigned_client',
            'stripe_session_id' => 'cs_test_reassigned_client',
            'expires_at' => now()->addHour(),
        ]);
        $attempt->items()->create([
            'shoot_id' => $shoot->id,
            'position' => 0,
            'expected_amount_cents' => 10000,
            'allocation_payload' => [],
        ]);

        $webhookMock = Mockery::mock('alias:Stripe\\Webhook');
        $webhookMock->shouldReceive('constructEvent')
            ->once()
            ->andReturn((object) [
                'id' => 'evt_test_reassigned_client',
                'type' => 'checkout.session.completed',
                'data' => (object) [
                    'object' => (object) [
                        'id' => 'cs_test_reassigned_client',
                        'mode' => 'payment',
                        'status' => 'complete',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_test_reassigned_client',
                        'amount_total' => 10000,
                        'currency' => 'usd',
                        'metadata' => (object) [
                            'type' => 'single',
                            'shoot_id' => (string) $shoot->id,
                            'checkout_attempt_id' => (string) $attempt->id,
                        ],
                    ],
                ],
            ]);

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('all')
            ->once()
            ->with(Mockery::on(fn (array $params) => ($params['payment_intent'] ?? null) === 'pi_test_reassigned_client'
                && ($params['limit'] ?? null) === 100))
            ->andReturn((object) ['data' => []]);
        $refundMock->shouldReceive('create')
            ->once()
            ->with(
                Mockery::on(fn (array $params) => ($params['payment_intent'] ?? null) === 'pi_test_reassigned_client'
                    && ($params['amount'] ?? null) === 10000
                    && str_starts_with(
                        (string) data_get($params, 'metadata.app_refund_operation_key', ''),
                        'checkout_stale_'
                    )),
                Mockery::on(fn (array $options) => str_starts_with(
                    (string) ($options['idempotency_key'] ?? ''),
                    'repro_checkout_stale_refund_'
                ))
            )
            ->andReturnUsing(fn (array $params) => (object) [
                'id' => 're_test_reassigned_client',
                'status' => 'succeeded',
                'payment_intent' => 'pi_test_reassigned_client',
                'amount' => 10000,
                'currency' => 'usd',
                'created' => now()->timestamp,
                'metadata' => (object) $params['metadata'],
            ]);

        $this->withHeader('Stripe-Signature', 'test_signature')
            ->postJson('/api/webhooks/stripe', [])
            ->assertOk()
            ->assertJsonPath('outcome', 'refunded_stale');

        $this->assertDatabaseHas('payments', [
            'stripe_session_id' => 'cs_test_reassigned_client',
            'status' => Payment::STATUS_REFUNDED,
        ]);
        $this->assertDatabaseHas('payment_refunds', [
            'provider_refund_id' => 're_test_reassigned_client',
            'status' => 'succeeded',
            'amount' => 100,
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_paid_session_is_fully_refunded_when_an_offline_payment_reduces_the_open_balance(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.secret_key', 'sk_test_checkout');

        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'total_quote' => 100,
            'payment_status' => 'partial',
        ]);
        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 40,
            'payment_method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $session = (object) [
            'id' => 'cs_test_stale_balance',
            'mode' => 'payment',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_stale_balance',
            'amount_total' => 10000,
            'currency' => 'usd',
            'metadata' => (object) [
                'type' => 'single',
                'shoot_id' => (string) $shoot->id,
                'client_id' => (string) $client->id,
            ],
        ];

        $webhookMock = Mockery::mock('alias:Stripe\\Webhook');
        $webhookMock->shouldReceive('constructEvent')
            ->once()
            ->andReturn((object) [
                'id' => 'evt_test_stale_balance',
                'type' => 'checkout.session.completed',
                'data' => (object) ['object' => $session],
            ]);

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('all')
            ->once()
            ->andReturn((object) ['data' => []]);
        $refundMock->shouldReceive('create')
            ->once()
            ->andReturnUsing(fn (array $params) => (object) [
                'id' => 're_test_stale_balance',
                'status' => 'succeeded',
                'payment_intent' => 'pi_test_stale_balance',
                'amount' => 10000,
                'currency' => 'usd',
                'created' => now()->timestamp,
                'metadata' => (object) $params['metadata'],
            ]);

        $this->withHeader('Stripe-Signature', 'test_signature')
            ->postJson('/api/webhooks/stripe', [])
            ->assertOk()
            ->assertJsonPath('outcome', 'refunded_stale');

        $stripePayment = Payment::query()
            ->where('stripe_session_id', 'cs_test_stale_balance')
            ->firstOrFail();
        $this->assertSame(Payment::STATUS_REFUNDED, $stripePayment->status);
        $this->assertSame(40.0, $shoot->fresh(['payments.refunds'])->calculateCanonicalTotalPaid());
    }

    public function test_multi_checkout_rejects_mixed_billing_clients(): void
    {
        $admin = User::factory()->admin()->create();
        $shootA = Shoot::factory()->for(User::factory()->create(), 'client')->create([
            'payment_status' => 'unpaid',
        ]);
        $shootB = Shoot::factory()->for(User::factory()->create(), 'client')->create([
            'payment_status' => 'unpaid',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/payments/stripe-multiple-shoots-embedded', [
            'shoot_ids' => [$shootA->id, $shootB->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('shoot_ids');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_unpaid_confirmation_has_an_unambiguous_non_success_outcome(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_checkout');

        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'total_quote' => 125,
            'payment_status' => 'unpaid',
        ]);

        $sessionMock = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $sessionMock->shouldReceive('retrieve')
            ->once()
            ->with('cs_test_unpaid')
            ->andReturn((object) [
                'id' => 'cs_test_unpaid',
                'payment_status' => 'unpaid',
                'payment_intent' => 'pi_test_unpaid',
                'amount_total' => 12500,
                'metadata' => (object) [
                    'type' => 'single',
                    'shoot_id' => (string) $shoot->id,
                ],
            ]);

        Sanctum::actingAs($client);

        $this->postJson("/api/shoots/{$shoot->id}/confirm-stripe-session", [
            'session_id' => 'cs_test_unpaid',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'unpaid')
            ->assertJsonPath('data.session_payment_status', 'unpaid')
            ->assertJsonPath('data.payment_recorded', false)
            ->assertJsonPath('data.reconciled', false)
            ->assertJsonPath('data.last_payment_amount', null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_public_confirmation_still_works_after_paid_webhook_revokes_the_link(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_checkout');

        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'address' => '44 Receipt Return Road',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'total_quote' => 150,
            'payment_status' => 'partial',
        ]);
        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount' => 100,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_revoked_return',
            'stripe_session_id' => 'cs_test_revoked_return',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $token = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id,
            'expires_at' => now()->addDay(),
            'revoked_at' => now(),
        ]);

        $sessionMock = Mockery::mock('alias:Stripe\\Checkout\\Session');
        $sessionMock->shouldReceive('retrieve')
            ->once()
            ->with('cs_test_revoked_return')
            ->andReturn((object) [
                'id' => 'cs_test_revoked_return',
                'mode' => 'payment',
                'status' => 'complete',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_revoked_return',
                'amount_total' => 10000,
                'currency' => 'usd',
                'metadata' => (object) [
                    'type' => 'single',
                    'shoot_id' => (string) $shoot->id,
                ],
            ]);

        $this->postJson("/api/public/payments/{$token->token}/confirm", [
            'session_id' => 'cs_test_revoked_return',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'already_processed')
            ->assertJsonPath('data.session_payment_status', 'paid')
            ->assertJsonPath('data.payment_recorded', true)
            ->assertJsonPath('data.remaining_balance', 50)
            ->assertJsonPath('data.shoot.address', '44 Receipt Return Road')
            ->assertJsonPath('data.shoot.amount_due', 50);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_webhook_returns_retryable_error_when_paid_session_cannot_be_recorded(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $webhookMock = Mockery::mock('alias:Stripe\\Webhook');
        $webhookMock->shouldReceive('constructEvent')
            ->once()
            ->andReturn((object) [
                'id' => 'evt_test_invalid_session',
                'type' => 'checkout.session.completed',
                'data' => (object) [
                    'object' => (object) [
                        'id' => 'cs_test_invalid_session',
                        'mode' => 'payment',
                        'status' => 'complete',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_test_invalid_session',
                        'amount_total' => 1000,
                        'currency' => 'usd',
                        'metadata' => (object) ['type' => 'single'],
                    ],
                ],
            ]);

        $this->withHeader('Stripe-Signature', 'test_signature')
            ->postJson('/api/webhooks/stripe', [])
            ->assertStatus(500)
            ->assertJsonPath('status', 'retry')
            ->assertJsonPath('outcome', 'failed');

        $this->assertDatabaseCount('payments', 0);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_dashboard_refund_for_multi_shoot_payment_is_allocated_across_local_payments(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $client = User::factory()->create();
        $firstShoot = Shoot::factory()->for($client, 'client')->create();
        $secondShoot = Shoot::factory()->for($client, 'client')->create();
        $firstPayment = Payment::factory()->create([
            'shoot_id' => $firstShoot->id,
            'amount' => 100,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_grouped',
            'stripe_session_id' => 'cs_test_grouped_shoot_'.$firstShoot->id,
            'status' => Payment::STATUS_COMPLETED,
        ]);
        $secondPayment = Payment::factory()->create([
            'shoot_id' => $secondShoot->id,
            'amount' => 100,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_grouped',
            'stripe_session_id' => 'cs_test_grouped_shoot_'.$secondShoot->id,
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $webhookMock = Mockery::mock('alias:Stripe\\Webhook');
        $webhookMock->shouldReceive('constructEvent')
            ->once()
            ->andReturn((object) [
                'id' => 'evt_test_grouped_refund',
                'type' => 'refund.created',
                'data' => (object) [
                    'object' => (object) [
                        'id' => 're_test_grouped',
                        'payment_intent' => 'pi_test_grouped',
                        'status' => 'pending',
                        'amount' => 15000,
                        'currency' => 'usd',
                        'created' => now()->timestamp,
                        'metadata' => (object) [],
                    ],
                ],
            ]);

        $this->withHeader('Stripe-Signature', 'test_signature')
            ->postJson('/api/webhooks/stripe', [])
            ->assertOk()
            ->assertJsonPath('outcome', 'refund_synced');

        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $firstPayment->id,
            'provider_refund_id' => 're_test_grouped',
            'amount' => 100,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $secondPayment->id,
            'provider_refund_id' => 're_test_grouped',
            'amount' => 50,
            'status' => 'pending',
        ]);
    }

    public function test_non_admin_cannot_refund_a_stripe_payment(): void
    {
        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_forbidden_refund',
            'status' => Payment::STATUS_COMPLETED,
        ]);
        Sanctum::actingAs($client);

        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
        ])->assertForbidden();
    }
}
