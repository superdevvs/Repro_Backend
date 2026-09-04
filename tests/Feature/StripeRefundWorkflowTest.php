<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;

class StripeRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Runs in a separate process so the `alias:Stripe\Refund` mock loads into a clean
     * process. Without isolation the alias collides ("class Stripe\Refund already exists")
     * when an earlier test in the same process has already autoloaded the Stripe SDK class.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_admin_can_process_a_full_stripe_refund(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');
        $refundOperationId = '04dff8a1-1807-4fe0-ac40-a61499dd9741';

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_123',
            'square_payment_id' => null,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 250.00,
        ]);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->with(Mockery::type(Shoot::class))
            ->andReturn([]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->with('PAYMENT_REFUNDED', Mockery::type('array'));
        $this->app->instance(AutomationService::class, $automationService);

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params) use ($payment, $refundOperationId) {
                // Current controller contract: a full refund sends the explicit amount
                // (in cents) alongside the payment_intent. ($250.00 -> 25000)
                return ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                    && ($params['amount'] ?? null) === (int) round(((float) $payment->amount) * 100)
                    && data_get($params, 'metadata.app_payment_id') === (string) $payment->id
                    && data_get($params, 'metadata.app_refund_operation_key') === $refundOperationId;
            }), Mockery::on(fn (array $options) => ($options['idempotency_key'] ?? null) ===
                'repro_refund_'.hash('sha256', $payment->id.':'.$refundOperationId)))
            ->andReturn((object) [
                'id' => 're_test_123',
                'status' => 'succeeded',
                'payment_intent' => $payment->stripe_payment_id,
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => 'usd',
                'created' => now()->timestamp,
                'metadata' => (object) [
                    'app_payment_id' => (string) $payment->id,
                    'shoot_id' => (string) $shoot->id,
                    'app_created_by' => (string) $admin->id,
                    'app_refund_operation_key' => $refundOperationId,
                ],
            ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'refund_operation_id' => $refundOperationId,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('refund.status', 'succeeded');
        $response->assertJsonPath('refund_operation_id', $refundOperationId);

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'payment_refunded',
        ]);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'provider_refund_id' => 're_test_123',
            'operation_key' => $refundOperationId,
            'status' => 'succeeded',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_ambiguous_refund_retry_reconciles_provider_before_reissuing(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');
        $refundOperationId = '4b34261d-e256-4d5c-b8be-b5993d6fb14e';

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_retry_refund',
            'square_payment_id' => null,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 100.00,
        ]);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->with(Mockery::type(Shoot::class))
            ->andReturn([]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->with('PAYMENT_REFUNDED', Mockery::type('array'));
        $this->app->instance(AutomationService::class, $automationService);

        $providerRefund = (object) [
            'id' => 're_test_retry_refund',
            'status' => 'succeeded',
            'payment_intent' => $payment->stripe_payment_id,
            'amount' => 10000,
            'currency' => 'usd',
            'created' => now()->timestamp,
            'metadata' => (object) [
                'app_payment_id' => (string) $payment->id,
                'shoot_id' => (string) $shoot->id,
                'app_created_by' => (string) $admin->id,
                'app_refund_operation_key' => $refundOperationId,
            ],
        ];
        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('create')
            ->once()
            ->with(
                Mockery::on(fn (array $params) => ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                    && ($params['amount'] ?? null) === 10000
                    && data_get($params, 'metadata.app_refund_operation_key') === $refundOperationId),
                Mockery::on(fn (array $options) => ($options['idempotency_key'] ?? null) ===
                    'repro_refund_'.hash('sha256', $payment->id.':'.$refundOperationId))
            )
            ->andThrow(new \RuntimeException('Simulated connection loss after request submission.'));
        $refundMock->shouldReceive('all')
            ->once()
            ->with(Mockery::on(fn (array $params) => ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                && ($params['limit'] ?? null) === 100
                && is_numeric(data_get($params, 'created.gte'))))
            ->andReturn((object) ['data' => [$providerRefund]]);

        Sanctum::actingAs($admin);
        $request = [
            'payment_id' => $payment->id,
            'amount' => 100.00,
            'refund_operation_id' => $refundOperationId,
        ];

        $this->postJson('/api/payments/stripe-refund', $request)
            ->assertInternalServerError();

        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'operation_key' => $refundOperationId,
            'provider_refund_id' => null,
            'status' => 'creating',
        ]);

        $this->postJson('/api/payments/stripe-refund', $request)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('refund.id', 're_test_retry_refund');

        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'operation_key' => $refundOperationId,
            'provider_refund_id' => 're_test_retry_refund',
            'status' => 'succeeded',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_stale_unresolved_refund_is_reconciled_then_released_without_reusing_an_expired_key(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');
        $operationId = '63c17766-78b6-4ed0-a555-674f441c468e';
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_stale_refund',
            'square_payment_id' => null,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 100.00,
        ]);
        $operation = $payment->refunds()->create([
            'shoot_id' => $shoot->id,
            'amount' => 50.00,
            'provider' => 'stripe',
            'operation_key' => $operationId,
            'status' => 'creating',
            'created_by' => $admin->id,
        ]);
        $operation->timestamps = false;
        $operation->forceFill([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ])->save();

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('all')
            ->once()
            ->with(Mockery::on(fn (array $params) => ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                && ($params['limit'] ?? null) === 100))
            ->andReturn((object) ['data' => []]);
        $refundMock->shouldNotReceive('create');

        Sanctum::actingAs($admin);
        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'amount' => 50,
            'refund_operation_id' => $operationId,
        ])->assertConflict()
            ->assertJsonPath('refund_status', 'failed')
            ->assertJsonPath('refund_operation_id', $operationId);

        $this->assertDatabaseHas('payment_refunds', [
            'id' => $operation->id,
            'provider_refund_id' => null,
            'status' => 'failed',
        ]);
        $this->assertSame(100.0, $payment->fresh(['refunds'])->refundableRemainder());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_failed_provider_reconciliation_does_not_issue_another_refund(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');
        $operationId = 'b89184e8-356c-4269-a28d-6cfb7d16f769';
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_reconciliation_failure',
            'square_payment_id' => null,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 100.00,
        ]);
        $operation = $payment->refunds()->create([
            'shoot_id' => $shoot->id,
            'amount' => 50.00,
            'provider' => 'stripe',
            'operation_key' => $operationId,
            'status' => 'creating',
            'created_by' => $admin->id,
        ]);

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('all')
            ->once()
            ->andThrow(new \RuntimeException('Stripe refund listing is temporarily unavailable.'));
        $refundMock->shouldNotReceive('create');

        Sanctum::actingAs($admin);
        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'amount' => 50,
            'refund_operation_id' => $operationId,
        ])->assertInternalServerError();

        $this->assertDatabaseHas('payment_refunds', [
            'id' => $operation->id,
            'provider_refund_id' => null,
            'status' => 'creating',
        ]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_definitive_stripe_rejection_releases_the_refund_reservation(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');
        $operationId = '43fb15e0-a172-44e2-987f-5d2f5c929d95';
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_test_rejected_refund',
            'square_payment_id' => null,
            'status' => Payment::STATUS_COMPLETED,
            'amount' => 100.00,
        ]);

        $refundMock = Mockery::mock('alias:Stripe\\Refund');
        $refundMock->shouldReceive('create')
            ->once()
            ->andThrow(InvalidRequestException::factory(
                'Refund amount exceeds the remaining charge amount.',
                400,
                null,
                null,
                null,
                'charge_already_refunded'
            ));

        Sanctum::actingAs($admin);
        $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
            'amount' => 100,
            'refund_operation_id' => $operationId,
        ])->assertUnprocessable()
            ->assertJsonPath('refund_status', 'failed')
            ->assertJsonPath('refund_operation_id', $operationId);

        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => $payment->id,
            'operation_key' => $operationId,
            'status' => 'failed',
        ]);
        $this->assertSame(0.0, $payment->fresh(['refunds'])->refundedAmount());
        $this->assertSame(100.0, $payment->fresh(['refunds'])->refundableRemainder());
    }
}
