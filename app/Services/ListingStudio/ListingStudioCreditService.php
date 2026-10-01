<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Non-cash Studio credits, isolated from shoot receivables and provider usage quotas. */
class ListingStudioCreditService
{
    public function __construct(private readonly StripeSubscriptionGateway $gateway) {}

    /** Fetch outside the write transaction; delayed invoice events still reconcile their own period. */
    public function prepareInvoices(array $event, array $subscription): array
    {
        $invoices = [];
        $latest = $subscription['latest_invoice'] ?? null;
        if (is_array($latest) && $this->id($latest) !== '') {
            $invoices[$this->id($latest)] = $latest;
        }
        if (str_starts_with($event['type'] ?? '', 'invoice.')) {
            $id = $this->id(data_get($event, 'data.object'));
            if (preg_match('/^in_[a-zA-Z0-9_]+$/', $id) && ! isset($invoices[$id])) {
                $invoice = $this->gateway->invoice($id);
                if ($this->id($invoice) !== $id) {
                    throw new \RuntimeException('Stripe returned a different credit invoice.');
                }
                $invoices[$id] = $invoice;
            }
        }

        return array_values($invoices);
    }

    /** Caller holds a short LockedWrite transaction. No network, mail, or queue work here. */
    public function reconcileInvoices(ListingStudioSubscription $subscription, array $invoices): void
    {
        if (! $subscription->client_id) {
            return;
        }
        foreach ($invoices as $invoice) {
            if (($invoice['status'] ?? '') !== 'paid') {
                continue;
            }
            $invoiceId = $this->id($invoice);
            $subscriptionId = $this->id(data_get($invoice, 'parent.subscription_details.subscription') ?? $invoice['subscription'] ?? null);
            if (! preg_match('/^in_[a-zA-Z0-9_]+$/', $invoiceId)
                || ! array_key_exists('livemode', $invoice) || (bool) $invoice['livemode'] !== $subscription->livemode
                || $this->id($invoice['customer'] ?? null) !== $subscription->stripe_customer_id
                || $subscriptionId !== $subscription->stripe_subscription_id
                || strtolower((string) ($invoice['currency'] ?? '')) !== 'usd') {
                throw new \RuntimeException('The paid credit invoice does not match its subscription.');
            }
            if (! in_array($invoice['billing_reason'] ?? '', ['subscription_create', 'subscription_cycle', 'subscription_update'], true)) {
                continue;
            }
            if (! is_array(data_get($invoice, 'lines.data')) || data_get($invoice, 'lines.has_more', false)) {
                throw new \RuntimeException('The credit invoice requires complete line items.');
            }
            foreach ($invoice['lines']['data'] as $line) {
                $price = $this->id(data_get($line, 'pricing.price_details.price') ?? $line['price'] ?? null);
                $plan = $this->plan($price, $subscription->livemode);
                if ($plan === null) {
                    continue;
                }
                $proration = (bool) ($line['proration'] ?? data_get($line, 'parent.subscription_item_details.proration')
                    ?? data_get($line, 'parent.invoice_item_details.proration', false));
                $lineSubscription = $this->id(data_get($line, 'parent.subscription_item_details.subscription')
                    ?? data_get($line, 'parent.invoice_item_details.subscription') ?? $line['subscription'] ?? null);
                $parentType = data_get($line, 'parent.type');
                $subscriptionLine = $parentType === 'subscription_item_details' || ($line['type'] ?? '') === 'subscription';
                if ($lineSubscription !== $subscription->stripe_subscription_id
                    || (! $subscriptionLine && ! ($parentType === 'invoice_item_details' && $proration))) {
                    continue;
                }
                $lineId = $this->id($line);
                $start = (int) data_get($line, 'period.start', 0);
                $end = (int) data_get($line, 'period.end', 0);
                if ($lineId === '' || (int) ($line['quantity'] ?? 0) !== 1 || $start <= 0 || $end <= $start) {
                    throw new \RuntimeException('The credit invoice has an unsupported plan quantity or billing period.');
                }
                $lineAmount = (int) ($line['amount'] ?? 0);
                // Stripe's signed proration line amount supplies the paid fraction for
                // both the old plan's negative and the new plan's positive adjustment.
                $amount = $proration ? $this->ratio($lineAmount, $plan['monthly_credit_cents'], $plan['price_cents'])
                    : ($lineAmount >= 0 ? $plan['monthly_credit_cents'] : 0);
                $this->write($subscription->id, $invoiceId, 'invoice', $invoiceId.':'.$lineId, [
                    'plan_code' => $plan['code'], 'amount_cents' => $amount, 'currency' => 'usd',
                    'period_start' => CarbonImmutable::createFromTimestampUTC($start),
                    'expires_at' => CarbonImmutable::createFromTimestampUTC($end),
                ]);
            }
            $this->reconcileRefunds($subscription, $invoice);
        }
    }

    public function summary(ListingStudioSubscription $subscription): array
    {
        $end = $subscription->current_period_end;
        $start = $subscription->current_period_start;
        $earned = 0;
        $used = 0;
        // A billing-anchor reset must not carry unused credits into the new period.
        if ($end && $end->isFuture() && (! $start || $start->lte(now()))) {
            $entries = DB::table('listing_studio_credit_entries')->where('subscription_id', $subscription->id)
                ->where('expires_at', $end)->where('period_start', '<=', now())->get(['source_type', 'amount_cents']);
            $earned = (int) $entries->where('source_type', '!=', 'usage')->sum('amount_cents');
            $used = -(int) $entries->where('source_type', 'usage')->sum('amount_cents');
        }

        return [
            'currency' => 'usd', 'available_cents' => max(0, $earned - $used),
            'earned_cents' => max(0, $earned), 'used_cents' => $used,
            'expires_at' => $end?->toIso8601String(),
            'monthly_allowance_cents' => (int) config('listing-studio.plans.'.$subscription->plan_code.'.monthly_credit_cents', 0),
        ];
    }

    private function reconcileRefunds(ListingStudioSubscription $subscription, array $invoice): void
    {
        $invoiceId = $this->id($invoice);
        $paid = (int) ($invoice['amount_paid'] ?? 0);
        $refunded = (int) DB::table('listing_studio_subscription_refunds')->where([
            'stripe_account_id' => $subscription->stripe_account_id, 'livemode' => $subscription->livemode,
            'stripe_subscription_id' => $subscription->stripe_subscription_id,
            'stripe_customer_id' => $subscription->stripe_customer_id,
            'stripe_invoice_id' => $invoiceId, 'currency' => 'usd', 'status' => 'succeeded',
        ])->sum('amount_cents');
        $entries = DB::table('listing_studio_credit_entries')->where([
            'subscription_id' => $subscription->id, 'stripe_invoice_id' => $invoiceId, 'source_type' => 'invoice',
        ])->get();
        foreach ($entries->groupBy('expires_at') as $end => $periodEntries) {
            $earned = max(0, (int) $periodEntries->sum('amount_cents'));
            // Recompute one aggregate adjustment rather than round each partial refund
            // independently. Failed/pending refunds do not remove credits.
            $reversal = $paid > 0 ? $this->ratio(min($refunded, $paid), $earned, $paid) : 0;
            $this->write($subscription->id, $invoiceId, 'refund', $invoiceId.':'.$end, [
                'plan_code' => null, 'amount_cents' => -$reversal, 'currency' => 'usd',
                'period_start' => $periodEntries->min('period_start'), 'expires_at' => $end,
            ]);
        }
    }

    private function write(int $subscriptionId, string $invoiceId, string $type, string $key, array $values): void
    {
        $identity = ['subscription_id' => $subscriptionId, 'source_type' => $type, 'source_key' => $key];
        $existing = DB::table('listing_studio_credit_entries')->where($identity)->first();
        DB::table('listing_studio_credit_entries')->updateOrInsert($identity, $values + [
            'stripe_invoice_id' => $invoiceId, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function plan(string $priceId, bool $live): ?array
    {
        foreach (config('listing-studio.plans', []) as $code => $plan) {
            if ($priceId !== '' && $priceId === ($plan[$live ? 'live_price' : 'test_price'] ?? null)
                && (int) ($plan['price_cents'] ?? 0) > 0 && (int) ($plan['monthly_credit_cents'] ?? 0) > 0) {
                return $plan + ['code' => $code];
            }
        }

        return null;
    }

    private function ratio(int $amount, int $credits, int $denominator): int
    {
        if ($denominator <= 0 || abs($amount) > 1_000_000_000 || $credits > 1_000_000_000) {
            throw new \RuntimeException('The credit adjustment is outside supported limits.');
        }
        $result = intdiv(abs($amount) * $credits + intdiv($denominator, 2), $denominator);

        return $amount < 0 ? -$result : $result;
    }

    private function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }
}
