<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioSubscription;
use App\Services\Payments\StripeCheckoutOwnership;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Recognize subscription refunds without changing enrollments or shoot accounting. */
class StripeSubscriptionRefundSync
{
    public const EVENTS = ['refund.created', 'refund.updated', 'refund.failed'];

    public function __construct(
        private readonly StripeRefundGateway $gateway,
        private readonly StripeCheckoutOwnership $shootOwnership,
    ) {}

    /** null preserves the existing handler for refunds not proven to be Listing Studio. */
    public function handle(array $event): ?array
    {
        if (! config('listing-studio.stripe_enabled') || ! in_array($event['type'] ?? '', self::EVENTS, true)) {
            return null;
        }
        $account = trim((string) config('listing-studio.stripe_account_id'));
        $mode = config('listing-studio.stripe_mode');
        $live = $mode === 'live';
        if ($account === '' || ! in_array($mode, ['live', 'test'], true)
            || ! array_key_exists('livemode', $event) || (bool) $event['livemode'] !== $live
            || (! empty($event['account']) && $event['account'] !== $account)) {
            return null;
        }
        $incoming = data_get($event, 'data.object', []);
        $refundId = $this->id($incoming);
        if (! is_array($incoming) || ! preg_match('/^re_[a-zA-Z0-9_]+$/', $refundId)
            || ! preg_match('/^evt_[a-zA-Z0-9_]+$/', (string) ($event['id'] ?? ''))
            || $this->belongsToShootWorkflow($incoming)) {
            return null;
        }

        $lock = Cache::lock('listing-studio-refund:'.hash('sha256', $account.':'.(int) $live.':'.$refundId), 180);
        if (! $lock->get()) {
            throw new \RuntimeException('Listing Studio refund synchronization is already running.');
        }
        try {
            $refund = $this->gateway->refund($refundId);
            $charge = $refund['charge'] ?? null;
            $paymentIntentId = $this->id($refund['payment_intent'] ?? null);
            if ($this->id($refund) !== $refundId || ! is_array($charge) || $this->id($charge) === ''
                || ! array_key_exists('livemode', $charge) || (bool) $charge['livemode'] !== $live) {
                throw new \RuntimeException('Stripe returned a different refund identity or charge mode.');
            }
            foreach (['charge', 'payment_intent'] as $field) {
                if (($expected = $this->id($incoming[$field] ?? null)) !== '' && $expected !== $this->id($refund[$field] ?? null)) {
                    throw new \RuntimeException('Stripe refund references do not match the event.');
                }
            }
            if ($this->belongsToShootWorkflow($refund)) {
                return null;
            }
            if ($paymentIntentId === '' || $this->id($charge['payment_intent'] ?? null) !== $paymentIntentId) {
                return null;
            }
            $customerId = $this->id($charge['customer'] ?? null);
            if ($customerId === '') {
                return null;
            }

            // Basil removed Charge.invoice and PaymentIntent.invoice. Resolve the
            // actual payment allocation rather than guessing from customer or metadata.
            $payments = $this->gateway->invoicePayments($paymentIntentId);
            if (! array_key_exists('has_more', $payments) || ! is_array($payments['data'] ?? null)) {
                throw new \RuntimeException('Stripe did not return an invoice payment collection.');
            }
            if ($payments['has_more'] || count($payments['data']) !== 1) {
                return null;
            }
            $payment = $payments['data'][0];
            $invoice = $payment['invoice'] ?? null;
            if (data_get($payment, 'payment.type') !== 'payment_intent'
                || $this->id(data_get($payment, 'payment.payment_intent')) !== $paymentIntentId
                || ! array_key_exists('livemode', $payment) || (bool) $payment['livemode'] !== $live) {
                throw new \RuntimeException('Stripe returned an unrelated invoice payment.');
            }
            if (! is_array($invoice) || $this->id($invoice) === '') {
                throw new \RuntimeException('Stripe did not expand the refund invoice.');
            }
            if (! array_key_exists('livemode', $invoice) || (bool) $invoice['livemode'] !== $live
                || $this->id($invoice['customer'] ?? null) !== $customerId) {
                throw new \RuntimeException('Stripe refund and invoice ownership do not match.');
            }
            $subscriptionId = $this->id(data_get($invoice, 'parent.subscription_details.subscription') ?? $invoice['subscription'] ?? null);
            if ($subscriptionId === '' || ($payment['status'] ?? '') !== 'paid') {
                return null;
            }
            $subscription = $this->gateway->subscription($subscriptionId);
            if ($this->id($subscription) !== $subscriptionId || ! array_key_exists('livemode', $subscription)
                || (bool) $subscription['livemode'] !== $live || $this->id($subscription['customer'] ?? null) !== $customerId) {
                throw new \RuntimeException('Stripe refund and subscription ownership do not match.');
            }

            $identity = ['stripe_account_id' => $account, 'livemode' => $live];
            $known = ListingStudioSubscription::where($identity + ['stripe_subscription_id' => $subscriptionId])->first();
            if ($known && $known->stripe_customer_id !== $customerId) {
                throw new \RuntimeException('The refund conflicts with the linked subscription customer.');
            }
            if (! $known && ! $this->hasAllowedPlan($subscription, $live)) {
                return null;
            }
            $status = $refund['status'] ?? '';
            $currency = strtolower((string) ($refund['currency'] ?? ''));
            if (! in_array($status, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)
                || ! is_numeric($refund['amount'] ?? null) || (int) $refund['amount'] <= 0
                || ! preg_match('/^[a-z]{3}$/', $currency)
                || $currency !== strtolower((string) ($charge['currency'] ?? ''))
                || $currency !== strtolower((string) ($invoice['currency'] ?? ''))
                || ! is_numeric($refund['created'] ?? null) || (int) $refund['created'] <= 0) {
                throw new \RuntimeException('Stripe returned an unsupported refund state.');
            }
            $key = $identity + ['stripe_refund_id' => $refundId];
            $references = [
                'stripe_subscription_id' => $subscriptionId, 'stripe_customer_id' => $customerId,
                'stripe_invoice_id' => $this->id($invoice), 'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_charge_id' => $this->id($charge),
            ];
            LockedWrite::run(fn () => DB::transaction(function () use ($key, $identity, $references, $refund, $status, $currency, $event, $invoice) {
                $existing = DB::table('listing_studio_subscription_refunds')->where($key)->first();
                if ($existing) {
                    foreach ($references as $field => $value) {
                        if ($existing->{$field} !== $value) {
                            throw new \RuntimeException('The refund identity changed during reconciliation.');
                        }
                    }
                }
                DB::table('listing_studio_subscription_refunds')->updateOrInsert($key, $references + [
                    'amount_cents' => (int) $refund['amount'], 'currency' => $currency, 'status' => $status,
                    'refund_created_at' => CarbonImmutable::createFromTimestampUTC((int) $refund['created']),
                    'last_event_id' => $event['id'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
                ]);
                // Credit policy is reconciled from cumulative canonical refund rows.
                // A refund arriving first must never create an account or enrollment.
                $record = ListingStudioSubscription::where($identity + [
                    'stripe_subscription_id' => $references['stripe_subscription_id'],
                ])->first();
                if ($record) {
                    app(ListingStudioCreditService::class)->reconcileInvoices($record, [$invoice]);
                }
            }), 'listing-studio-stripe-refund-sync');

            return ['status' => 'success', 'handled' => true, 'outcome' => 'listing_studio_refund_synced'];
        } finally {
            $lock->release();
        }
    }

    private function belongsToShootWorkflow(array $refund): bool
    {
        foreach (['app_instance', 'app_payment_id', 'app_refund_operation_key'] as $field) {
            if (trim((string) data_get($refund, 'metadata.'.$field, '')) !== '') {
                return true;
            }
        }

        return $this->shootOwnership->hasLocalReference('', $this->id($refund['payment_intent'] ?? null));
    }

    private function hasAllowedPlan(array $subscription, bool $live): bool
    {
        $items = data_get($subscription, 'items.data', []);
        if (! is_array($items) || count($items) !== 1 || (int) ($items[0]['quantity'] ?? 0) !== 1) {
            return false;
        }
        $priceId = $this->id($items[0]['price'] ?? null);
        foreach (config('listing-studio.plans', []) as $plan) {
            $allowed = $plan[$live ? 'live_price' : 'test_price'] ?? null;
            if (is_string($allowed) && $allowed !== '' && hash_equals($allowed, $priceId)) {
                return true;
            }
        }

        return false;
    }

    private function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }
}
