<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\ListingStudioBillingRequest as BillingRequest;
use App\Models\ListingStudioCheckoutAttempt;
use App\Models\ListingStudioOrder;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListingStudioBillingRequestService
{
    public function create(User $actor, User $client, array $input, string $key): array
    {
        ListingStudioDelegatedAccess::assert($actor, $client);
        $kind = $input['kind'] ?? '';
        $orderId = $input['orderId'] ?? null;
        $plan = $input['planCode'] ?? null;
        if (! Str::isUuid($key) || ! in_array($kind, ['order_payment', 'subscription_purchase', 'subscription_upgrade'], true)
            || ($kind === 'order_payment' ? (! Str::isUuid($orderId) || $plan !== null) : ($orderId !== null || ! in_array($plan, ['starter', 'pro', 'studio'], true)))) {
            throw new PublicApiException('Choose a supported payment request.', 'validation_failed', 422);
        }
        $hash = hash('sha256', json_encode([$kind, $orderId, $plan], JSON_THROW_ON_ERROR));

        return $this->write(function () use ($actor, $client, $kind, $orderId, $plan, $key, $hash) {
            // A write before any snapshot serializes SQLite replay and active-request limits.
            DB::table('users')->where('id', $client->id)->update(['updated_at' => DB::raw('updated_at')]);
            $actor = User::findOrFail($actor->id);
            $client = User::findOrFail($client->id);
            ListingStudioDelegatedAccess::assert($actor, $client);
            $prior = BillingRequest::where('client_id', $client->id)->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($prior) {
                if (! hash_equals($prior->request_hash, $hash)) {
                    throw new PublicApiException('This request key belongs to another purchase.', 'idempotency_conflict', 409);
                }

                return ['request' => $prior, 'created' => false];
            }
            ListingStudioDelegatedAccess::assert($actor, $client, true);
            if (! app(ListingStudioCatalogService::class)->checkoutAvailable()) {
                throw new PublicApiException('Card checkout is temporarily unavailable.', 'checkout_unavailable', 503);
            }
            $selection = app(ListingStudioCreditReservationService::class)->selection($client);
            if ($selection['billingAttention']) {
                throw new PublicApiException('This account needs billing review before another purchase.', 'billing_attention', 409);
            }
            $active = BillingRequest::where('client_id', $client->id)->where(function ($query): void {
                $query->whereIn('status', ['processing', 'needs_attention'])
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '>', now()));
            });
            if ((clone $active)->count() >= max(1, (int) config('listing-studio.billing_request_limit', 20))) {
                throw new PublicApiException('Resolve an existing payment request first.', 'billing_request_limit', 409);
            }
            $subscriptionId = null;
            if ($kind === 'order_payment') {
                $order = ListingStudioDelegatedAccess::order($actor, $client, $orderId);
                if ($order->funding_method !== 'card' || $order->funding_status !== 'pending' || $order->paid_amount_cents !== 0 || $order->item?->status !== 'awaiting_funding') {
                    throw new PublicApiException('This order is not awaiting payment.', 'billing_request_conflict', 409);
                }
                if ((clone $active)->where('order_id', $orderId)->exists()) {
                    throw new PublicApiException('This order already has a payment request.', 'billing_request_conflict', 409);
                }
                $amount = $order->total_cents;
            } else {
                if ((clone $active)->whereIn('kind', ['subscription_purchase', 'subscription_upgrade'])->exists()) {
                    throw new PublicApiException('Resolve the existing plan request first.', 'billing_request_conflict', 409);
                }
                if ($kind === 'subscription_purchase' && $selection['hasOpenSubscription']) {
                    throw new PublicApiException('This client already has a subscription.', 'subscription_exists', 409);
                }
                if ($kind === 'subscription_upgrade') {
                    abort_unless($actor->role === 'superadmin', 403);
                    if (! config('listing-studio.subscription_controls_enabled', false)) {
                        throw new PublicApiException('Subscription changes are temporarily unavailable.', 'subscription_actions_unavailable', 503);
                    }
                    $subscription = $selection['creditSubscription'];
                    $rank = ['starter' => 1, 'pro' => 2, 'studio' => 3];
                    if (! $subscription || ($rank[$plan] ?? 0) <= ($rank[$subscription->plan_code] ?? 99)) {
                        throw new PublicApiException('Choose a higher plan for an active subscription.', 'billing_request_conflict', 409);
                    }
                    $subscriptionId = $subscription->id;
                }
                $amount = $kind === 'subscription_upgrade' ? null : (int) config('listing-studio.plans.'.$plan.'.price_cents');
            }
            $request = BillingRequest::create(['client_id' => $client->id, 'actor_id' => $actor->id, 'kind' => $kind,
                'order_id' => $orderId, 'subscription_id' => $subscriptionId, 'plan_code' => $plan,
                'request_key' => $key, 'request_hash' => $hash, 'checkout_key' => (string) Str::uuid(),
                'amount_cents' => $amount, 'currency' => 'usd', 'status' => 'pending', 'expires_at' => now()->addDay()]);
            UserActivityLog::record($client, 'listing_studio.payment_requested', 'Listing Studio payment requested', null, $actor,
                ['requestId' => $request->id, 'kind' => $kind, 'orderId' => $orderId, 'planCode' => $plan]);

            return ['request' => $request, 'created' => true];
        });
    }

    public function owned(User $client, string $id): BillingRequest
    {
        ListingStudioClientAccess::assert($client);

        return BillingRequest::where('client_id', $client->id)->findOrFail($id);
    }

    public function managed(User $actor, User $client, string $id): BillingRequest
    {
        ListingStudioDelegatedAccess::assert($actor, $client);

        return BillingRequest::where('client_id', $client->id)->where('actor_id', $actor->id)->findOrFail($id);
    }

    public function review(User $client, string $id): array
    {
        $this->owned($client, $id);
        $lock = Cache::store('database')->lock('listing-studio-billing-confirm:'.$id, 120);
        if (! $lock->get()) {
            throw new PublicApiException('Confirmation is still processing. Refresh this request.', 'billing_request_in_progress', 409);
        }
        try {
            return $this->performReview($client, $id);
        } finally {
            $lock->release();
        }
    }

    private function performReview(User $client, string $id): array
    {
        $request = $this->refresh($this->owned($client, $id), true);
        if (in_array($request->status, ['completed', 'expired', 'cancelled'], true)) {
            return ['request' => $this->present($request, true), 'review' => null];
        }
        if ($request->kind === 'subscription_upgrade') {
            $result = app(StudioSubscriptionActionService::class)->review($client, $request);
            $this->applyResult($request, $result);

            return ['request' => $this->present($request->fresh(), true), 'review' => $result['review'] ?? null,
                'status' => $result['status'] ?? $request->status, 'nextAction' => $result['nextAction'] ?? ['type' => 'none'],
                ...(! empty($result['url']) ? ['url' => $result['url']] : [])];
        }
        $price = $this->currentPrice($request);
        $attempt = ListingStudioCheckoutAttempt::where('client_id', $client->id)->where('idempotency_key', $request->checkout_key)->first();
        $accepted = $attempt && ($attempt->submission_started_at || $attempt->session_id);
        if ($request->confirmed_at && $request->preview && $accepted) {
            $preview = $request->preview;
        } else {
            $preview = $this->write(function () use ($request, $price) {
                $fresh = BillingRequest::findOrFail($request->id);
                if (! in_array($fresh->status, ['pending', 'processing', 'needs_attention'], true) || $fresh->expires_at->isPast()) {
                    throw new PublicApiException('This request is no longer available.', 'billing_request_expired', 410);
                }
                $preview = $fresh->preview;
                if (! $preview || ($preview['amountCents'] ?? null) !== $price || now()->gte($preview['expiresAt'])) {
                    $preview = ['reviewId' => (string) Str::uuid(), 'amountCents' => $price, 'currency' => $fresh->currency,
                        'planCode' => $fresh->plan_code, 'expiresAt' => now()->addMinutes(10)->min($fresh->expires_at)->toIso8601String()];
                    $fresh->update(['preview' => $preview]);
                }

                return $preview;
            });
        }
        $preview['confirmationToken'] = $this->token($request, $preview);

        return ['request' => $this->present($request->fresh(), true), 'review' => $preview];
    }

    public function confirm(User $client, string $id, array $confirmation): array
    {
        $request = $this->owned($client, $id);
        $this->assertInitiator($request, $client);
        $lock = Cache::store('database')->lock('listing-studio-billing-confirm:'.$request->id, 120);
        if (! $lock->get()) {
            throw new PublicApiException('Confirmation is still processing. Refresh this request.', 'billing_request_in_progress', 409);
        }
        try {
            $request = $this->refresh($request->fresh(), true);
            if ($request->status === 'completed') {
                return ['request' => $this->present($request, true), 'status' => 'completed', 'nextAction' => ['type' => 'none']];
            }
            if (in_array($request->status, ['expired', 'cancelled'], true)) {
                throw new PublicApiException('This payment request expired or was cancelled.', 'billing_request_expired', 410);
            }
            if (($confirmation['acceptCharges'] ?? null) !== true) {
                throw new PublicApiException('Review and explicitly accept the payment amount.', 'validation_failed', 422);
            }
            if ($request->kind === 'subscription_upgrade') {
                $result = app(StudioSubscriptionActionService::class)->confirm($client, $request, $confirmation);
                $this->applyResult($request, $result);

                return ['request' => $this->present($request->fresh(), true), ...$result];
            }
            $request = $this->write(function () use ($client, $request, $confirmation) {
                DB::table('listing_studio_billing_requests')->where('id', $request->id)->update(['updated_at' => DB::raw('updated_at')]);
                $fresh = $this->owned(User::findOrFail($client->id), $request->id);
                $this->assertInitiator($fresh, User::findOrFail($client->id));
                $preview = $fresh->preview;
                if (! $preview || ($confirmation['reviewId'] ?? '') !== ($preview['reviewId'] ?? '')
                    || ! hash_equals($this->token($fresh, $preview), (string) ($confirmation['confirmationToken'] ?? ''))) {
                    throw new PublicApiException('Review the current payment amount before confirming.', 'review_stale', 409);
                }
                $attempt = ListingStudioCheckoutAttempt::where('client_id', $client->id)->where('idempotency_key', $fresh->checkout_key)->first();
                $accepted = $attempt && ($attempt->submission_started_at || $attempt->session_id);
                if (! $accepted) {
                    if ($fresh->expires_at->isPast()) {
                        throw new PublicApiException('This payment request expired.', 'billing_request_expired', 410);
                    }
                    if (now()->gte($preview['expiresAt']) || $preview['amountCents'] !== $this->currentPrice($fresh)) {
                        throw new PublicApiException('The price review expired. Review it again before confirming.', 'review_stale', 409);
                    }
                    if (! config('listing-studio.delegated_enabled', false)) {
                        throw new PublicApiException('Client payment requests are temporarily unavailable.', 'delegated_billing_unavailable', 503);
                    }
                }
                if (in_array($fresh->status, ['cancelled', 'expired', 'completed'], true)) {
                    throw new PublicApiException('This payment request is no longer pending.', 'billing_request_conflict', 409);
                }
                $fresh->update(['status' => 'processing', 'confirmed_at' => $fresh->confirmed_at ?? now()]);

                return $fresh;
            });
            // Client consent is durable above. All Stripe calls stay outside SQL transactions.
            $checkout = app(StudioCheckoutService::class);
            $result = $request->kind === 'order_payment'
                ? $checkout->order($client, $request->order_id, $request->checkout_key)
                : $checkout->subscription($client, $request->plan_code, $request->checkout_key);
            $status = in_array($result['status'] ?? '', ['settled', 'paid'], true) ? 'completed'
                : (($result['status'] ?? '') === 'awaiting_reconciliation' ? 'needs_attention' : 'processing');
            $this->applyResult($request, ['status' => $status]);

            return ['request' => $this->present($request->fresh(), true), ...$result];
        } finally {
            $lock->release();
        }
    }

    public function cancel(BillingRequest $request, User $actor): BillingRequest
    {
        $lock = Cache::store('database')->lock('listing-studio-billing-confirm:'.$request->id, 120);
        if (! $lock->get()) {
            throw new PublicApiException('Confirmation is still processing. Refresh this request.', 'billing_request_in_progress', 409);
        }
        try {
            return $this->cancelLocked($request, $actor);
        } finally {
            $lock->release();
        }
    }

    private function cancelLocked(BillingRequest $request, User $actor): BillingRequest
    {
        return $this->closeUnsubmitted($request, 'cancelled', $actor);
    }

    private function hasSubmission(BillingRequest $request): bool
    {
        $attempt = ListingStudioCheckoutAttempt::where('client_id', $request->client_id)->where('idempotency_key', $request->checkout_key)->first();
        $action = $request->kind === 'subscription_upgrade' && class_exists(\App\Models\ListingStudioSubscriptionAction::class)
            ? \App\Models\ListingStudioSubscriptionAction::where('billing_request_id', $request->id)->first() : null;

        return (bool) ($attempt?->submission_started_at || $attempt?->session_id || $attempt?->parameters
            || in_array($attempt?->status, ['submitting', 'awaiting_reconciliation', 'awaiting_payment', 'awaiting_webhook', 'paid'], true)
            || $action?->submitted_at || $action?->steps);
    }

    /** Caller owns the confirmation mutex. No provider or queue work may occur here. */
    private function closeUnsubmitted(BillingRequest $request, string $status, ?User $actor = null): BillingRequest
    {
        return $this->write(function () use ($request, $status, $actor) {
            DB::table('listing_studio_billing_requests')->where('id', $request->id)->update(['updated_at' => DB::raw('updated_at')]);
            $fresh = BillingRequest::findOrFail($request->id);
            if ($actor) {
                $actor = User::findOrFail($actor->id);
                $client = User::findOrFail($fresh->client_id);
                if ((int) $actor->id === (int) $client->id) {
                    ListingStudioClientAccess::assert($client);
                } else {
                    abort_unless((int) $actor->id === (int) $fresh->actor_id, 404);
                    ListingStudioDelegatedAccess::assert($actor, $client);
                }
            }
            if (in_array($fresh->status, ['cancelled', 'expired'], true)) {
                return $fresh;
            }
            if ($fresh->status === 'completed' || $this->hasSubmission($fresh)) {
                if ($status === 'expired') {
                    return $fresh;
                }
                throw new PublicApiException('A submitted payment must be reconciled before cancelling.', 'billing_request_conflict', 409);
            }
            if ($status === 'expired' && $fresh->expires_at->isFuture()) {
                return $fresh;
            }
            // A customer lookup or price failure can leave an allocated attempt with no payment POST.
            // The shared mutex excludes a live confirmation, and the marker check above is authoritative.
            ListingStudioCheckoutAttempt::where('client_id', $fresh->client_id)->where('idempotency_key', $fresh->checkout_key)
                ->whereNull('submission_started_at')->whereNull('session_id')->update(['status' => 'failed']);
            if ($fresh->kind === 'subscription_upgrade' && class_exists(\App\Models\ListingStudioSubscriptionAction::class)) {
                \App\Models\ListingStudioSubscriptionAction::where('billing_request_id', $fresh->id)->whereNull('submitted_at')->update(['status' => 'cancelled']);
            }
            $fresh->update(['status' => $status]);
            if ($actor) {
                UserActivityLog::record(User::findOrFail($fresh->client_id), 'listing_studio.payment_request_cancelled', 'Listing Studio payment request cancelled', null, $actor, ['requestId' => $fresh->id]);
            }

            return $fresh;
        });
    }

    private function expireUnsubmitted(BillingRequest $request, bool $confirmationLockHeld): BillingRequest
    {
        if ($confirmationLockHeld) {
            return $this->closeUnsubmitted($request, 'expired');
        }
        $lock = Cache::store('database')->lock('listing-studio-billing-confirm:'.$request->id, 120);
        if (! $lock->get()) {
            return $request;
        }
        try {
            return $this->closeUnsubmitted($request, 'expired');
        } finally {
            $lock->release();
        }
    }

    public function refresh(BillingRequest $request, bool $confirmationLockHeld = false): BillingRequest
    {
        if (in_array($request->status, ['completed', 'cancelled', 'expired'], true)) {
            return $request;
        }
        if ($request->kind === 'subscription_upgrade' && class_exists(\App\Models\ListingStudioSubscriptionAction::class)) {
            $action = \App\Models\ListingStudioSubscriptionAction::where('billing_request_id', $request->id)->first();
            if ($action?->submitted_at) {
                $state = match ($action->status) {
                    'completed' => 'completed', 'expired', 'cancelled' => 'expired', 'needs_attention' => 'needs_attention',
                    default => 'processing',
                };
                $this->applyResult($request, ['status' => $state]);

                return $request->fresh();
            }
        }
        $attempt = ListingStudioCheckoutAttempt::where('client_id', $request->client_id)->where('idempotency_key', $request->checkout_key)->first();
        $paid = $request->order_id ? ListingStudioOrder::whereKey($request->order_id)->where('client_id', $request->client_id)->where('paid_amount_cents', '>', 0)->exists() : $attempt?->status === 'paid';
        $state = $paid ? 'completed' : (in_array($attempt?->status, ['expired', 'failed'], true) ? 'expired' : $request->status);
        if ($state === $request->status && $request->expires_at->isPast()) {
            return $this->expireUnsubmitted($request, $confirmationLockHeld);
        }
        if ($state !== $request->status) {
            $this->applyResult($request, ['status' => $state]);
        }

        return $request->fresh();
    }

    public function present(BillingRequest $request, bool $forClient = false): array
    {
        $client = User::findOrFail($request->client_id);
        $actor = User::withTrashed()->findOrFail($request->actor_id);
        $email = \App\Models\ListingStudioBillingEmail::where('billing_request_id', $request->id)->latest('created_at')->first();
        $pending = $request->status === 'pending' && $request->expires_at->isFuture();
        $status = $request->status === 'pending' && ! $pending ? 'expired' : $request->status;
        $enabled = (bool) config('listing-studio.delegated_enabled', false);
        $initiatorAllowed = true;
        try {
            $this->assertInitiator($request, $client);
        } catch (\Throwable) {
            $initiatorAllowed = false;
        }

        return ['id' => $request->id, 'kind' => $request->kind, 'status' => $status,
            'client' => ['id' => (string) $client->id, 'name' => $client->name, 'email' => $client->email],
            'actor' => ['id' => (string) $actor->id, 'name' => $actor->name], 'orderId' => $request->order_id,
            'planCode' => $request->plan_code, 'amountCents' => $request->amount_cents, 'currency' => $request->currency,
            'expiresAt' => $request->expires_at->toIso8601String(), 'createdAt' => $request->created_at->toIso8601String(),
            'completedAt' => $request->completed_at?->toIso8601String(), 'appUrl' => $this->appUrl($request),
            'actions' => ['canShare' => ! $forClient && $pending, 'canEmail' => ! $forClient && $pending && $enabled,
                'canCancel' => in_array($status, ['pending', 'processing', 'needs_attention'], true) && ! $this->hasSubmission($request), 'canConfirm' => $forClient && $initiatorAllowed && (($pending && $enabled) || in_array($status, ['processing', 'needs_attention'], true))],
            'email' => ['status' => $email?->status, 'lastSentAt' => $email?->sent_at?->toIso8601String()]];
    }

    public function pending(User $client, ?User $actor = null): array
    {
        BillingRequest::where('client_id', $client->id)->when($actor, fn ($q) => $q->where('actor_id', $actor->id))
            ->whereIn('status', ['pending', 'processing', 'needs_attention'])->oldest()->limit(20)->get()->each(fn ($row) => $this->refresh($row));
        $query = BillingRequest::where('client_id', $client->id)->when($actor, fn ($q) => $q->where('actor_id', $actor->id))->where(fn ($q) => $q->whereIn('status', ['processing', 'needs_attention'])
            ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '>', now())));

        return ['count' => (clone $query)->count(), 'requests' => $query->latest()->limit(10)->get()
            ->map(fn ($row) => $this->present($row, $actor === null))->all()];
    }

    public function appUrl(BillingRequest $request): string
    {
        return rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/').'/listing-studio/payment-requests/'.$request->id;
    }

    /** Called inside Checkout's final short write, after canonical reads and immediately before a provider POST. */
    public function assertCheckoutSubmission(User $client, BillingRequest $request, ListingStudioCheckoutAttempt $attempt): void
    {
        $this->assertInitiator($request, User::findOrFail($client->id));
        if (! config('listing-studio.delegated_enabled', false)) {
            throw new PublicApiException('Client payment requests are temporarily unavailable.', 'delegated_billing_unavailable', 503);
        }
        $matches = $request->kind === 'order_payment'
            ? $attempt->kind === 'order' && $attempt->order_id === $request->order_id
            : $request->kind === 'subscription_purchase' && $attempt->kind === 'subscription' && $attempt->plan_code === $request->plan_code;
        if (! $matches || (int) $request->client_id !== (int) $client->id || (int) $attempt->actor_id !== (int) $request->actor_id || ! $request->confirmed_at
            || ! in_array($request->status, ['processing', 'needs_attention'], true)
            || (! $attempt->submission_started_at && $request->expires_at->isPast())) {
            throw new PublicApiException('This request no longer authorizes a payment.', 'billing_request_conflict', 409);
        }
    }

    private function assertInitiator(BillingRequest $request, User $client): void
    {
        $actor = User::findOrFail($request->actor_id);
        ListingStudioDelegatedAccess::assert($actor, $client);
        if ($request->kind === 'subscription_upgrade' && $actor->role !== 'superadmin') {
            throw new PublicApiException('The initiating account can no longer authorize this change.', 'delegated_billing_forbidden', 403);
        }
    }

    private function currentPrice(BillingRequest $request): int
    {
        if ($request->kind === 'order_payment') {
            $order = ListingStudioOrder::where('client_id', $request->client_id)->findOrFail($request->order_id);

            return $order->total_cents;
        }

        return (int) config('listing-studio.plans.'.$request->plan_code.'.price_cents');
    }

    private function token(BillingRequest $request, array $preview): string
    {
        unset($preview['confirmationToken']);

        return hash_hmac('sha256', $request->id.'|'.$request->client_id.'|'.json_encode($preview, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function applyResult(BillingRequest $request, array $result): void
    {
        $status = $result['status'] ?? null;
        if (! in_array($status, ['pending', 'processing', 'completed', 'expired', 'cancelled', 'needs_attention'], true)) {
            return;
        }
        $this->write(function () use ($request, $status) {
            $fresh = BillingRequest::findOrFail($request->id);
            if (in_array($fresh->status, ['completed', 'cancelled', 'expired'], true)) {
                return;
            }
            if ($status === 'expired') {
                // A stale GET must not expire an operation whose durable submission appeared after its read.
                $attempt = ListingStudioCheckoutAttempt::where('client_id', $fresh->client_id)->where('idempotency_key', $fresh->checkout_key)->first();
                $action = $fresh->kind === 'subscription_upgrade' && class_exists(\App\Models\ListingStudioSubscriptionAction::class)
                    ? \App\Models\ListingStudioSubscriptionAction::where('billing_request_id', $fresh->id)->first() : null;
                $definitive = in_array($attempt?->status, ['expired', 'failed'], true) || in_array($action?->status, ['expired', 'cancelled'], true);
                if (! $definitive && ($fresh->confirmed_at || $action?->submitted_at || $attempt?->submission_started_at || $attempt?->session_id)) {
                    return;
                }
            }
            $fresh->update(['status' => $status, 'confirmed_at' => in_array($status, ['processing', 'completed', 'needs_attention'], true) ? ($fresh->confirmed_at ?? now()) : $fresh->confirmed_at,
                'completed_at' => $status === 'completed' ? ($fresh->completed_at ?? now()) : $fresh->completed_at]);
        });
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-billing-request');
    }
}
