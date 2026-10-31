<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioSubscriptionAction as Action;

/** Complete, bound proration evidence shared by preview, recovery and webhook projection. */
class StudioSubscriptionInvoice
{
    public static function describe(Action $action, array $invoice): array
    {
        $snapshot = $action->snapshot;
        $subscription = self::id(data_get($invoice, 'parent.subscription_details.subscription') ?? $invoice['subscription'] ?? null);
        if (($invoice['livemode'] ?? null) !== $action->livemode || self::id($invoice['customer'] ?? null) !== $action->customer_id
            || $subscription !== $action->stripe_subscription_id || ($invoice['currency'] ?? '') !== 'usd'
            || ! is_int($invoice['amount_due'] ?? null) || $invoice['amount_due'] < 0 || $invoice['amount_due'] > 1_000_000
            || ! is_array(data_get($invoice, 'lines.data')) || data_get($invoice, 'lines.has_more') !== false) {
            throw new StudioPaymentException('subscription_review_unavailable', 'The subscription invoice could not be verified.');
        }
        $lines = $invoice['lines']['data'];
        if (count($lines) !== 2) {
            throw new StudioPaymentException('subscription_review_unavailable', 'This subscription needs billing review before changing plans.');
        }
        $credits = 0;
        $normalized = [];
        foreach ($lines as $line) {
            $price = self::id(data_get($line, 'pricing.price_details.price') ?? $line['price'] ?? null);
            $isSource = $price === $action->source_price_id;
            $plan = config('listing-studio.plans.'.($isSource ? $action->current_plan_code : $action->plan_code));
            $lineSubscription = self::id(data_get($line, 'parent.subscription_item_details.subscription')
                ?? data_get($line, 'parent.invoice_item_details.subscription') ?? $line['subscription'] ?? null);
            $proration = $line['proration'] ?? data_get($line, 'parent.subscription_item_details.proration')
                ?? data_get($line, 'parent.invoice_item_details.proration', false);
            if ((! $isSource && $price !== $action->target_price_id) || ! is_array($plan) || ! $proration
                || $lineSubscription !== $action->stripe_subscription_id || ($line['quantity'] ?? null) !== 1
                || ! is_int($line['amount'] ?? null) || ($isSource ? $line['amount'] > 0 : $line['amount'] < 0)
                || (int) data_get($line, 'period.start') !== (int) ($snapshot['prorationDate'] ?? 0)
                || (int) data_get($line, 'period.end') !== (int) $snapshot['periodEnd']) {
                throw new StudioPaymentException('subscription_review_unavailable', 'The subscription adjustment changed. Review it again.');
            }
            $amount = $line['amount'];
            $credit = intdiv(abs($amount) * (int) $plan['monthly_credit_cents'] + intdiv((int) $plan['price_cents'], 2), (int) $plan['price_cents']);
            $credits += $amount < 0 ? -$credit : $credit;
            $normalized[$price] = [$price, $amount, $line['quantity'], (int) data_get($line, 'period.start'), (int) data_get($line, 'period.end')];
        }
        if (count($normalized) !== 2 || $credits < 0) {
            throw new StudioPaymentException('subscription_review_unavailable', 'The subscription adjustment is not supported.');
        }
        ksort($normalized);

        return ['amountCents' => $invoice['amount_due'], 'additionalCreditCents' => $credits, 'currency' => 'usd',
            'fingerprint' => hash('sha256', json_encode([$invoice['amount_due'], $invoice['total'] ?? null, array_values($normalized)], JSON_THROW_ON_ERROR))];
    }

    public static function matches(Action $action, array $invoice): bool
    {
        try {
            $description = self::describe($action, $invoice);

            return is_string(data_get($action->preview, 'fingerprint'))
                && hash_equals($action->preview['fingerprint'], $description['fingerprint']);
        } catch (StudioPaymentException) {
            return false;
        }
    }

    public static function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }
}
