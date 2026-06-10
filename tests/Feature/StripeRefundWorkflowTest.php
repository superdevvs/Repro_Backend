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
                // Current controller contract: a full refund sends the explicit amount
                // (in cents) alongside the payment_intent. ($250.00 -> 25000)
                return ($params['payment_intent'] ?? null) === $payment->stripe_payment_id
                    && ($params['amount'] ?? null) === (int) round(((float) $payment->amount) * 100);
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
