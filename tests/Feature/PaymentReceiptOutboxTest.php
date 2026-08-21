<?php

namespace Tests\Feature;

use App\Jobs\SendSystemEmailDispatchJob;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\SystemEmails\SystemEmailDispatcher;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentReceiptOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_receipt_is_persisted_once_and_queued_after_request(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'total_quote' => 100,
            'payment_status' => 'partial',
        ]);
        $payment = Payment::query()->create([
            'shoot_id' => $shoot->id,
            'amount' => 50,
            'currency' => 'USD',
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_outbox_once',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        $mail = app(MailService::class);
        $this->assertTrue($mail->sendPaymentConfirmationEmail($client, $shoot, $payment));
        $this->assertTrue($mail->sendPaymentConfirmationEmail($client, $shoot, $payment));

        $this->assertSame(1, SystemEmailDispatch::query()->where('email_alias', 'PAYMENT_CONFIRMATION')->count());
        $this->assertDatabaseHas('system_email_dispatches', [
            'idempotency_key' => "PAYMENT_CONFIRMATION:client:{$client->id}:provider:stripe:pi_outbox_once",
            'status' => 'pending',
            'delivery_mode' => 'async',
        ]);
        Queue::assertPushed(SendSystemEmailDispatchJob::class, 1);
    }

    public function test_equal_value_manual_payments_use_payment_row_identity(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'total_quote' => 100]);
        $payments = collect([1, 2])->map(fn () => Payment::query()->create([
            'shoot_id' => $shoot->id,
            'amount' => 25,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]));

        $mail = app(MailService::class);
        $payments->each(fn (Payment $payment) => $this->assertTrue($mail->sendPaymentConfirmationEmail($client, $shoot, $payment)));

        $this->assertSame(2, SystemEmailDispatch::query()->where('email_alias', 'PAYMENT_CONFIRMATION')->count());
    }

    public function test_uncertain_provider_outcome_is_quarantined_instead_of_blindly_resent(): void
    {
        Queue::fake();
        $dispatcher = $this->mock(SystemEmailDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Provider connection ended before acknowledgement.'));

        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'total_quote' => 100]);
        $payment = Payment::query()->create([
            'shoot_id' => $shoot->id,
            'amount' => 25,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        app(MailService::class)->sendPaymentConfirmationEmail($client, $shoot, $payment);
        $dispatch = SystemEmailDispatch::query()->where('email_alias', 'PAYMENT_CONFIRMATION')->firstOrFail();
        app(SystemEmailOrchestrator::class)->processQueued($dispatch);

        $dispatch->refresh();
        $this->assertSame('processing', $dispatch->status);
        $this->assertTrue((bool) data_get($dispatch->metadata, 'reconciliation_required'));
        $this->assertNull($dispatch->failed_at);
    }

    public function test_multi_shoot_provider_transaction_queues_one_itemized_receipt(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => 'client']);
        $shoots = collect([
            Shoot::factory()->create(['client_id' => $client->id, 'address' => '10 First Ave', 'total_quote' => 75]),
            Shoot::factory()->create(['client_id' => $client->id, 'address' => '20 Second Ave', 'total_quote' => 125]),
        ]);
        $payments = $shoots->map(fn (Shoot $shoot, int $index) => Payment::query()->create([
            'shoot_id' => $shoot->id,
            'amount' => $index === 0 ? 75 : 100,
            'currency' => 'USD',
            'payment_method' => 'stripe',
            'stripe_payment_id' => 'pi_grouped_once',
            'stripe_session_id' => 'cs_grouped_'.$shoot->id,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now(),
        ]));

        $mail = app(MailService::class);
        $this->assertTrue($mail->sendGroupedPaymentConfirmationEmail($client, $payments));
        $this->assertTrue($mail->sendPaymentConfirmationEmail($client, $shoots->first(), $payments->first()));

        $dispatch = SystemEmailDispatch::query()->where('email_alias', 'PAYMENT_CONFIRMATION')->sole();
        $this->assertCount(2, data_get($dispatch->payload_snapshot, 'payment.items'));
        $this->assertSame(175.0, (float) data_get($dispatch->payload_snapshot, 'payment.amount'));
        $this->assertStringStartsWith('10 First Ave', (string) data_get($dispatch->payload_snapshot, 'payment.items.0.address'));
        $this->assertStringStartsWith('20 Second Ave', (string) data_get($dispatch->payload_snapshot, 'payment.items.1.address'));
        Queue::assertPushed(SendSystemEmailDispatchJob::class, 1);
    }
}
