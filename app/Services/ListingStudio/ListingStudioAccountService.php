<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioGuestTrial;
use App\Models\ListingStudioOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListingStudioAccountService
{
    public function __construct(
        private readonly ListingStudioCreditReservationService $reservations,
        private readonly ListingStudioCreditService $credits,
        private readonly ListingStudioCatalogService $catalog,
        private readonly ListingStudioOrderService $orders,
    ) {}

    public function account(User $client): array
    {
        ListingStudioClientAccess::assert($client);
        $selection = $this->reservations->selection($client);
        $subscription = $selection['subscription'];
        $creditSubscription = $selection['creditSubscription'];
        $credit = $creditSubscription ? $this->credits->summary($creditSubscription) : null;
        $trialRows = ListingStudioGuestTrial::where('client_id', $client->id)->get(['service', 'status', 'completed_at']);
        $usage = [];
        foreach (array_keys(config('listing-studio.services', [])) as $service) {
            $rows = $trialRows->where('service', $service);
            $completed = (int) $rows->contains(fn ($trial) => $trial->completed_at || $trial->status === 'completed');
            $reserved = (int) ($completed === 0 && $rows->contains(fn ($trial) => in_array($trial->status, ['queued', 'processing', 'needs_attention'], true)));
            $usage[] = ['service' => $service, 'completed' => $completed, 'reserved' => $reserved, 'remaining' => max(0, 1 - $completed - $reserved)];
        }
        $enabled = (bool) config('listing-studio.paid_enabled', false);

        return ['status' => $selection['billingAttention'] ? 'billing_attention' : ($enabled ? 'ready' : 'unavailable'),
            'subscription' => $subscription ? ['id' => (string) $subscription->id, 'planCode' => $subscription->plan_code,
                'status' => $subscription->status, 'periodStart' => $subscription->current_period_start?->toIso8601String(),
                'periodEnd' => $subscription->current_period_end?->toIso8601String(), 'cancelAtPeriodEnd' => $subscription->cancel_at_period_end] : null,
            'credits' => ['earnedCents' => $credit['earned_cents'] ?? 0, 'usedCents' => $credit['used_cents'] ?? 0,
                'reservedCents' => $credit['reserved_cents'] ?? 0, 'availableCents' => $credit['available_cents'] ?? 0,
                'expiresAt' => $credit['expires_at'] ?? null, 'rollover' => false],
            'revisionDiscountPercent' => $creditSubscription ? (int) config('listing-studio.plans.'.$creditSubscription->plan_code.'.revision_discount_percent', 0) : 0,
            'capabilities' => ['canCreateOrder' => $enabled && ! $selection['billingAttention'], 'canUseCredits' => $enabled && $creditSubscription !== null && ($credit['available_cents'] ?? 0) > 0,
                'canPayByCard' => ! $selection['billingAttention'] && $this->catalog->checkoutAvailable(),
                'canSubscribe' => $this->catalog->checkoutAvailable() && ! $selection['billingAttention'] && ! $selection['hasOpenSubscription'],
                'canManageBilling' => $this->canManageBilling($client)],
            'notice' => $selection['billingAttention'] ? 'Your subscriptions need review before starting another purchase.'
                : (! $enabled ? 'Paid editing is temporarily unavailable. Your order history is still available.' : null),
            'pendingBilling' => app(ListingStudioBillingRequestService::class)->pending($client),
            'subscriptionManagement' => $this->subscriptionManagement($client),
            'trialUsage' => ['allowancePerService' => 1, 'services' => $usage, 'startUrl' => 'https://reprophotos.com/listing-studio']];
    }

    private function subscriptionManagement(User $client): array
    {
        $summary = class_exists(StudioSubscriptionActionService::class) ? app(StudioSubscriptionActionService::class)->summary($client)
            : ['pendingChange' => null];
        // Visibility does not grant the client the superadmin renewal-control routes.
        $summary['capabilities'] = ['canScheduleDowngrade' => false, 'canCancelRenewal' => false,
            'canUndoCancel' => false, 'canUndoDowngrade' => false];

        return $summary;
    }

    public function orders(User $client, array $filters): array
    {
        ListingStudioClientAccess::assert($client);
        $query = ListingStudioOrder::where('client_id', $client->id);
        foreach (['service', 'kind'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['status'])) {
            $query->whereHas('item', fn ($items) => $items->where('status', $filters['status']));
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['perPage'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['orders' => $page->getCollection()->map(fn ($order) => $this->orders->present($order))->all(),
            'pagination' => $this->pagination($page)];
    }

    private function canManageBilling(User $client): bool
    {
        $identity = ['account_id' => config('listing-studio.stripe_account_id'), 'livemode' => config('listing-studio.stripe_mode') === 'live'];
        if (\App\Models\ListingStudioStripeCustomer::where('client_id', $client->id)->where($identity)->whereNotNull('customer_id')->exists()) {
            return true;
        }
        $subscriptions = \App\Models\ListingStudioSubscription::where('client_id', $client->id)
            ->where('stripe_account_id', $identity['account_id'])->where('livemode', $identity['livemode'])
            ->pluck('stripe_customer_id')->filter()->unique();

        return $subscriptions->count() === 1 && ! \App\Models\ListingStudioSubscription::where('stripe_account_id', $identity['account_id'])
            ->where('livemode', $identity['livemode'])->where('stripe_customer_id', $subscriptions->first())->where('client_id', '!=', $client->id)->exists();
    }

    public function transactions(User $client, int $pageNumber = 1, int $perPage = 20): array
    {
        ListingStudioClientAccess::assert($client);
        $credit = DB::table('listing_studio_credit_entries as c')->join('listing_studio_subscriptions as s', 's.id', '=', 'c.subscription_id')
            ->where('s.client_id', $client->id)->selectRaw("'credit' AS record_type, CAST(c.id AS TEXT) AS record_id, c.source_type AS kind, 'succeeded' AS status,
                c.amount_cents, c.plan_code, c.source_key, c.subscription_id, NULL AS order_id, c.created_at AS occurred_at, c.expires_at");
        $card = DB::table('listing_studio_order_funding_effects as e')->join('listing_studio_orders as o', 'o.id', '=', 'e.order_id')
            ->where('o.client_id', $client->id)->selectRaw("'card' AS record_type, e.id AS record_id, e.kind, e.status,
                CASE WHEN e.kind = 'payment' THEN -e.amount_cents ELSE e.amount_cents END AS amount_cents,
                NULL AS plan_code, NULL AS source_key, NULL AS subscription_id, e.order_id, e.occurred_at, NULL AS expires_at");
        $page = DB::query()->fromSub($credit->unionAll($card), 'studio_transactions')->orderByDesc('occurred_at')->orderByDesc('record_id')
            ->paginate(min(100, max(1, $perPage)), ['*'], 'page', max(1, $pageNumber));
        $rows = $page->getCollection();
        $referenced = $rows->map(function ($row) {
            if ($row->record_type === 'credit' && preg_match('/\Aorder:([a-f0-9-]{36}):/', $row->source_key ?? '', $match)) {
                return $match[1];
            }

            return null;
        })->filter()->values();
        $ownedOrders = ListingStudioOrder::where('client_id', $client->id)->whereIn('id', $referenced)->pluck('id')->all();
        $result = $rows->map(function ($row) use ($ownedOrders) {
            $orderId = $row->order_id;
            if ($row->record_type === 'credit') {
                $kind = match ($row->kind) {
                    'usage' => (int) $row->amount_cents < 0 ? 'credit_usage' : 'credit_refund',
                    'invoice' => (int) $row->amount_cents > 0 ? 'credit_grant' : 'credit_adjustment',
                    default => 'credit_adjustment',
                };
                if (preg_match('/\Aorder:([a-f0-9-]{36}):/', $row->source_key ?? '', $match) && in_array($match[1], $ownedOrders, true)) {
                    $orderId = $match[1];
                }
                $description = $kind === 'credit_grant' ? (config('listing-studio.plans.'.$row->plan_code.'.name', 'Plan').' editing credit')
                    : match ($kind) {
                        'credit_usage' => 'Editing credit used', 'credit_refund' => 'Editing credit returned', default => 'Editing credit adjustment'
                    };
            } else {
                $kind = $row->kind === 'payment' ? 'card_charge' : 'card_refund';
                $description = $kind === 'card_charge' ? 'Studio order payment' : 'Studio order refund';
            }

            return ['id' => $row->record_type.':'.$row->record_id, 'kind' => $kind,
                'status' => $row->status === 'canceled' ? 'failed' : $row->status, 'amountCents' => (int) $row->amount_cents,
                'balanceType' => $row->record_type === 'credit' ? 'editing_credit' : 'card', 'currency' => 'usd',
                'description' => $description, 'orderId' => $orderId, 'subscriptionId' => $row->subscription_id === null ? null : (string) $row->subscription_id,
                'createdAt' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
                'expiresAt' => $row->expires_at ? CarbonImmutable::parse($row->expires_at)->toIso8601String() : null];
        })->all();

        return ['transactions' => $result, 'pagination' => $this->pagination($page)];
    }

    private function pagination(LengthAwarePaginator $page): array
    {
        return ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'hasMore' => $page->hasMorePages()];
    }
}
