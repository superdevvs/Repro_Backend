<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioRefundDispatcher;
use App\Models\ListingStudioCheckoutAttempt as Checkout;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioRefundAttempt as Refund;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StudioRefundService implements ListingStudioRefundDispatcher
{
    public function __construct(private readonly StudioStripeGateway $gateway, private readonly StudioCheckoutService $checkout) {}

    public function dispatch(ListingStudioOutbox $event): void
    {
        if ($event->type !== 'refund_order') {
            throw new \LogicException('Unsupported Studio financial intent.');
        }
        $orderId = (string) data_get($event->payload, 'orderId');
        $lock = Cache::store('database')->lock('studio-order-refund:'.$orderId, 180);
        if (! $lock->get()) {
            throw new StudioPaymentException('awaiting_reconciliation', 'Refund reconciliation is already running.');
        }
        try {
            $this->checkout->verifyAccount();
            $attempt = $this->write(function () use ($event, $orderId) {
                $prior = Refund::where('operation_id', $event->id)->first();
                if ($prior) {
                    return $prior;
                }
                $order = ListingStudioOrder::findOrFail($orderId);
                $payment = Checkout::where('order_id', $orderId)->where('status', 'paid')->sole();
                $this->checkout->assertIdentity($payment);
                $amount = $order->paid_amount_cents - $order->refunded_amount_cents;
                if ($order->funding_method !== 'card' || $order->funding_status !== 'refund_pending' || $amount <= 0
                    || ! $payment->payment_intent_id || Refund::where('order_id', $orderId)->whereIn('status', ['pending', 'submitting', 'awaiting_reconciliation', 'requires_action'])->exists()) {
                    throw new StudioPaymentException('refund_state_conflict', 'Refund requires review.');
                }

                return Refund::create(['order_id' => $orderId, 'operation_id' => $event->id, 'checkout_attempt_id' => $payment->id,
                    'account_id' => $payment->account_id, 'livemode' => $payment->livemode, 'payment_intent_id' => $payment->payment_intent_id,
                    'currency' => $payment->currency, 'amount_cents' => $amount, 'status' => 'pending']);
            });
            $this->checkout->assertIdentity($attempt);
            if ($attempt->refund_id) {
                $this->reconcile($attempt, $this->gateway->refund($attempt->refund_id));

                return;
            }
            $this->checkout->assertRetryWindow($attempt->submission_started_at);
            if (! $attempt->parameters) {
                $parameters = ['payment_intent' => $attempt->payment_intent_id, 'amount' => $attempt->amount_cents,
                    'metadata' => ['studio_order_id' => $attempt->order_id, 'studio_refund_attempt' => $attempt->id, 'studio_refund_operation' => $attempt->operation_id]];
                $this->write(fn () => $attempt->update(['parameters' => $parameters, 'submission_started_at' => now(), 'status' => 'submitting']));
            }
            try {
                $remote = $this->gateway->createRefund($attempt->parameters, 'studio-refund-'.$attempt->id);
                if (! preg_match('/^re_[A-Za-z0-9_]+$/', $this->id($remote)) || $this->id($remote['payment_intent'] ?? null) !== $attempt->payment_intent_id
                    || ($remote['amount'] ?? null) !== $attempt->amount_cents || data_get($remote, 'metadata.studio_refund_attempt') !== $attempt->id) {
                    throw new StudioPaymentException('billing_attention', 'Refund evidence did not match the operation.');
                }
                $this->write(fn () => $attempt->update(['refund_id' => $remote['id']]));
                $this->reconcile($attempt, $this->gateway->refund($attempt->refund_id));
            } catch (\Throwable $exception) {
                $this->write(fn () => Refund::whereKey($attempt->id)->whereNull('refund_id')->update(['status' => 'awaiting_reconciliation']));
                throw new StudioPaymentException('awaiting_reconciliation', 'Refund is awaiting provider confirmation.');
            }
        } finally {
            $lock->release();
        }
    }

    public function reconcileExternal(Checkout $payment, string $refundId, ?string $eventId): void
    {
        $this->checkout->assertIdentity($payment);
        if (! preg_match('/^re_[A-Za-z0-9_]+$/', $refundId)) {
            throw new StudioPaymentException('billing_attention', 'Invalid refund identity.');
        }
        $lock = Cache::store('database')->lock('studio-order-refund:'.$payment->order_id, 180);
        if (! $lock->get()) {
            throw new StudioPaymentException('awaiting_reconciliation', 'Refund reconciliation is already running.');
        }
        try {
            $remote = $this->gateway->refund($refundId);
            $this->validate($payment, $remote, $refundId);
            $attempt = $this->write(function () use ($payment, $remote, $refundId) {
                $prior = Refund::where(['account_id' => $payment->account_id, 'livemode' => $payment->livemode, 'refund_id' => $refundId])->first();
                if ($prior) {
                    return $prior;
                }
                $localId = data_get($remote, 'metadata.studio_refund_attempt');
                if ($localId) {
                    $prior = Refund::findOrFail($localId);
                    if ($prior->order_id !== $payment->order_id || ($prior->refund_id && $prior->refund_id !== $refundId)
                        || $prior->amount_cents !== $remote['amount'] || $prior->operation_id !== data_get($remote, 'metadata.studio_refund_operation')) {
                        throw new StudioPaymentException('billing_attention', 'Refund ownership changed.');
                    }
                    $prior->update(['refund_id' => $refundId]);

                    return $prior;
                }

                // An operator may refund in Stripe directly. Bind from the verified known payment, never email/metadata alone.
                return Refund::create(['order_id' => $payment->order_id, 'checkout_attempt_id' => $payment->id, 'operation_id' => 'external:'.$refundId,
                    'account_id' => $payment->account_id, 'livemode' => $payment->livemode, 'payment_intent_id' => $payment->payment_intent_id,
                    'refund_id' => $refundId, 'currency' => $payment->currency, 'amount_cents' => $remote['amount'], 'status' => 'pending']);
            });
            $this->reconcile($attempt, $remote, $eventId);
        } finally {
            $lock->release();
        }
    }

    private function reconcile(Refund $attempt, array $remote, ?string $eventId = null): void
    {
        $payment = Checkout::findOrFail($attempt->checkout_attempt_id);
        $this->validate($payment, $remote, $attempt->refund_id);
        if ($remote['amount'] !== $attempt->amount_cents || $attempt->payment_intent_id !== $payment->payment_intent_id) {
            throw new StudioPaymentException('billing_attention', 'Refund amount changed.');
        }
        $status = $remote['status'];
        app(ListingStudioOrderService::class)->confirmRefund($attempt->order_id, [
            'provider' => 'stripe', 'eventId' => $eventId, 'accountId' => $attempt->account_id, 'liveMode' => $attempt->livemode,
            'refundAttemptId' => $attempt->id, 'refundOperationId' => $attempt->operation_id, 'paymentIntentId' => $attempt->payment_intent_id,
            'refundId' => $attempt->refund_id, 'currency' => $attempt->currency, 'amountCents' => $attempt->amount_cents,
            'status' => $status === 'requires_action' ? 'pending' : $status,
            'occurredAt' => CarbonImmutable::createFromTimestampUTC($remote['created'])->toIso8601String(),
        ]);
        $this->write(fn () => $attempt->update(['status' => $status]));
    }

    private function validate(Checkout $payment, array $refund, string $id): void
    {
        $intent = $refund['payment_intent'] ?? null;
        $charge = $refund['charge'] ?? null;
        if ($this->id($refund) !== $id || ! is_array($intent) || ! is_array($charge)
            || $this->id($intent) !== $payment->payment_intent_id || $this->id($charge['payment_intent'] ?? null) !== $payment->payment_intent_id
            || ($intent['livemode'] ?? null) !== $payment->livemode || ($charge['livemode'] ?? null) !== $payment->livemode
            || $this->id($intent['customer'] ?? null) !== $payment->customer_id || $this->id($charge['customer'] ?? null) !== $payment->customer_id
            || ($refund['currency'] ?? '') !== $payment->currency || ($intent['currency'] ?? '') !== $payment->currency
            || ($intent['amount_received'] ?? null) !== $payment->amount_cents || ($intent['status'] ?? '') !== 'succeeded'
            || ! is_int($refund['amount'] ?? null) || $refund['amount'] <= 0 || $refund['amount'] > $payment->amount_cents
            || ! is_int($refund['created'] ?? null) || ! in_array($refund['status'] ?? '', ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)) {
            throw new StudioPaymentException('billing_attention', 'Refund evidence did not match the paid order.');
        }
    }

    private function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-refund');
    }
}
