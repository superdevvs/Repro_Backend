<?php

namespace Tests\Feature;

use App\Http\Controllers\StripePaymentController;
use App\Jobs\DispatchPaymentReceipt;
use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Payments\StripePaymentMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class PaymentAutomationReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AutomationRule::query()->update(['is_active' => false]);
        Queue::fake([DispatchPaymentReceipt::class]);
        $this->app->instance(MailService::class, Mockery::mock(MailService::class));
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'subject' => $payload['subject'],
            'body_html' => $payload['body_html'], 'body_text' => $payload['body_text'],
            'tags_json' => $payload['tags_json'], 'related_shoot_id' => $payload['related_shoot_id'] ?? null,
        ]));
        $this->app->instance(MessagingService::class, $messaging);
        $metadata = Mockery::mock(StripePaymentMetadataService::class)->makePartial();
        $metadata->shouldReceive('hydratePaymentRecord')->andReturnUsing(fn (Payment $payment) => $payment);
        $metadata->shouldReceive('buildActivityMetadata')->andReturn([]);
        $this->app->instance(StripePaymentMetadataService::class, $metadata);
    }

    public function test_stripe_partial_and_final_payments_queue_saved_receipts_with_distinct_identities(): void
    {
        $this->rule('PAYMENT_COMPLETED');
        $shoot = $this->shoot();
        $controller = app(StripePaymentController::class);
        $method = new \ReflectionMethod($controller, 'updateShootPaymentStatus');
        $first = $this->payment($shoot, 50, 'pi_first');
        $method->invoke($controller, $shoot, $first, 50);
        Queue::assertPushed(DispatchPaymentReceipt::class, fn ($job) => $job->paymentIds === [$first->id] && $job->afterCommit === true);
        $this->assertSame(0, Message::count(), 'Receipt delivery must leave the payment request transaction.');
        $this->deliverQueuedReceipts();
        $this->assertSame('partial', $shoot->fresh()->payment_status);
        $this->assertStringContainsString('Saved receipt $50.00 / pi_first / balance 50.00', Message::firstOrFail()->body_text);

        $second = $this->payment($shoot, 50, 'pi_second');
        $method->invoke($controller, $shoot->fresh(), $second, 50);
        $this->deliverQueuedReceipts();
        $this->assertSame('paid', $shoot->fresh()->payment_status);
        $this->assertSame(2, Message::count(), 'A repeated job must not duplicate the first equal-value receipt.');
        $this->assertStringContainsString('Saved receipt $50.00 / pi_second / balance 0.00', Message::latest('id')->first()->body_text);
    }

    public function test_disabling_the_receipt_rule_before_delivery_suppresses_the_saved_and_legacy_receipts(): void
    {
        $rule = $this->rule('PAYMENT_COMPLETED');
        $shoot = $this->shoot();
        $payment = $this->payment($shoot, 25, 'pi_disabled');
        $method = new \ReflectionMethod(StripePaymentController::class, 'updateShootPaymentStatus');
        $method->invoke(app(StripePaymentController::class), $shoot, $payment, 25);
        $rule->update(['is_active' => false]);
        $this->deliverQueuedReceipts();
        $this->assertSame(0, Message::count());
        $this->assertSame('partial', $shoot->fresh()->payment_status);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_grouped_stripe_checkout_queues_one_saved_receipt_with_total_and_all_properties(): void
    {
        config()->set('services.stripe.secret_key', 'sk_test_receipt');
        $this->rule('PAYMENT_COMPLETED');
        $first = $this->shoot();
        $second = Shoot::factory()->create(['client_id' => $first->client_id, 'address' => '202 Second Lane', 'total_quote' => 75, 'payment_status' => 'unpaid']);
        $session = (object) ['id' => 'cs_grouped_receipt', 'payment_intent' => 'pi_grouped_receipt',
            'amount_total' => 17500, 'currency' => 'usd', 'metadata' => (object) ['shoot_ids' => $first->id.','.$second->id, 'client_id' => (string) $first->client_id]];
        Mockery::mock('alias:Stripe\\Checkout\\Session')->shouldReceive('allLineItems')
            ->once()->with($session->id, ['limit' => 100])->andReturn((object) ['data' => [(object) ['amount_total' => 10000], (object) ['amount_total' => 7500]]]);
        $method = new \ReflectionMethod(StripePaymentController::class, 'processMultipleShootPayment');
        $this->assertTrue($method->invoke(app(StripePaymentController::class), $session));
        Queue::assertPushed(DispatchPaymentReceipt::class, 1);
        $this->assertSame(2, Payment::count());
        $this->deliverQueuedReceipts();
        $this->deliverQueuedReceipts();
        $this->assertSame(1, Message::count());
        $body = Message::firstOrFail()->body_text;
        $this->assertStringContainsString('Saved receipt $175.00 / pi_grouped_receipt', $body);
        $this->assertStringContainsString('101 Receipt Road: $100.00', $body);
        $this->assertStringContainsString('202 Second Lane: $75.00', $body);
    }

    public function test_confirmed_offline_payment_routes_through_saved_receipt(): void
    {
        $this->rule('PAYMENT_COMPLETED');
        $shoot = $this->shoot();
        $payment = $this->payment($shoot, 25, null, ['status' => Payment::STATUS_PENDING, 'payment_method' => 'cash']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/shoots/'.$shoot->id.'/payment-intents/'.$payment->id.'/confirm')->assertOk();
        Queue::assertPushed(DispatchPaymentReceipt::class, fn ($job) => $job->paymentIds === [$payment->id]);
        $this->deliverQueuedReceipts();
        $this->assertSame(1, Message::count());
        $this->assertStringContainsString('Saved receipt $25.00 / '.$payment->id, Message::first()->body_text);
    }

    public function test_invoice_payment_routes_each_partial_transaction_through_saved_receipt(): void
    {
        $this->rule('PAYMENT_COMPLETED');
        $shoot = $this->shoot();
        $invoice = Invoice::create(['shoot_id' => $shoot->id, 'client_id' => $shoot->client_id, 'user_id' => $shoot->client_id,
            'role' => Invoice::ROLE_CLIENT, 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'total' => 100, 'total_amount' => 100, 'amount_paid' => 0, 'status' => Invoice::STATUS_SENT]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/invoices/'.$invoice->id.'/mark-paid', ['amount_paid' => 25, 'payment_method' => 'cash'])->assertOk();
        $this->deliverQueuedReceipts();
        $this->assertSame(1, Message::count());
        $this->assertStringContainsString('Saved receipt $25.00', Message::first()->body_text);
    }

    public function test_distinct_equal_value_refunds_notify_once_each_using_provider_identity(): void
    {
        $this->rule('PAYMENT_REFUNDED');
        $shoot = $this->shoot();
        $payment = $this->payment($shoot, 100, 'pi_refund');
        $service = app(AutomationService::class);
        $context = array_merge($service->buildShootContext($shoot), ['payment' => $payment, 'payment_id' => $payment->id, 'refund_amount' => '25.00']);
        foreach (['re_one', 're_two', 're_one'] as $refundId) {
            $service->handleEvent('PAYMENT_REFUNDED', array_merge($context, ['refund_id' => $refundId]));
        }
        $this->assertSame(2, Message::count());
        $this->assertStringContainsString('re_one', Message::first()->body_text);
        $this->assertStringContainsString('re_two', Message::latest('id')->first()->body_text);
    }

    public function test_legacy_receipt_fallback_only_applies_when_no_rule_exists(): void
    {
        AutomationRule::where('trigger_type', 'PAYMENT_COMPLETED')->delete();
        $shoot = $this->shoot();
        $payment = $this->payment($shoot, 25, 'pi_legacy');
        app(MailService::class)->shouldReceive('sendPaymentConfirmationEmail')->once()
            ->with(Mockery::type(User::class), Mockery::type(Shoot::class), Mockery::on(fn ($value) => $value->id === $payment->id))->andReturnTrue();
        (new DispatchPaymentReceipt([$payment->id]))->handle(app(AutomationService::class));
        $this->assertSame(0, Message::count());
    }

    public function test_refund_before_worker_delivery_does_not_erase_the_accepted_payment_receipt(): void
    {
        $this->rule('PAYMENT_COMPLETED');
        $shoot = $this->shoot();
        $payment = $this->payment($shoot, 100, 'pi_refunded_before_delivery');
        app(AutomationService::class)->queueAcceptedPaymentReceipt([$payment]);
        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        $this->deliverQueuedReceipts();
        $this->assertSame(1, Message::count());
        $this->assertStringContainsString('Saved receipt $100.00 / pi_refunded_before_delivery', Message::first()->body_text);
    }

    public function test_reschedule_sends_one_update_to_client_and_photographer_without_new_booking_notice(): void
    {
        app(MailService::class)->shouldReceive('captureShootSnapshot')->once()->andReturn([]);
        app(MailService::class)->shouldReceive('buildShootChangeSummary')->once()->andReturn([
            'summary' => 'Schedule: moved to the requested date', 'html' => '<p>Schedule: moved to the requested date</p>',
        ]);
        $this->rule('SHOOT_SCHEDULED', ['client', 'photographer']);
        $this->rule('SHOOT_UPDATED', ['client', 'photographer']);
        $shoot = $this->shoot();
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot->update(['photographer_id' => $photographer->id, 'scheduled_date' => now()->addDay()->toDateString(), 'time' => '10:00 AM']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/shoots/'.$shoot->id.'/reschedule', ['requested_date' => now()->addDays(3)->toDateString(), 'requested_time' => '02:00 PM', 'reason' => 'Owner request'])->assertCreated();
        $this->assertSame(2, Message::count());
        $this->assertEqualsCanonicalizing([$shoot->client->email, $photographer->email], Message::pluck('to_address')->all());
        $this->assertSame(['Saved SHOOT_UPDATED'], Message::pluck('subject')->unique()->values()->all());
    }

    private function deliverQueuedReceipts(): void
    {
        Queue::pushed(DispatchPaymentReceipt::class)->each(fn ($job) => $job->handle(app(AutomationService::class)));
    }

    private function shoot(): Shoot
    {
        return Shoot::factory()->create(['client_id' => User::factory()->create(['role' => 'client'])->id,
            'address' => '101 Receipt Road', 'total_quote' => 100, 'payment_status' => 'unpaid']);
    }

    private function payment(Shoot $shoot, float $amount, ?string $reference, array $attributes = []): Payment
    {
        return Payment::create(array_merge(['shoot_id' => $shoot->id, 'amount' => $amount, 'currency' => 'USD',
            'payment_method' => 'stripe', 'stripe_payment_id' => $reference, 'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now()], $attributes));
    }

    private function rule(string $trigger, array $roles = ['client']): AutomationRule
    {
        $template = MessageTemplate::create(['name' => 'Saved '.$trigger, 'channel' => 'EMAIL', 'scope' => 'SYSTEM', 'is_active' => true,
            'subject' => 'Saved '.$trigger, 'body_text' => 'Saved receipt {{payment_amount}} / {{payment_reference}} / balance {{remaining_balance}} / {{payment_items}} / {{refund_id}}',
            'variables_json' => ['payment_amount', 'payment_reference', 'remaining_balance', 'payment_items', 'refund_id']]);

        return AutomationRule::create(['name' => 'Configured '.$trigger, 'trigger_type' => $trigger, 'scope' => 'SYSTEM',
            'is_active' => true, 'template_id' => $template->id, 'recipients_json' => $roles]);
    }
}
