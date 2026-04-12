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
use Tests\TestCase;

class StripeRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_process_a_full_stripe_refund(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_123');

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
            ->with(Mockery::on(function (array $params) use ($payment) {
                return ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                    && !array_key_exists('amount', $params);
            }))
            ->andReturn((object) [
                'id' => 're_test_123',
                'status' => 'succeeded',
            ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/payments/stripe-refund', [
            'payment_id' => $payment->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('refund.status', 'succeeded');

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'payment_refunded',
        ]);
    }
}
