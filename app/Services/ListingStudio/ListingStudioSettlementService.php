<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderFundingEffect;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Applies verified economic facts; never calls Stripe or dispatches providers. */
class ListingStudioSettlementService
{
    public function __construct(private readonly ListingStudioOrderService $orders) {}

    public function payment(string $orderId, array $evidence): ListingStudioOrder
    {
        if (($evidence['provider'] ?? null) !== 'stripe' || ! preg_match('/\Aevt_[A-Za-z0-9_]+\z/', $evidence['eventId'] ?? '')
            || ! preg_match('/\Api_[A-Za-z0-9_]+\z/', $evidence['paymentIntentId'] ?? '')
            || ! preg_match('/\Acs_[A-Za-z0-9_]+\z/', $evidence['checkoutSessionId'] ?? '')
            || ! preg_match('/\Acus_[A-Za-z0-9_]+\z/', $evidence['customerId'] ?? '')
            || ! Str::isUuid($evidence['checkoutAttemptId'] ?? '')
            || ! is_int($evidence['amountPaidCents'] ?? null)) {
            $this->invalid();
        }
        $at = $this->date($evidence['paidAt'] ?? null);
        $safe = Arr::only($evidence, ['provider', 'eventId', 'accountId', 'liveMode', 'checkoutAttemptId',
            'checkoutSessionId', 'paymentIntentId', 'customerId', 'currency', 'amountPaidCents']);
        $safe['paidAt'] = $at->toIso8601String();

        return $this->write(function () use ($orderId, $safe, $at) {
            $order = ListingStudioOrder::findOrFail($orderId);
            if ($order->funding_method !== 'card' || ($safe['accountId'] ?? null) !== config('listing-studio.stripe_account_id')
                || ($safe['liveMode'] ?? null) !== (config('listing-studio.stripe_mode') === 'live')
                || ($safe['currency'] ?? null) !== $order->currency || $safe['amountPaidCents'] !== $order->total_cents) {
                $this->invalid();
            }
            $key = $this->key($safe, $safe['paymentIntentId']);
            if ($order->payment_reference && $order->payment_reference !== $key) {
                $this->invalid();
            }
            $effect = ListingStudioOrderFundingEffect::where('kind', 'payment')->where('external_key', $key)->first();
            if ($effect && ($effect->order_id !== $order->id || $effect->amount_cents !== $safe['amountPaidCents'])) {
                $this->invalid();
            }
            if (! $effect) {
                ListingStudioOrderFundingEffect::create(['order_id' => $order->id, 'kind' => 'payment', 'external_key' => $key,
                    'status' => 'succeeded', 'amount_cents' => $safe['amountPaidCents'], 'evidence' => $safe, 'occurred_at' => $at]);
            }
            if (! $order->payment_reference) {
                $order->update(['payment_reference' => $key, 'payment_evidence' => $safe,
                    'paid_amount_cents' => $safe['amountPaidCents'], 'funding_status' => 'settled']);
            }
            $this->orders->fundOrder($order->fresh());

            return $order->fresh();
        });
    }

    public function refund(string $orderId, array $evidence): ListingStudioOrder
    {
        if (($evidence['provider'] ?? null) !== 'stripe' || ! preg_match('/\Are_[A-Za-z0-9_]+\z/', $evidence['refundId'] ?? '')
            || ! preg_match('/\Api_[A-Za-z0-9_]+\z/', $evidence['paymentIntentId'] ?? '')
            || ! Str::isUuid($evidence['refundAttemptId'] ?? '') || ! Str::isUuid($evidence['refundOperationId'] ?? '')
            || ! is_int($evidence['amountCents'] ?? null) || $evidence['amountCents'] < 1
            || ! in_array($evidence['status'] ?? null, ['pending', 'succeeded', 'failed', 'canceled'], true)
            || (isset($evidence['eventId']) && ! preg_match('/\Aevt_[A-Za-z0-9_]+\z/', $evidence['eventId']))) {
            $this->invalid();
        }
        $at = $this->date($evidence['occurredAt'] ?? null);
        $safe = Arr::only($evidence, ['provider', 'eventId', 'accountId', 'liveMode', 'refundAttemptId',
            'refundOperationId', 'paymentIntentId', 'refundId', 'currency', 'amountCents', 'status']);
        $safe['occurredAt'] = $at->toIso8601String();

        return $this->write(function () use ($orderId, $safe, $at) {
            $order = ListingStudioOrder::findOrFail($orderId);
            $payment = $order->payment_evidence ?? [];
            if ($order->funding_method !== 'card' || ! $order->payment_reference
                || ($safe['accountId'] ?? null) !== ($payment['accountId'] ?? null)
                || ($safe['liveMode'] ?? null) !== ($payment['liveMode'] ?? null)
                || ($safe['currency'] ?? null) !== $order->currency
                || $this->key($safe, $safe['paymentIntentId']) !== $order->payment_reference
                || $safe['amountCents'] > $order->paid_amount_cents) {
                $this->invalid();
            }
            $key = $this->key($safe, $safe['refundId']);
            $effect = ListingStudioOrderFundingEffect::where('kind', 'refund')->where('external_key', $key)->first();
            if ($effect && ($effect->order_id !== $order->id || $effect->amount_cents !== $safe['amountCents'])) {
                $this->invalid();
            }
            if (! $effect) {
                $effect = ListingStudioOrderFundingEffect::create(['order_id' => $order->id, 'kind' => 'refund', 'external_key' => $key,
                    'status' => $safe['status'], 'amount_cents' => $safe['amountCents'], 'evidence' => $safe, 'occurred_at' => $at]);
            } elseif ($effect->status !== 'succeeded' && $at->gte($effect->occurred_at)) {
                $effect->update(['status' => $safe['status'], 'evidence' => $safe, 'occurred_at' => $at]);
            }
            $refunds = ListingStudioOrderFundingEffect::where('order_id', $order->id)->where('kind', 'refund')->get();
            $refunded = (int) $refunds->where('status', 'succeeded')->sum('amount_cents');
            if ($refunded > $order->paid_amount_cents) {
                $this->invalid();
            }
            $status = $refunded === $order->paid_amount_cents ? 'refunded'
                : ($refunds->contains('status', 'pending') ? 'refund_pending' : ($refunded > 0 ? 'partially_refunded' : 'settled'));
            $order->update(['refunded_amount_cents' => $refunded, 'funding_status' => $status]);

            return $order->fresh();
        });
    }

    private function key(array $evidence, string $economicId): string
    {
        return ($evidence['accountId'] ?? '').':'.(($evidence['liveMode'] ?? false) ? 'live' : 'test').':'.$economicId;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $value)) {
            $this->invalid();
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new PublicApiException('The verified payment information does not match this order.', 'payment_evidence_invalid', 409);
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-settlement');
    }
}
