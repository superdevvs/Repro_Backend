<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioCreditReservation;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Financial mutation methods require the caller's short LockedWrite transaction. */
class ListingStudioCreditReservationService
{
    public function __construct(private readonly ListingStudioCreditService $credits) {}

    public function selection(User $client): array
    {
        $rows = ListingStudioSubscription::where('client_id', $client->id)
            ->where('stripe_account_id', config('listing-studio.stripe_account_id'))
            ->where('livemode', config('listing-studio.stripe_mode') === 'live')
            ->orderByDesc('current_period_end')->orderByDesc('id')->get();
        $current = $rows->filter(fn ($row) => $row->current_period_end?->isFuture()
            && (! $row->current_period_start || $row->current_period_start->lte(now())));
        $eligible = $current->filter(fn ($row) => $row->sync_status === 'synced'
            && array_key_exists($row->plan_code, config('listing-studio.plans', []))
            && $this->credits->summary($row)['earned_cents'] > 0);
        $attention = $eligible->count() > 1 || $current->contains(fn ($row) => $row->sync_status === 'needs_attention');
        $creditSubscription = $attention ? null : $eligible->first();

        return ['subscription' => $creditSubscription ?? $rows->first(), 'creditSubscription' => $creditSubscription,
            'billingAttention' => $attention, 'hasOpenSubscription' => $rows->contains(fn ($row) => ! in_array($row->status, ['canceled', 'incomplete_expired'], true))];
    }

    public function reserve(ListingStudioOrder $order, ListingStudioSubscription $subscription, int $amount): ListingStudioCreditReservation
    {
        $this->assertTransaction();
        if ($existing = ListingStudioCreditReservation::where('order_id', $order->id)->first()) {
            if ($existing->subscription_id !== $subscription->id || $existing->amount_cents !== $amount) {
                throw new PublicApiException('This order already has a different credit reservation.', 'idempotency_conflict', 409);
            }

            return $existing;
        }
        $subscription = $subscription->fresh();
        if (! $subscription || (int) $subscription->client_id !== (int) $order->client_id
            || ! $subscription->current_period_end?->isFuture() || $amount < 1) {
            throw new PublicApiException('The editing credit period changed. Review a new quote.', 'quote_stale', 409, ['requiresNewQuote' => true]);
        }
        $available = $this->credits->summary($subscription)['available_cents'];
        if ($amount > $available) {
            throw new PublicApiException('Your available editing credit changed. Review payment options and try again.',
                'insufficient_credits', 409, ['quoteId' => $order->quote_id, 'shortfallCents' => $amount - $available,
                    'availableCreditCents' => $available, 'requiresNewQuote' => false]);
        }
        $entries = DB::table('listing_studio_credit_entries')->where('subscription_id', $subscription->id)
            ->where('expires_at', $subscription->current_period_end)->where('period_start', '<=', now())->orderBy('id')->get();
        $reserved = ListingStudioCreditReservation::where('subscription_id', $subscription->id)
            ->where('status', 'reserved')->where('expires_at', $subscription->current_period_end)->get();
        $remaining = $amount;
        $allocations = [];
        foreach ($entries->groupBy('stripe_invoice_id') as $invoiceId => $lot) {
            $held = $reserved->sum(fn ($reservation) => collect($reservation->allocations)
                ->where('invoiceId', $invoiceId)->sum('amountCents'));
            $availableLot = max(0, (int) $lot->sum('amount_cents') - $held);
            $take = min($remaining, $availableLot);
            if ($take > 0) {
                $allocations[] = ['invoiceId' => $invoiceId, 'amountCents' => $take];
                $remaining -= $take;
            }
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining !== 0) {
            throw new PublicApiException('Editing credit is being reconciled. Please try again shortly.', 'account_ineligible', 409);
        }

        return ListingStudioCreditReservation::create(['order_id' => $order->id, 'subscription_id' => $subscription->id,
            'amount_cents' => $amount, 'allocations' => $allocations, 'status' => 'reserved',
            'period_start' => $subscription->current_period_start ?? $entries->min('period_start'),
            'expires_at' => $subscription->current_period_end]);
    }

    public function capture(ListingStudioOrder $order): void
    {
        $this->assertTransaction();
        $reservation = ListingStudioCreditReservation::where('order_id', $order->id)->firstOrFail();
        if ($reservation->status === 'captured') {
            return;
        }
        if ($reservation->status !== 'reserved') {
            throw new PublicApiException('This order no longer has reserved editing credit.', 'payment_required', 409);
        }
        foreach ($reservation->allocations as $allocation) {
            DB::table('listing_studio_credit_entries')->insert([
                'subscription_id' => $reservation->subscription_id, 'stripe_invoice_id' => $allocation['invoiceId'],
                'source_type' => 'usage', 'source_key' => 'order:'.$order->id.':'.$allocation['invoiceId'],
                'plan_code' => $order->pricing_snapshot['planCode'] ?? null,
                'amount_cents' => -(int) $allocation['amountCents'], 'currency' => 'usd',
                'period_start' => $reservation->period_start, 'expires_at' => $reservation->expires_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $reservation->update(['status' => 'captured', 'captured_at' => now()]);
    }

    public function release(ListingStudioOrder $order): void
    {
        $this->assertTransaction();
        $reservation = ListingStudioCreditReservation::where('order_id', $order->id)->firstOrFail();
        if ($reservation->status === 'released') {
            return;
        }
        if ($reservation->status !== 'reserved') {
            throw new PublicApiException('Completed editing credit cannot be released as an unused reservation.', 'revision_state_conflict', 409);
        }
        $reservation->update(['status' => 'released', 'released_at' => now()]);
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Listing Studio credit mutations require the core financial transaction.');
        }
    }
}
