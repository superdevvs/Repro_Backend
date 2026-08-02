<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripePaymentMetadataService
{
    private ?StripeClient $stripeClient = null;

    public function hydratePaymentRecord(Payment $payment, mixed $session = null): Payment
    {
        $details = $this->mergeStripePaymentDetails($payment, $session);

        if (($payment->payment_details ?? null) !== $details) {
            $payment->payment_details = $details;
            $payment->save();
        }

        return $payment->fresh() ?? $payment;
    }

    public function hydratePaymentRecordIfNeeded(Payment $payment, mixed $session = null): Payment
    {
        if (!$this->shouldBackfillStripeDetails($payment, $session)) {
            return $payment;
        }

        return $this->hydratePaymentRecord($payment, $session);
    }

    public function shouldBackfillStripeDetails(Payment $payment, mixed $session = null): bool
    {
        if ($payment->payment_method !== 'stripe' || empty($payment->stripe_payment_id)) {
            return false;
        }

        if ($session !== null) {
            return true;
        }

        $details = $this->normalizePaymentDetails($payment->payment_details);

        return empty($details['hosted_receipt_url'])
            || empty($details['charge_id'])
            || empty($details['payment_intent_id']);
    }

    public function mergeStripePaymentDetails(Payment $payment, mixed $session = null): array
    {
        $details = $this->applyBasePaymentMetadata(
            $payment,
            $this->normalizePaymentDetails($payment->payment_details)
        );

        if ($payment->payment_method !== 'stripe' || empty($payment->stripe_payment_id)) {
            return $details;
        }

        try {
            $session = $session ?: $this->retrieveCheckoutSessionSafely($payment->stripe_session_id);

            if (is_object($session)) {
                $details['checkout_session_id'] = (string) ($session->id ?? ($details['checkout_session_id'] ?? ''));
                $details['checkout_payment_status'] = (string) ($session->payment_status ?? ($details['checkout_payment_status'] ?? ''));
                $details['customer_email'] = (string) ($session->customer_details->email ?? ($details['customer_email'] ?? ''));
                $details['customer_name'] = (string) ($session->customer_details->name ?? ($details['customer_name'] ?? ''));
                $details['customer_id'] = (string) ($session->customer ?? ($details['customer_id'] ?? ''));

                if (is_numeric($session->amount_total ?? null)) {
                    $details['amount_total'] = round(((float) $session->amount_total) / 100, 2);
                }

                if (!empty($session->currency)) {
                    $details['currency'] = strtoupper((string) $session->currency);
                }
            }

            $paymentIntent = $this->stripeClient()->paymentIntents->retrieve(
                $payment->stripe_payment_id,
                ['expand' => ['latest_charge']]
            );

            $details['payment_intent_id'] = (string) ($paymentIntent->id ?? $payment->stripe_payment_id);
            $details['payment_intent_status'] = (string) ($paymentIntent->status ?? ($details['payment_intent_status'] ?? ''));
            $details['payment_method_id'] = (string) ($paymentIntent->payment_method ?? ($details['payment_method_id'] ?? ''));

            $charge = $paymentIntent->latest_charge ?? null;
            if (is_string($charge) && $charge !== '') {
                $charge = $this->stripeClient()->charges->retrieve($charge, []);
            }

            if (is_object($charge)) {
                $details['charge_id'] = (string) ($charge->id ?? ($details['charge_id'] ?? ''));
                $details['hosted_receipt_url'] = (string) ($charge->receipt_url ?? ($details['hosted_receipt_url'] ?? ''));
                $details['receipt_url'] = (string) ($charge->receipt_url ?? ($details['receipt_url'] ?? ''));
                $details['receipt_number'] = (string) ($charge->receipt_number ?? ($details['receipt_number'] ?? ''));
                $details['billing_email'] = (string) ($charge->billing_details->email ?? ($details['billing_email'] ?? ''));
                $details['payment_method_type'] = (string) ($charge->payment_method_details->type ?? ($details['payment_method_type'] ?? 'card'));
                $details['payment_method_brand'] = (string) ($charge->payment_method_details->card->brand ?? ($details['payment_method_brand'] ?? ''));
                $details['payment_method_last4'] = (string) ($charge->payment_method_details->card->last4 ?? ($details['payment_method_last4'] ?? ''));

                if (is_numeric($charge->created ?? null)) {
                    $details['charged_at'] = Carbon::createFromTimestamp((int) $charge->created)->toIso8601String();
                }

                if (is_numeric($charge->amount ?? null)) {
                    $details['charged_amount'] = round(((float) $charge->amount) / 100, 2);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to enrich Stripe payment metadata.', [
                'payment_id' => $payment->id,
                'stripe_payment_id' => $payment->stripe_payment_id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $details;
    }

    public function mergeRefundDetails(Payment $payment, mixed $refund, ?float $refundAmount = null): array
    {
        $details = $this->mergeStripePaymentDetails($payment);
        $refundedAt = is_numeric($refund?->created ?? null)
            ? Carbon::createFromTimestamp((int) $refund->created)
            : now();

        $details['status'] = (string) ($payment->status ?: Payment::STATUS_REFUNDED);
        $details['refund_id'] = (string) ($refund->id ?? ($details['refund_id'] ?? ''));
        $details['refund_status'] = (string) ($refund->status ?? ($details['refund_status'] ?? Payment::STATUS_REFUNDED));
        $details['refund_amount'] = round(
            $refundAmount ?? $this->stripeAmountToFloat($refund?->amount, (float) $payment->amount),
            2
        );
        $details['refund_currency'] = strtoupper((string) ($refund->currency ?? $payment->currency ?? ($details['refund_currency'] ?? 'USD')));
        $details['refunded_at'] = $refundedAt->toIso8601String();

        return $details;
    }

    public function serializePayment(Payment $payment): array
    {
        $details = $this->normalizePaymentDetails($payment->payment_details);

        return [
            'id' => (int) $payment->id,
            'payment_id' => (int) $payment->id,
            'amount' => (float) $payment->amount,
            'currency' => strtoupper((string) ($payment->currency ?: 'USD')),
            'provider' => strtolower((string) ($payment->payment_method ?: 'stripe')),
            'status' => (string) ($payment->status ?? ''),
            'processed_at' => $this->toIso8601($payment->processed_at ?? $payment->created_at),
            'stripe_payment_id' => $payment->stripe_payment_id,
            'stripe_session_id' => $payment->stripe_session_id,
            'charge_id' => $this->stringOrNull($details['charge_id'] ?? null),
            'hosted_receipt_url' => $this->stringOrNull($details['hosted_receipt_url'] ?? $details['receipt_url'] ?? null),
            'receipt_url' => $this->stringOrNull($details['hosted_receipt_url'] ?? $details['receipt_url'] ?? null),
            'refund_status' => $this->stringOrNull($details['refund_status'] ?? null),
            'refunded_at' => $this->normalizeIsoDateString($details['refunded_at'] ?? null),
            'refund_id' => $this->stringOrNull($details['refund_id'] ?? null),
            'refund_amount' => $this->toNullableFloat($details['refund_amount'] ?? null),
            // Authoritative refunded total from the payment_refunds rows, so the
            // client computes a net contribution instead of discarding a
            // partially refunded payment entirely.
            'refunded_amount' => $payment->refundedAmount(),
            'net_amount' => $payment->netAmount(),
            'refundable_remainder' => $payment->refundableRemainder(),
            'payment_method_type' => $this->stringOrNull($details['payment_method_type'] ?? null),
            'payment_method_brand' => $this->stringOrNull($details['payment_method_brand'] ?? null),
            'payment_method_last4' => $this->stringOrNull($details['payment_method_last4'] ?? null),
            'payment_details' => $details !== [] ? $details : null,
        ];
    }

    public function buildActivityMetadata(Payment $payment): array
    {
        $serialized = $this->serializePayment($payment);

        return [
            'payment_id' => $serialized['payment_id'],
            'provider' => $serialized['provider'],
            'amount' => $serialized['amount'],
            'currency' => $serialized['currency'],
            'status' => $serialized['status'],
            'processed_at' => $serialized['processed_at'],
            'stripe_payment_id' => $serialized['stripe_payment_id'],
            'stripe_session_id' => $serialized['stripe_session_id'],
            'charge_id' => $serialized['charge_id'],
            'hosted_receipt_url' => $serialized['hosted_receipt_url'],
            'receipt_url' => $serialized['receipt_url'],
            'refund_status' => $serialized['refund_status'],
            'refunded_at' => $serialized['refunded_at'],
            'refund_id' => $serialized['refund_id'],
            'refund_amount' => $serialized['refund_amount'],
            // Carried into the activity entry because that is the only payload
            // the activity log reads. Without the remainder the UI cannot tell a
            // partial refund from a full one and would refuse a second refund.
            'refunded_amount' => $serialized['refunded_amount'],
            'net_amount' => $serialized['net_amount'],
            'refundable_remainder' => $serialized['refundable_remainder'],
            'payment_method_brand' => $serialized['payment_method_brand'],
            'payment_method_last4' => $serialized['payment_method_last4'],
        ];
    }

    public function resolveLatestReceiptPayment(iterable $payments): ?Payment
    {
        $collection = $payments instanceof Collection ? $payments : collect($payments);

        return $collection
            ->filter(fn ($payment) => $payment instanceof Payment && in_array($payment->status, [
                Payment::STATUS_COMPLETED,
                Payment::STATUS_REFUNDED,
            ], true))
            ->sortByDesc(fn (Payment $payment) => $this->resolvePaymentActivityTimestamp($payment)?->getTimestamp() ?? 0)
            ->first();
    }

    public function buildReceiptPayload(?Payment $payment): ?array
    {
        if (!$payment) {
            return null;
        }

        $details = $this->normalizePaymentDetails($payment->payment_details);
        $paidAt = $payment->processed_at ?? $payment->created_at;

        return [
            'payment_id' => (int) $payment->id,
            'number' => 'PAY-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'amount' => (float) $payment->amount,
            'currency' => strtoupper((string) ($payment->currency ?: 'USD')),
            'paid_at' => $this->toIso8601($paidAt),
            'provider' => strtolower((string) ($payment->payment_method ?: 'stripe')),
            'status' => (string) ($payment->status ?? ''),
            'hosted_receipt_url' => $this->stringOrNull($details['hosted_receipt_url'] ?? $details['receipt_url'] ?? null),
            'receipt_url' => $this->stringOrNull($details['hosted_receipt_url'] ?? $details['receipt_url'] ?? null),
            'payment_intent_id' => $payment->stripe_payment_id,
            'charge_id' => $this->stringOrNull($details['charge_id'] ?? null),
            'refund_status' => $this->stringOrNull($details['refund_status'] ?? null),
            'refunded_at' => $this->normalizeIsoDateString($details['refunded_at'] ?? null),
            'refund_id' => $this->stringOrNull($details['refund_id'] ?? null),
            'refund_amount' => $this->toNullableFloat($details['refund_amount'] ?? null),
        ];
    }

    private function applyBasePaymentMetadata(Payment $payment, array $details): array
    {
        $details['provider'] = (string) ($payment->payment_method ?: ($details['provider'] ?? 'stripe'));
        $details['payment_id'] = (int) $payment->id;
        $details['status'] = (string) ($payment->status ?? ($details['status'] ?? ''));
        $details['amount'] = round((float) $payment->amount, 2);
        $details['currency'] = strtoupper((string) ($payment->currency ?: ($details['currency'] ?? 'USD')));
        $details['processed_at'] = $this->toIso8601($payment->processed_at ?? $payment->created_at);
        $details['payment_intent_id'] = (string) ($payment->stripe_payment_id ?? ($details['payment_intent_id'] ?? ''));
        $details['checkout_session_id'] = (string) ($payment->stripe_session_id ?? ($details['checkout_session_id'] ?? ''));

        return $details;
    }

    private function retrieveCheckoutSessionSafely(?string $sessionId): ?object
    {
        $normalizedSessionId = $this->normalizeCheckoutSessionId($sessionId);
        if ($normalizedSessionId === null) {
            return null;
        }

        try {
            return $this->stripeClient()->checkout->sessions->retrieve($normalizedSessionId, []);
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve Stripe checkout session for metadata enrichment.', [
                'session_id' => $normalizedSessionId,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    private function normalizeCheckoutSessionId(?string $sessionId): ?string
    {
        $normalized = trim((string) $sessionId);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(cs_[^_]+(?:_[^_]+)*)_shoot_\d+$/', $normalized, $matches)) {
            return $matches[1];
        }

        return $normalized;
    }

    private function resolvePaymentActivityTimestamp(Payment $payment): ?Carbon
    {
        $details = $this->normalizePaymentDetails($payment->payment_details);
        $timestamps = collect([
            $this->parseCarbon($details['refunded_at'] ?? null),
            $payment->processed_at instanceof Carbon ? $payment->processed_at : $this->parseCarbon($payment->processed_at),
            $payment->created_at instanceof Carbon ? $payment->created_at : $this->parseCarbon($payment->created_at),
        ])->filter();

        return $timestamps->sortByDesc(fn (Carbon $date) => $date->getTimestamp())->first();
    }

    private function stripeAmountToFloat(mixed $amount, float $fallback = 0): float
    {
        if (!is_numeric($amount)) {
            return $fallback;
        }

        return round(((float) $amount) / 100, 2);
    }

    private function toIso8601(mixed $value): ?string
    {
        $date = $value instanceof Carbon ? $value : $this->parseCarbon($value);

        return $date?->toIso8601String();
    }

    private function parseCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeIsoDateString(mixed $value): ?string
    {
        return $this->toIso8601($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function normalizePaymentDetails(mixed $details): array
    {
        return is_array($details) ? $details : [];
    }

    private function stripeClient(): StripeClient
    {
        if ($this->stripeClient instanceof StripeClient) {
            return $this->stripeClient;
        }

        $secretKey = trim((string) config('services.stripe.secret_key'));
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe payment integration is not configured. Please contact the administrator.');
        }

        $this->stripeClient = new StripeClient($secretKey);

        return $this->stripeClient;
    }
}
