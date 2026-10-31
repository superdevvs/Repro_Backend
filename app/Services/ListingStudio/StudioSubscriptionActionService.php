<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioBillingRequest;
use App\Models\ListingStudioSubscription;
use App\Models\ListingStudioSubscriptionAction as Action;
use App\Models\User;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Client-confirmed upgrades and superadmin renewal controls. No network within a write transaction. */
class StudioSubscriptionActionService
{
    private const TERMINAL = ['completed', 'expired', 'cancelled'];

    public function __construct(private readonly StudioStripeGateway $gateway) {}

    public function review(User $client, ListingStudioBillingRequest $request): array
    {
        $this->requestOwner($client, $request);
        $existing = Action::where('billing_request_id', $request->id)->first();
        $record = $this->subscription($client, $request->subscription_id, (bool) $existing?->submitted_at);

        return $this->locked($record, function () use ($client, $request, $record, $existing) {
            if ($existing?->submitted_at) {
                return $this->recoverUpgrade($existing->fresh());
            }
            $this->liveRequest($request);
            $canonical = $this->canonical($record);
            $this->unchangedPaidSubscription($record, $canonical);
            $this->noRemoteConflict($canonical);
            $plan = (string) $request->plan_code;
            $this->direction($record->plan_code, $plan, true);
            $target = $this->validatedPrice($plan);
            $this->noLocalConflict($record, $existing?->id);
            $snapshot = $this->snapshot($canonical) + ['prorationDate' => now()->timestamp];
            $token = bin2hex(random_bytes(32));
            $action = $this->write(function () use ($client, $request, $record, $existing, $target, $snapshot, $plan) {
                $this->requestOwner($client->fresh(), $request->fresh());
                $this->liveRequest($request->fresh());
                $this->noLocalConflict($record, $existing?->id);
                $values = $this->identity($record, $snapshot) + ['billing_request_id' => $request->id,
                    'actor_id' => $request->actor_id, 'request_key' => $request->checkout_key,
                    'request_hash' => hash('sha256', $request->id.':'.$plan), 'kind' => 'upgrade',
                    'plan_code' => $plan, 'target_price_id' => $target['id'], 'status' => 'reviewing',
                    'snapshot' => $snapshot, 'error_code' => null];
                if ($existing) {
                    $fresh = $existing->fresh();
                    if ($fresh->submitted_at) {
                        throw new StudioPaymentException('subscription_in_progress', 'This subscription change is already in progress.');
                    }
                    $fresh->fill($values)->save();

                    return $fresh;
                }

                return Action::create($values);
            });
            $preview = $this->gateway->previewSubscription($this->previewParameters($action));
            $description = StudioSubscriptionInvoice::describe($action, $preview);
            $expires = now()->addMinutes(10)->min($request->expires_at)->min(CarbonImmutable::createFromTimestampUTC($snapshot['periodEnd']));
            $this->write(fn () => $action->update(['preview' => $description, 'confirmation_token_hash' => hash('sha256', $token), 'review_expires_at' => $expires]));

            return ['status' => 'pending', 'nextAction' => ['type' => 'confirm_subscription_upgrade'], 'review' => [
                'reviewId' => $action->id, 'confirmationToken' => $token, 'currentPlanCode' => $action->current_plan_code,
                'planCode' => $action->plan_code, 'currency' => $description['currency'], 'amountCents' => $description['amountCents'],
                'additionalCreditCents' => $description['additionalCreditCents'], 'prorationDate' => $this->iso($snapshot['prorationDate']),
                'monthlyPriceCents' => (int) config('listing-studio.plans.'.$plan.'.price_cents'),
                'monthlyCreditCents' => (int) config('listing-studio.plans.'.$plan.'.monthly_credit_cents'),
                'periodEnd' => $this->iso($snapshot['periodEnd']), 'expiresAt' => $expires->toIso8601String(),
            ]];
        });
    }

    public function confirm(User $client, ListingStudioBillingRequest $request, array $confirmation): array
    {
        $this->requestOwner($client, $request);
        $action = Action::where('billing_request_id', $request->id)->where('client_id', $client->id)->first();
        if (! $action || ($confirmation['reviewId'] ?? null) !== $action->id) {
            throw new StudioPaymentException('subscription_review_required', 'Review the subscription adjustment before confirming.');
        }
        $record = $this->subscription($client, $action->subscription_id, (bool) $action->submitted_at);

        return $this->locked($record, function () use ($client, $request, $confirmation, $action, $record) {
            $action = $action->fresh();
            if ($action->submitted_at) {
                return $this->recoverUpgrade($action);
            }
            $this->enabled(true);
            $this->superadmin(User::findOrFail($request->actor_id));
            $this->liveRequest($request->fresh(), true);
            if (($confirmation['acceptCharges'] ?? null) !== true || ! $action->review_expires_at?->isFuture()
                || ! is_string($confirmation['confirmationToken'] ?? null) || ! $action->confirmation_token_hash
                || ! hash_equals($action->confirmation_token_hash, hash('sha256', $confirmation['confirmationToken']))) {
                throw new StudioPaymentException('subscription_review_expired', 'Review the current adjustment and explicitly confirm the charge.', 409);
            }
            $canonical = $this->canonical($record);
            $this->unchangedPaidSubscription($record, $canonical);
            $this->noRemoteConflict($canonical);
            $this->sameSnapshot($action, $canonical);
            $this->validatedPrice($action->plan_code);
            $preview = StudioSubscriptionInvoice::describe($action, $this->gateway->previewSubscription($this->previewParameters($action)));
            if (! hash_equals($action->preview['fingerprint'], $preview['fingerprint'])) {
                throw new StudioPaymentException('subscription_review_changed', 'The adjustment changed. Review the new amount before confirming.');
            }
            $this->write(function () use ($client, $request, $action, $record) {
                $this->enabled(true);
                $this->superadmin(User::findOrFail($request->actor_id));
                $this->requestOwner($client->fresh(), $request->fresh());
                $this->liveRequest($request->fresh(), true);
                $this->noLocalConflict($record, $action->id);
                $action->update(['confirmed_at' => now(), 'submitted_at' => now(), 'status' => 'submitting',
                    'steps' => ['upgrade' => ['startedAt' => now()->toIso8601String()]]]);
            });
            $parameters = ['items' => [['id' => $action->item_id, 'price' => $action->target_price_id, 'quantity' => 1]],
                'payment_behavior' => 'pending_if_incomplete', 'proration_behavior' => 'always_invoice',
                'billing_cycle_anchor' => 'unchanged', 'proration_date' => $action->snapshot['prorationDate'],
                'expand' => ['customer', 'latest_invoice']];
            try {
                $result = $this->gateway->updateSubscription($action->stripe_subscription_id, $parameters, $this->stripeKey($action, 'upgrade'));
                $this->assertIdentity($action, $result);

                return $this->observeUpgrade($action, $result);
            } catch (\Throwable) {
                $this->attention($action);

                return $this->result($action->fresh());
            }
        });
    }

    public function execute(User $actor, User $client, string $action, string $planCode, string $idempotencyKey): array
    {
        $this->superadmin($actor);
        $this->client($client);
        if (! in_array($action, ['schedule_downgrade', 'cancel_renewal', 'undo_cancel', 'undo_downgrade'], true) || ! Str::isUuid($idempotencyKey)) {
            throw new StudioPaymentException('validation_failed', 'Choose a supported subscription action and request key.', 422);
        }
        $hash = hash('sha256', json_encode([$actor->id, $client->id, $action, $planCode], JSON_THROW_ON_ERROR));
        $prior = Action::where('client_id', $client->id)->where('request_key', $idempotencyKey)->first();
        if ($prior && ! hash_equals($prior->request_hash, $hash)) {
            throw new StudioPaymentException('idempotency_conflict', 'This request key belongs to a different subscription action.');
        }
        $record = $this->subscription($client, $prior?->subscription_id, (bool) $prior?->submitted_at);

        return $this->locked($record, function () use ($actor, $client, $action, $planCode, $idempotencyKey, $hash, $record, $prior) {
            if ($prior?->submitted_at) {
                return $this->recoverAdministrative($prior->fresh());
            }
            $this->enabled();
            $canonical = $this->canonical($record);
            $this->unchangedPaidSubscription($record, $canonical);
            if ($prior) {
                $this->sameSnapshot($prior, $canonical);
            }
            $source = null;
            if (str_starts_with($action, 'undo_')) {
                $source = Action::where('subscription_id', $record->id)->where('kind', $action === 'undo_cancel' ? 'cancel_renewal' : 'schedule_downgrade')
                    ->where('status', 'scheduled')->where('effective_at', '>', now())->latest()->first();
                if (! $source || ! empty($canonical['pending_update'])) {
                    throw new StudioPaymentException('subscription_conflict', 'There is no matching reversible change.');
                }
                if ($action === 'undo_cancel' && (! ($canonical['cancel_at_period_end'] ?? false) || ! empty($canonical['schedule']))) {
                    throw new StudioPaymentException('subscription_conflict', 'The pending cancellation changed.');
                }
                if ($action === 'undo_downgrade' && (StudioSubscriptionInvoice::id($canonical['schedule'] ?? null) !== $source->schedule_id
                    || ($canonical['cancel_at_period_end'] ?? false))) {
                    throw new StudioPaymentException('subscription_conflict', 'The scheduled plan change no longer matches this request.');
                }
                $this->noLocalConflict($record, $prior?->id, $source->id);
            } else {
                $this->noRemoteConflict($canonical);
                $this->noLocalConflict($record, $prior?->id);
            }
            $target = null;
            if ($action === 'schedule_downgrade') {
                $this->direction($record->plan_code, $planCode, false);
                $target = $this->validatedPrice($planCode);
                $this->simpleSchedule($canonical);
            } elseif ($planCode !== '') {
                throw new StudioPaymentException('validation_failed', 'This action does not accept a target plan.', 422);
            }
            $snapshot = $this->snapshot($canonical) + ['reversesActionId' => $source?->id];
            $operation = $this->write(function () use ($actor, $client, $record, $action, $target, $snapshot, $planCode, $idempotencyKey, $hash, $source, $prior) {
                $this->enabled();
                $this->superadmin($actor->fresh());
                $this->client($client->fresh());
                $this->noLocalConflict($record, $prior?->id, $source?->id);

                return Action::firstOrCreate(['client_id' => $client->id, 'request_key' => $idempotencyKey], $this->identity($record, $snapshot) + [
                    'actor_id' => $actor->id, 'request_hash' => $hash, 'kind' => $action, 'status' => 'prepared',
                    'plan_code' => $planCode ?: null, 'target_price_id' => $target['id'] ?? null, 'snapshot' => $snapshot,
                    'schedule_id' => $source?->schedule_id, 'effective_at' => $this->iso($snapshot['periodEnd']),
                ]);
            });
            try {
                if ($action === 'schedule_downgrade') {
                    $this->markStep($operation, 'create_schedule');
                    $schedule = $this->gateway->createSchedule($record->stripe_subscription_id, $this->stripeKey($operation, 'create_schedule'));
                    $this->verifyScheduleIdentity($operation, $schedule);
                    $this->write(fn () => $operation->update(['schedule_id' => $schedule['id']]));

                    return $this->configureSchedule($operation->fresh(), $schedule);
                }
                if ($action === 'undo_downgrade') {
                    $schedule = $this->gateway->schedule($operation->schedule_id);
                    $this->verifyScheduleIdentity($source, $schedule);
                    if (! $this->matchesSchedule($source, $schedule)) {
                        throw new StudioPaymentException('subscription_conflict', 'The schedule was changed outside this request.');
                    }
                    $this->markStep($operation, 'release_schedule');
                    $released = $this->gateway->releaseSchedule($operation->schedule_id, $this->stripeKey($operation, 'release_schedule'));
                    $this->verifyScheduleIdentity($operation, $released, true);
                    if (($released['status'] ?? '') !== 'released') {
                        throw new \RuntimeException('Schedule release remains unresolved.');
                    }
                    $this->finishAdministrative($operation, 'completed');

                    return $this->result($operation->fresh());
                }
                $this->markStep($operation, 'renewal');
                $updated = $this->gateway->updateSubscription($record->stripe_subscription_id,
                    ['cancel_at_period_end' => $action === 'cancel_renewal', 'expand' => ['customer', 'latest_invoice']], $this->stripeKey($operation, 'renewal'));
                $this->assertIdentity($operation, $updated);

                return $this->observeRenewal($operation, $updated);
            } catch (\Throwable) {
                $this->attention($operation);

                return $this->result($operation->fresh());
            }
        });
    }

    public function summary(User $client): array
    {
        $rows = ListingStudioSubscription::where('client_id', $client->id)->where('stripe_account_id', config('listing-studio.stripe_account_id'))
            ->where('livemode', config('listing-studio.stripe_mode') === 'live')->whereNotIn('status', ['canceled', 'incomplete_expired'])->get();
        $subscription = $rows->count() === 1 ? $rows->first() : null;
        $pending = $subscription ? Action::where('subscription_id', $subscription->id)->whereNotIn('status', self::TERMINAL)
            ->where(fn ($q) => $q->where('status', '!=', 'reviewing')->orWhere('review_expires_at', '>', now()))->latest()->first() : null;
        $enabled = (bool) config('listing-studio.subscription_controls_enabled') && $subscription?->sync_status === 'synced'
            && $subscription?->current_period_end?->isFuture() && $subscription?->status === 'active';
        $reversible = $pending?->status === 'scheduled' && $pending?->effective_at?->isFuture();

        return ['pendingChange' => $pending ? $this->present($pending) : null, 'capabilities' => [
            'canScheduleDowngrade' => $enabled && ! $pending && ! $subscription->cancel_at_period_end && $subscription->plan_code !== 'starter',
            'canCancelRenewal' => $enabled && ! $pending && ! $subscription->cancel_at_period_end,
            'canUndoCancel' => $enabled && $reversible && $pending->kind === 'cancel_renewal',
            'canUndoDowngrade' => $enabled && $reversible && $pending->kind === 'schedule_downgrade',
        ]];
    }

    private function configureSchedule(Action $action, array $schedule): array
    {
        $this->verifyScheduleIdentity($action, $schedule);
        $this->sameSnapshot($action, $this->canonical(ListingStudioSubscription::findOrFail($action->subscription_id)), true);
        $phases = $schedule['phases'] ?? [];
        if (count($phases) !== 1 || StudioSubscriptionInvoice::id(data_get($phases, '0.items.0.price')) !== $action->source_price_id
            || count(data_get($phases, '0.items', [])) !== 1 || (int) data_get($phases, '0.items.0.quantity') !== 1) {
            throw new StudioPaymentException('subscription_conflict', 'The existing schedule requires manual review.');
        }
        $start = (int) data_get($schedule, 'current_phase.start_date');
        if ($start <= 0 || $start > (int) $action->snapshot['periodStart']) {
            throw new StudioPaymentException('subscription_conflict', 'The schedule period changed.');
        }
        $parameters = ['end_behavior' => 'release', 'proration_behavior' => 'none',
            'metadata' => ['studio_subscription_action' => $action->id], 'phases' => [
                ['start_date' => $start, 'end_date' => $action->snapshot['periodEnd'],
                    'items' => [['price' => $action->source_price_id, 'quantity' => 1]], 'proration_behavior' => 'none'],
                ['start_date' => $action->snapshot['periodEnd'], 'duration' => ['interval' => 'month', 'interval_count' => 1],
                    'items' => [['price' => $action->target_price_id, 'quantity' => 1]], 'proration_behavior' => 'none'],
            ]];
        $this->markStep($action, 'configure_schedule');
        $updated = $this->gateway->updateSchedule($action->schedule_id, $parameters, $this->stripeKey($action, 'configure_schedule'));
        $this->verifyScheduleIdentity($action, $updated);
        if (! $this->matchesSchedule($action, $updated)) {
            throw new \RuntimeException('Scheduled update could not be confirmed.');
        }
        $this->finishAdministrative($action, 'scheduled');

        return $this->result($action->fresh());
    }

    private function recoverAdministrative(Action $action): array
    {
        if (in_array($action->status, self::TERMINAL, true)) {
            return $this->result($action);
        }
        $canonical = $this->canonical(ListingStudioSubscription::findOrFail($action->subscription_id));
        $this->assertIdentity($action, $canonical);
        if (in_array($action->kind, ['cancel_renewal', 'undo_cancel'], true)) {
            return $this->observeRenewal($action, $canonical);
        }
        if (! $action->schedule_id) {
            // A lost schedule-create response cannot be proven ours. Never create another schedule.
            $this->attention($action);

            return $this->result($action->fresh());
        }
        $schedule = $this->gateway->schedule($action->schedule_id);
        if ($action->kind === 'undo_downgrade') {
            $this->verifyScheduleIdentity($action, $schedule, true);
            if (($schedule['status'] ?? '') === 'released' && empty($canonical['schedule'])
                && StudioSubscriptionInvoice::id(data_get($canonical, 'items.data.0.price')) === $action->source_price_id) {
                $this->finishAdministrative($action, 'completed');
            } else {
                $this->attention($action);
            }
        } elseif ($this->matchesSchedule($action, $schedule)) {
            $this->verifyScheduleIdentity($action, $schedule);
            $this->finishAdministrative($action, 'scheduled');
        } elseif (! data_get($action->steps, 'configure_schedule.startedAt')) {
            // First create is known; finishing an unsubmitted second step cannot duplicate a payment.
            $this->enabled();

            return $this->configureSchedule($action, $schedule);
        } else {
            $this->attention($action);
        }

        return $this->result($action->fresh());
    }

    private function recoverUpgrade(Action $action): array
    {
        if (in_array($action->status, self::TERMINAL, true)) {
            return $this->result($action);
        }
        $canonical = $this->canonical(ListingStudioSubscription::findOrFail($action->subscription_id));

        return $this->observeUpgrade($action, $canonical);
    }

    private function observeUpgrade(Action $action, array $canonical): array
    {
        $this->assertIdentity($action, $canonical);
        $invoice = $canonical['latest_invoice'] ?? null;
        if ($action->invoice_id && StudioSubscriptionInvoice::id($invoice) !== $action->invoice_id) {
            $invoice = $this->gateway->invoice($action->invoice_id);
        }
        if (! is_array($invoice) || ! preg_match('/^in_[A-Za-z0-9_]+$/', StudioSubscriptionInvoice::id($invoice))
            || StudioSubscriptionInvoice::id($invoice) === data_get($action->snapshot, 'latestInvoiceId')
            || (int) ($invoice['created'] ?? 0) < $action->submitted_at->timestamp - 5 || ! StudioSubscriptionInvoice::matches($action, $invoice)) {
            $this->attention($action);

            return $this->result($action->fresh());
        }
        $paid = ($invoice['status'] ?? '') === 'paid' && (int) ($invoice['amount_remaining'] ?? -1) === 0;
        $price = StudioSubscriptionInvoice::id(data_get($canonical, 'items.data.0.price'));
        $applied = $price === $action->target_price_id && empty($canonical['pending_update']);
        $expired = in_array($invoice['status'] ?? '', ['void', 'uncollectible'], true) && empty($canonical['pending_update']) && $price === $action->source_price_id;
        $status = $expired ? 'expired' : ($paid ? ($applied ? 'awaiting_webhook' : 'awaiting_application') : 'awaiting_payment');
        $this->write(fn () => $action->update(['invoice_id' => $invoice['id'], 'status' => $status,
            'error_code' => $expired ? 'subscription_update_expired' : null]));
        // Browser recovery can display canonical progress; only verified webhook projection grants the plan/credits.
        $url = ! $paid && ! $expired ? $this->invoiceUrl($invoice['hosted_invoice_url'] ?? null) : null;

        return $this->result($action->fresh(), $url);
    }

    private function observeRenewal(Action $action, array $canonical): array
    {
        $this->assertIdentity($action, $canonical);
        $target = $action->kind === 'cancel_renewal';
        if (($canonical['cancel_at_period_end'] ?? null) === $target && empty($canonical['pending_update']) && empty($canonical['schedule'])
            && StudioSubscriptionInvoice::id(data_get($canonical, 'items.data.0.price')) === $action->source_price_id
            && (int) data_get($canonical, 'items.data.0.current_period_end', $canonical['current_period_end'] ?? 0) === (int) $action->snapshot['periodEnd']) {
            $this->finishAdministrative($action, $target ? 'scheduled' : 'completed');
        } else {
            $this->attention($action);
        }

        return $this->result($action->fresh());
    }

    private function finishAdministrative(Action $action, string $status): void
    {
        $this->write(function () use ($action, $status) {
            $action->update(['status' => $status, 'error_code' => null, 'completed_at' => $status === 'completed' ? now() : null]);
            if ($status === 'completed' && ($source = data_get($action->snapshot, 'reversesActionId'))) {
                Action::whereKey($source)->where('client_id', $action->client_id)->where('status', 'scheduled')
                    ->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
        });
    }

    private function markStep(Action $action, string $step): void
    {
        $this->write(function () use ($action, $step) {
            $this->enabled();
            $this->superadmin(User::findOrFail($action->actor_id));
            $this->client(User::findOrFail($action->client_id));
            $fresh = $action->fresh();
            if (data_get($fresh->steps, $step.'.startedAt')) {
                throw new StudioPaymentException('subscription_reconciliation_required', 'Recover the existing action before trying again.');
            }
            $steps = $fresh->steps ?? [];
            $steps[$step] = ['startedAt' => now()->toIso8601String()];
            $action->update(['steps' => $steps, 'submitted_at' => $fresh->submitted_at ?? now(), 'status' => 'submitting']);
        });
    }

    private function canonical(ListingStudioSubscription $record): array
    {
        $account = $this->gateway->account();
        if (($account['id'] ?? '') !== $record->stripe_account_id || $record->stripe_account_id !== config('listing-studio.stripe_account_id')
            || $record->livemode !== (config('listing-studio.stripe_mode') === 'live')) {
            throw new StudioPaymentException('billing_attention', 'The billing account configuration changed.');
        }
        $canonical = $this->gateway->subscription($record->stripe_subscription_id);
        if (($canonical['id'] ?? '') !== $record->stripe_subscription_id || ($canonical['livemode'] ?? null) !== $record->livemode
            || StudioSubscriptionInvoice::id($canonical['customer'] ?? null) !== $record->stripe_customer_id) {
            throw new StudioPaymentException('billing_attention', 'The subscription ownership could not be confirmed.');
        }

        return $canonical;
    }

    private function subscription(User $client, int|string|null $id = null, bool $recovery = false): ListingStudioSubscription
    {
        $query = ListingStudioSubscription::where('client_id', $client->id)->where('stripe_account_id', config('listing-studio.stripe_account_id'))
            ->where('livemode', config('listing-studio.stripe_mode') === 'live');
        if ($id && $recovery) {
            return $query->findOrFail($id);
        }
        $rows = $query->whereNotIn('status', ['canceled', 'incomplete_expired'])->get();
        if ($rows->count() !== 1 || ($id && (string) $rows->first()->id !== (string) $id)
            || $rows->first()->sync_status !== 'synced' || $rows->first()->status !== 'active'
            || ! $rows->first()->current_period_end?->isFuture()) {
            throw new StudioPaymentException('billing_attention', 'This client needs one active, synchronized subscription before changing plans.');
        }

        return $rows->first();
    }

    private function unchangedPaidSubscription(ListingStudioSubscription $record, array $canonical): void
    {
        $items = data_get($canonical, 'items.data', []);
        $invoice = $canonical['latest_invoice'] ?? null;
        if (($canonical['status'] ?? '') !== 'active' || ($canonical['collection_method'] ?? '') !== 'charge_automatically'
            || count($items) !== 1 || data_get($canonical, 'items.has_more', false) || ($items[0]['quantity'] ?? null) !== 1
            || StudioSubscriptionInvoice::id($items[0]['price'] ?? null) !== $record->stripe_price_id
            || ! is_array($invoice) || ($invoice['status'] ?? '') !== 'paid'
            || StudioSubscriptionInvoice::id($invoice['customer'] ?? null) !== $record->stripe_customer_id
            || ($invoice['livemode'] ?? null) !== $record->livemode
            || StudioSubscriptionInvoice::id(data_get($invoice, 'parent.subscription_details.subscription') ?? $invoice['subscription'] ?? null) !== $record->stripe_subscription_id
            || (int) ($items[0]['current_period_start'] ?? $canonical['current_period_start'] ?? 0) !== $record->current_period_start?->timestamp
            || (int) ($items[0]['current_period_end'] ?? $canonical['current_period_end'] ?? 0) !== $record->current_period_end?->timestamp) {
            throw new StudioPaymentException('subscription_conflict', 'Subscription billing changed. Wait for synchronization or contact the team.');
        }
    }

    private function noRemoteConflict(array $canonical): void
    {
        if (! empty($canonical['pending_update']) || ! empty($canonical['schedule']) || ($canonical['cancel_at_period_end'] ?? false)
            || ! empty($canonical['cancel_at']) || ! empty($canonical['pause_collection'])) {
            throw new StudioPaymentException('subscription_conflict', 'Resolve the existing pending subscription change first.');
        }
    }

    private function noLocalConflict(ListingStudioSubscription $record, ?string $own = null, ?string $reversed = null): void
    {
        $active = Action::where('subscription_id', $record->id)->whereNotIn('status', self::TERMINAL)
            ->when($own, fn ($q) => $q->where('id', '!=', $own))->when($reversed, fn ($q) => $q->where('id', '!=', $reversed))
            ->where(fn ($q) => $q->where('status', '!=', 'reviewing')->orWhere('review_expires_at', '>', now()));
        if ($active->exists()) {
            throw new StudioPaymentException('subscription_conflict', 'A subscription change is already awaiting completion.');
        }
    }

    private function snapshot(array $canonical): array
    {
        $item = data_get($canonical, 'items.data.0', []);

        return ['itemId' => $item['id'], 'priceId' => StudioSubscriptionInvoice::id($item['price']),
            'periodStart' => (int) ($item['current_period_start'] ?? $canonical['current_period_start'] ?? 0),
            'periodEnd' => (int) ($item['current_period_end'] ?? $canonical['current_period_end'] ?? 0),
            'anchor' => $canonical['billing_cycle_anchor'] ?? null, 'latestInvoiceId' => StudioSubscriptionInvoice::id($canonical['latest_invoice'] ?? null),
            'cancelAtPeriodEnd' => (bool) ($canonical['cancel_at_period_end'] ?? false), 'cancelAt' => $canonical['cancel_at'] ?? null,
            'scheduleId' => StudioSubscriptionInvoice::id($canonical['schedule'] ?? null),
            'settingsHash' => hash('sha256', json_encode([$canonical['discounts'] ?? [], $canonical['default_tax_rates'] ?? [],
                $canonical['automatic_tax'] ?? null, StudioSubscriptionInvoice::id($canonical['default_payment_method'] ?? null),
                $canonical['collection_method'] ?? null, $canonical['currency'] ?? null], JSON_THROW_ON_ERROR))];
    }

    private function sameSnapshot(Action $action, array $canonical, bool $ownedSchedule = false): void
    {
        $actual = $this->snapshot($canonical);
        $expected = array_intersect_key($action->snapshot, $actual);
        if ($ownedSchedule && StudioSubscriptionInvoice::id($canonical['schedule'] ?? null) === $action->schedule_id) {
            $actual['scheduleId'] = $expected['scheduleId'];
        }
        if ($actual !== $expected || ! empty($canonical['pending_update'])) {
            throw new StudioPaymentException('subscription_review_changed', 'The subscription changed. Review it again before continuing.');
        }
    }

    private function identity(ListingStudioSubscription $record, array $snapshot): array
    {
        return ['client_id' => $record->client_id, 'subscription_id' => $record->id, 'account_id' => $record->stripe_account_id,
            'livemode' => $record->livemode, 'customer_id' => $record->stripe_customer_id,
            'stripe_subscription_id' => $record->stripe_subscription_id, 'item_id' => $snapshot['itemId'],
            'current_plan_code' => $record->plan_code, 'source_price_id' => $record->stripe_price_id];
    }

    private function assertIdentity(Action $action, array $canonical): void
    {
        if (($canonical['id'] ?? '') !== $action->stripe_subscription_id || ($canonical['livemode'] ?? null) !== $action->livemode
            || StudioSubscriptionInvoice::id($canonical['customer'] ?? null) !== $action->customer_id) {
            throw new StudioPaymentException('billing_attention', 'Subscription identity could not be confirmed.');
        }
    }

    private function validatedPrice(string $code): array
    {
        if (! in_array($code, ['starter', 'pro', 'studio'], true)) {
            throw new StudioPaymentException('validation_failed', 'Choose a supported plan.', 422);
        }
        $plan = config('listing-studio.plans.'.$code);
        $id = $plan[config('listing-studio.stripe_mode') === 'live' ? 'live_price' : 'test_price'] ?? '';
        if (! is_string($id) || ! preg_match('/^price_[A-Za-z0-9_]+$/', $id)) {
            throw new StudioPaymentException('subscription_unavailable', 'This plan is not configured.', 503);
        }
        $price = $this->gateway->price($id);
        if (($price['id'] ?? '') !== $id || ($price['livemode'] ?? null) !== (config('listing-studio.stripe_mode') === 'live')
            || ($price['active'] ?? null) !== true || ($price['currency'] ?? '') !== $plan['currency']
            || ($price['unit_amount'] ?? null) !== $plan['price_cents'] || data_get($price, 'recurring.interval') !== 'month'
            || data_get($price, 'recurring.interval_count') !== 1) {
            throw new StudioPaymentException('billing_attention', 'The configured plan price needs review.');
        }

        return $price;
    }

    private function direction(string $current, string $target, bool $upgrade): void
    {
        $ranks = ['starter' => 1, 'pro' => 2, 'studio' => 3];
        if (! isset($ranks[$current], $ranks[$target]) || ($upgrade ? $ranks[$target] <= $ranks[$current] : $ranks[$target] >= $ranks[$current])) {
            throw new StudioPaymentException('validation_failed', $upgrade ? 'Choose a higher plan.' : 'Choose a lower plan.', 422);
        }
    }

    private function previewParameters(Action $action): array
    {
        return ['customer' => $action->customer_id, 'subscription' => $action->stripe_subscription_id,
            'subscription_details' => ['items' => [['id' => $action->item_id, 'price' => $action->target_price_id, 'quantity' => 1]],
                'billing_cycle_anchor' => 'unchanged', 'proration_behavior' => 'always_invoice', 'proration_date' => $action->snapshot['prorationDate']]];
    }

    private function simpleSchedule(array $canonical): void
    {
        foreach (['discounts', 'default_tax_rates', 'billing_thresholds', 'pause_collection', 'on_behalf_of', 'transfer_data', 'trial_end'] as $key) {
            if (! empty($canonical[$key])) {
                throw new StudioPaymentException('subscription_conflict', 'This customized subscription requires billing review before scheduling changes.');
            }
        }
        if (data_get($canonical, 'automatic_tax.enabled', false) || ! empty(data_get($canonical, 'items.data.0.discounts'))
            || ! empty(data_get($canonical, 'items.data.0.tax_rates'))) {
            throw new StudioPaymentException('subscription_conflict', 'This customized subscription requires billing review before scheduling changes.');
        }
    }

    private function verifyScheduleIdentity(Action $action, array $schedule, bool $released = false): void
    {
        if (! preg_match('/^sub_sched_[A-Za-z0-9_]+$/', (string) ($schedule['id'] ?? ''))
            || ($action->schedule_id && $schedule['id'] !== $action->schedule_id)
            || ($schedule['livemode'] ?? null) !== $action->livemode || StudioSubscriptionInvoice::id($schedule['customer'] ?? null) !== $action->customer_id
            || StudioSubscriptionInvoice::id($released && ($schedule['status'] ?? '') === 'released' ? ($schedule['released_subscription'] ?? null) : ($schedule['subscription'] ?? null)) !== $action->stripe_subscription_id) {
            throw new StudioPaymentException('subscription_conflict', 'Schedule ownership could not be confirmed.');
        }
    }

    private function matchesSchedule(Action $action, array $schedule): bool
    {
        $phases = $schedule['phases'] ?? [];

        return ($schedule['status'] ?? '') === 'active' && ($schedule['end_behavior'] ?? '') === 'release'
            && data_get($schedule, 'metadata.studio_subscription_action') === $action->id && count($phases) === 2
            && (int) data_get($phases, '0.end_date') === (int) $action->snapshot['periodEnd']
            && (int) data_get($phases, '1.start_date') === (int) $action->snapshot['periodEnd']
            && count(data_get($phases, '0.items', [])) === 1 && count(data_get($phases, '1.items', [])) === 1
            && StudioSubscriptionInvoice::id(data_get($phases, '0.items.0.price')) === $action->source_price_id
            && StudioSubscriptionInvoice::id(data_get($phases, '1.items.0.price')) === $action->target_price_id
            && data_get($phases, '0.items.0.quantity') === 1 && data_get($phases, '1.items.0.quantity') === 1
            && data_get($phases, '0.proration_behavior') === 'none' && data_get($phases, '1.proration_behavior') === 'none';
    }

    private function requestOwner(User $client, ListingStudioBillingRequest $request): void
    {
        $this->client($client);
        if ((int) $request->client_id !== (int) $client->id || $request->kind !== 'subscription_upgrade') {
            throw new StudioPaymentException('billing_request_not_found', 'This billing request is unavailable.', 404);
        }
    }

    private function liveRequest(ListingStudioBillingRequest $request, bool $confirm = false): void
    {
        if (! $request->expires_at?->isFuture() || ! in_array($request->status, $confirm ? ['pending', 'processing'] : ['pending'], true)) {
            throw new StudioPaymentException('billing_request_expired', 'This billing request has expired or is no longer available.');
        }
    }

    private function client(User $client): void
    {
        if ($client->role !== 'client' || ! $client->isAccountEligibleForAuthentication() || $client->getAttribute('is_impersonating')) {
            throw new StudioPaymentException('account_ineligible', 'Use the active client account for this billing request.', 403);
        }
    }

    private function superadmin(User $actor): void
    {
        if ($actor->role !== 'superadmin' || ! $actor->isAccountEligibleForAuthentication() || $actor->getAttribute('is_impersonating')) {
            throw new StudioPaymentException('forbidden', 'Only a superadmin can manage subscription renewals.', 403);
        }
    }

    private function enabled(bool $charge = false): void
    {
        if (! config('listing-studio.subscription_controls_enabled') || ($charge && (! config('listing-studio.delegated_enabled')
            || ! config('listing-studio.paid_enabled') || ! config('listing-studio.paid_checkout_enabled')))) {
            throw new StudioPaymentException('subscription_controls_unavailable', 'Subscription changes are temporarily unavailable.', 503);
        }
    }

    private function locked(ListingStudioSubscription $record, callable $callback): mixed
    {
        $lock = Cache::lock('listing-studio-subscription:'.hash('sha256', $record->stripe_account_id.':'.(int) $record->livemode.':'.$record->stripe_subscription_id), 120);
        if (! $lock->get()) {
            throw new StudioPaymentException('subscription_in_progress', 'Subscription billing is being updated. Try again shortly.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function attention(Action $action): void
    {
        $this->write(fn () => $action->update(['status' => 'needs_attention', 'error_code' => 'subscription_reconciliation_required']));
    }

    private function result(Action $action, ?string $url = null): array
    {
        $status = match ($action->status) {
            'completed', 'scheduled' => 'completed', 'expired', 'cancelled' => 'expired',
            'needs_attention' => 'needs_attention', default => 'processing',
        };

        return ['status' => $status, 'action' => $this->present($action), 'review' => null, 'url' => $url,
            'expiresAt' => null, 'nextAction' => ['type' => $url ? 'redirect' : (in_array($status, ['completed', 'expired']) ? 'none' : ($status === 'needs_attention' ? 'await_reconciliation' : 'await_payment'))]];
    }

    private function present(Action $action): array
    {
        return ['id' => $action->id, 'type' => $action->kind, 'status' => $action->status, 'currentPlanCode' => $action->current_plan_code,
            'planCode' => $action->plan_code, 'effectiveAt' => $action->effective_at?->toIso8601String(), 'errorCode' => $action->error_code];
    }

    private function invoiceUrl(mixed $url): ?string
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'invoice.stripe.com'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null && parse_url($url, PHP_URL_PORT) === null ? $url : null;
    }

    private function stripeKey(Action $action, string $step): string
    {
        return 'studio-subscription-'.$action->id.'-'.$step;
    }

    private function iso(int $time): string
    {
        return CarbonImmutable::createFromTimestampUTC($time)->toIso8601String();
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'studio-subscription-actions');
    }
}
