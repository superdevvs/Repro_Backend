<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioCheckoutAttempt as Attempt;
use App\Models\ListingStudioOrder;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Recognizes only locally owned Studio purchases; existing subscriptions and shoot payments keep their handlers. */
class StudioPaymentSync
{
    public function __construct(private readonly StudioStripeGateway $gateway, private readonly StudioCheckoutService $checkout) {}

    public function handle(array $event): ?array
    {
        $type = $event['type'] ?? '';
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired', 'refund.created', 'refund.updated', 'refund.failed'], true)) {
            return null;
        }
        [$account, $live] = $this->checkout->identity();
        if (($event['livemode'] ?? null) !== $live || (! empty($event['account']) && $event['account'] !== $account)
            || ! preg_match('/^evt_[A-Za-z0-9_]+$/', (string) ($event['id'] ?? ''))) {
            return null;
        }
        $object = data_get($event, 'data.object', []);
        if (! is_array($object)) {
            return null;
        }
        $attempt = str_starts_with($type, 'checkout.') ? $this->findAttempt($object) : null;
        $refundPayment = str_starts_with($type, 'refund.') ? Attempt::where(['account_id' => $account, 'livemode' => $live, 'kind' => 'order'])
            ->where('payment_intent_id', $this->id($object['payment_intent'] ?? null))->whereNotNull('payment_intent_id')->first() : null;
        if (! $attempt && ! $refundPayment) {
            return null;
        }
        $eventKey = ['account_id' => $account, 'livemode' => $live, 'event_id' => $event['id']];
        if (DB::table('listing_studio_payment_events')->where($eventKey)->exists()) {
            return $this->handled();
        }
        // Covers the bounded complete refund pagination plus canonical checkout/account reads.
        $lock = Cache::store('database')->lock('studio-payment-sync:'.($attempt?->id ?? $refundPayment->id), 300);
        if (! $lock->get()) {
            throw new StudioPaymentException('awaiting_reconciliation', 'Payment reconciliation is already running.');
        }
        try {
            $this->checkout->verifyAccount();
            if ($attempt) {
                $this->checkout->assertIdentity($attempt);
                $session = $this->gateway->checkout($this->id($object));
                $this->reconcileCheckout($attempt, $session, $event['id']);
                if ($type === 'checkout.session.async_payment_failed' && ($session['payment_status'] ?? '') === 'unpaid') {
                    $intent = $session['payment_intent'] ?? null;
                    $this->require(is_array($intent) && ($intent['livemode'] ?? null) === $attempt->livemode
                        && $this->id($intent['customer'] ?? null) === $attempt->customer_id
                        && ($intent['amount'] ?? null) === $attempt->amount_cents && ($intent['currency'] ?? '') === $attempt->currency
                        && data_get($intent, 'metadata.studio_checkout_attempt') === $attempt->id
                        && in_array($intent['status'] ?? '', ['canceled', 'requires_payment_method'], true));
                    $this->write(fn () => Attempt::whereKey($attempt->id)->whereNotIn('status', ['paid', 'expired'])->update(['status' => 'failed', 'checkout_url' => null]));
                }
            } else {
                app(StudioRefundService::class)->reconcileExternal($refundPayment, $this->id($object), $event['id']);
            }
            $this->write(fn () => DB::table('listing_studio_payment_events')->insertOrIgnore($eventKey + ['event_type' => $type, 'processed_at' => now()]));

            return $this->handled();
        } finally {
            $lock->release();
        }
    }

    public function reconcileCheckout(Attempt $attempt, array $session, ?string $eventId = null, bool $readOnly = false): array
    {
        $this->checkout->assertIdentity($attempt);
        $this->require($eventId === null || preg_match('/^evt_[A-Za-z0-9_]+$/', $eventId) === 1);
        $settle = $eventId !== null && ! $readOnly;
        $sessionId = $this->id($session);
        $this->require(preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) === 1
            && (! $attempt->session_id || $attempt->session_id === $sessionId)
            && ($session['livemode'] ?? null) === $attempt->livemode
            && $this->id($session['customer'] ?? null) === $attempt->customer_id
            && ($session['client_reference_id'] ?? null) === $attempt->id
            && data_get($session, 'metadata.studio_checkout_attempt') === $attempt->id
            && data_get($session, 'metadata.studio_kind') === $attempt->kind
            && ($session['mode'] ?? '') === ($attempt->kind === 'order' ? 'payment' : 'subscription'));
        if ($attempt->kind === 'order') {
            $this->require(data_get($session, 'metadata.studio_order_id') === $attempt->order_id);
        }
        // Save the accepted identity before another GET so interrupted finalization can retrieve it.
        if (! $readOnly) {
            $this->write(function () use ($attempt, $sessionId) {
                $fresh = Attempt::findOrFail($attempt->id);
                $this->require(! $fresh->session_id || $fresh->session_id === $sessionId);
                $fresh->update(['session_id' => $sessionId]);
            });
        }
        if (! is_array($session['line_items'] ?? null)) {
            $canonical = $this->gateway->checkout($sessionId);
            $this->require(is_array($canonical['line_items'] ?? null));

            return $this->reconcileCheckout($attempt->fresh(), $canonical, $eventId, $readOnly);
        }
        $lines = data_get($session, 'line_items.data');
        $this->require($this->id($session) === $sessionId && ($session['livemode'] ?? null) === $attempt->livemode
            && $this->id($session['customer'] ?? null) === $attempt->customer_id
            && ($session['client_reference_id'] ?? null) === $attempt->id
            && data_get($session, 'metadata.studio_checkout_attempt') === $attempt->id
            && strtolower((string) ($session['currency'] ?? '')) === $attempt->currency
            && is_int($session['amount_total'] ?? null) && $session['amount_total'] === $attempt->amount_cents
            && is_array($lines) && count($lines) === 1 && ! data_get($session, 'line_items.has_more', true)
            && ($lines[0]['quantity'] ?? null) === 1 && ($lines[0]['amount_total'] ?? null) === $attempt->amount_cents);
        if ($attempt->kind === 'subscription') {
            $this->require($this->id(data_get($lines[0], 'price')) === data_get($attempt->parameters, 'line_items.0.price'));
        }
        $status = $session['status'] ?? '';
        $this->require(in_array($status, ['open', 'complete', 'expired'], true));
        $paid = ($session['payment_status'] ?? '') === 'paid';
        $state = $status === 'expired' ? 'expired' : ($status === 'open' ? 'open' : 'awaiting_payment');
        $paymentId = null;
        $subscriptionId = null;
        if ($paid) {
            $this->require($status === 'complete');
            if ($attempt->kind === 'order') {
                $payment = $session['payment_intent'] ?? null;
                $this->require(is_array($payment) && preg_match('/^pi_[A-Za-z0-9_]+$/', $this->id($payment)) === 1
                    && ($payment['livemode'] ?? null) === $attempt->livemode && ($payment['status'] ?? '') === 'succeeded'
                    && ($payment['amount'] ?? null) === $attempt->amount_cents && ($payment['amount_received'] ?? null) === $attempt->amount_cents
                    && ($payment['currency'] ?? '') === $attempt->currency && $this->id($payment['customer'] ?? null) === $attempt->customer_id
                    && data_get($payment, 'metadata.studio_order_id') === $attempt->order_id
                    && data_get($payment, 'metadata.studio_checkout_attempt') === $attempt->id);
                $paymentId = $this->id($payment);
                $this->require(! $attempt->payment_intent_id || $attempt->payment_intent_id === $paymentId);
                $order = ListingStudioOrder::findOrFail($attempt->order_id);
                $this->require($order->client_id === $attempt->client_id && $order->funding_method === 'card'
                    && $order->total_cents === $attempt->amount_cents && $order->currency === $attempt->currency);
                if (! $readOnly) {
                    $this->write(function () use ($attempt, $paymentId) {
                        $fresh = Attempt::findOrFail($attempt->id);
                        $this->require(! $fresh->payment_intent_id || $fresh->payment_intent_id === $paymentId);
                        $fresh->update(['payment_intent_id' => $paymentId]);
                    });
                }
                if ($settle) {
                    // Stripe may deliver an external refund before the checkout event. Reconcile
                    // the complete canonical refund state atomically with funding, before queueing.
                    $refunds = app(StudioRefundService::class)->preparePaymentRefunds($attempt->fresh(), $eventId);
                    app(ListingStudioOrderService::class)->confirmPayment($order->id, [
                        'provider' => 'stripe', 'eventId' => $eventId, 'accountId' => $attempt->account_id,
                        'liveMode' => $attempt->livemode, 'checkoutAttemptId' => $attempt->id, 'checkoutSessionId' => $sessionId,
                        'paymentIntentId' => $paymentId, 'customerId' => $attempt->customer_id, 'currency' => $attempt->currency,
                        'amountPaidCents' => $attempt->amount_cents, 'paidAt' => now()->toIso8601String(),
                        'refundsVerified' => true, 'refunds' => $refunds,
                    ]);
                    app(StudioRefundService::class)->recordPreparedRefunds($refunds);
                }
            } else {
                $subscription = $session['subscription'] ?? null;
                $subscriptionId = $this->id($subscription);
                $this->require(is_array($subscription) && preg_match('/^sub_[A-Za-z0-9_]+$/', $subscriptionId) === 1
                    && ($subscription['livemode'] ?? null) === $attempt->livemode
                    && $this->id($subscription['customer'] ?? null) === $attempt->customer_id
                    && data_get($subscription, 'metadata.studio_checkout_attempt') === $attempt->id
                    && data_get($subscription, 'metadata.studio_kind') === 'subscription');
                if ($settle) {
                    app(StripeSubscriptionSync::class)->handle(['id' => $eventId,
                        'type' => 'checkout.session.completed', 'livemode' => $attempt->livemode, 'account' => $attempt->account_id,
                        'created' => now()->timestamp, 'data' => ['object' => $session]]);
                }
            }
            $state = $settle ? 'paid' : 'awaiting_webhook';
        }
        $url = $state === 'open' ? $this->checkout->navigationUrl($session['url'] ?? null, 'checkout.stripe.com') : null;
        if (! $readOnly) {
            $this->write(function () use ($attempt, $state, $sessionId, $paymentId, $subscriptionId, $session, $url) {
                $fresh = Attempt::findOrFail($attempt->id);
                // A stale unpaid HTTP response cannot undo a concurrent verified settlement.
                if (($fresh->status === 'paid' && $state !== 'paid') || ($fresh->status === 'expired' && $state === 'open')) {
                    return;
                }
                $fresh->update(['session_id' => $sessionId, 'status' => $state, 'payment_intent_id' => $paymentId ?? $fresh->payment_intent_id,
                    'subscription_id' => $subscriptionId ?? $fresh->subscription_id, 'checkout_url' => $url,
                    'expires_at' => isset($session['expires_at']) ? CarbonImmutable::createFromTimestampUTC($session['expires_at']) : null]);
            });
        }

        return ['status' => $state, 'requiresVerifiedEvent' => $paid && ! $settle];
    }

    private function findAttempt(array $object): ?Attempt
    {
        $id = data_get($object, 'metadata.studio_checkout_attempt');
        if (is_string($id) && \Illuminate\Support\Str::isUuid($id)) {
            return Attempt::find($id);
        }

        return Attempt::where('session_id', $this->id($object))->whereNotNull('session_id')->first();
    }

    private function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new StudioPaymentException('billing_attention', 'Payment evidence did not match the purchase.');
        }
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-payment-sync');
    }

    private function handled(): array
    {
        return ['status' => 'success', 'handled' => true, 'outcome' => 'listing_studio_payment_synced'];
    }
}
