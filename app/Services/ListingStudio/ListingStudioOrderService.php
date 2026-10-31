<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioOutcomeGuard;
use App\Contracts\ListingStudio\ListingStudioSourceResolver;
use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioQuote;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListingStudioOrderService
{
    public function __construct(
        private readonly ListingStudioQuoteService $quotes,
        private readonly ListingStudioCatalogService $catalog,
        private readonly ListingStudioCreditReservationService $credits,
        private readonly ListingStudioSourceResolver $sources,
        private readonly ListingStudioOutcomeGuard $outcomes,
    ) {}

    public function acceptQuote(User $client, string $quoteId, string $fundingMethod, string $idempotencyKey, ?User $actor = null): array
    {
        if (! Str::isUuid($quoteId) || ! Str::isUuid($idempotencyKey) || ! in_array($fundingMethod, ['credits', 'card'], true)) {
            throw new PublicApiException('A quote, payment choice and valid request identifier are required.', 'validation_failed', 422);
        }
        $hash = hash('sha256', json_encode([$quoteId, $fundingMethod], JSON_THROW_ON_ERROR));

        return $this->write(function () use ($client, $quoteId, $fundingMethod, $idempotencyKey, $hash, $actor) {
            $client = User::findOrFail($client->id);
            ListingStudioClientAccess::assert($client);
            if ($actor) {
                $actor = User::findOrFail($actor->id);
                ListingStudioDelegatedAccess::assert($actor, $client);
            }
            $existing = ListingStudioOrder::where('client_id', $client->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_if($actor && (int) $existing->actor_id !== (int) $actor->id, 404);
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new PublicApiException('This request identifier was used for another order.', 'idempotency_conflict', 409);
                }

                return ['order' => $existing, 'created' => false];
            }
            $quote = ListingStudioQuote::where('client_id', $client->id)->findOrFail($quoteId);
            if ($actor) {
                ListingStudioDelegatedAccess::assert($actor, $client, true);
                abort_unless((int) $quote->actor_id === (int) $actor->id, 404);
                ListingStudioDelegatedAccess::assertSource($actor, $client, $quote->source_kind, $quote->source_id);
            }
            if ($quote->consumed_at || ListingStudioOrder::where('quote_id', $quote->id)->exists()) {
                throw new PublicApiException('This quote already has an order. Open your order history to continue.', 'idempotency_conflict', 409);
            }
            $selection = $this->quotes->assertCurrent($quote, $client);
            if ($fundingMethod === 'card' && ! $this->catalog->checkoutAvailable()) {
                throw new PublicApiException('Card checkout is temporarily unavailable.', 'checkout_unavailable', 503);
            }
            if ($fundingMethod === 'card' && ListingStudioOrder::where('client_id', $client->id)
                ->where('funding_method', 'card')->where('funding_status', 'pending')->where('paid_amount_cents', 0)
                ->whereHas('item', fn ($items) => $items->where('status', 'awaiting_funding'))
                ->count() >= max(1, (int) config('listing-studio.max_unpaid_orders_per_client', 20))) {
                throw new PublicApiException('Finish or cancel an unpaid order before starting another.', 'unpaid_order_limit', 409);
            }
            if ($fundingMethod === 'credits' && ! $selection['creditSubscription']) {
                throw new PublicApiException('No eligible editing credit is available for this account.', 'insufficient_credits', 409,
                    ['quoteId' => $quote->id, 'shortfallCents' => $quote->pricing_snapshot['creditCostCents'],
                        'availableCreditCents' => 0, 'requiresNewQuote' => false]);
            }
            $order = ListingStudioOrder::create(['client_id' => $client->id, 'actor_id' => $actor?->id ?? $client->id, 'quote_id' => $quote->id,
                'kind' => $quote->kind, 'service' => $quote->service, 'funding_method' => $fundingMethod,
                'funding_status' => $fundingMethod === 'credits' ? 'reserved' : 'pending',
                'currency' => 'usd', 'total_cents' => $quote->pricing_snapshot['totalPriceCents'],
                'pricing_snapshot' => $quote->pricing_snapshot, 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash]);
            $item = ListingStudioOrderItem::create(['order_id' => $order->id, 'client_id' => $client->id,
                'kind' => $quote->kind, 'service' => $quote->service, 'status' => 'awaiting_funding',
                'source_kind' => $quote->source_kind, 'source_id' => $quote->source_id,
                'source_snapshot' => $quote->source_snapshot, 'options' => $quote->options, 'instructions' => $quote->instructions]);
            $this->sources->pin($quote->source_snapshot, $item->id);
            if ($fundingMethod === 'credits') {
                $this->credits->reserve($order, $selection['creditSubscription'], $quote->pricing_snapshot['creditCostCents']);
                $this->fundOrder($order);
            }
            $quote->update(['consumed_at' => now()]);
            if ($actor) {
                \App\Models\UserActivityLog::record($client, 'listing_studio.delegated_order', 'Listing Studio order created for client', null, $actor,
                    ['orderId' => $order->id, 'fundingMethod' => $fundingMethod, 'amountCents' => $order->total_cents]);
            }

            return ['order' => $order->fresh(), 'created' => true];
        });
    }

    /** Caller holds the financial transaction; no queue/network work here. */
    public function fundOrder(ListingStudioOrder $order): void
    {
        $this->assertTransaction();
        $item = $order->item()->firstOrFail();
        if (in_array($item->status, ['failed', 'cancelled'], true)) {
            if ($order->funding_method === 'card' && $order->paid_amount_cents > $order->refunded_amount_cents) {
                $this->requestRefund($order);
            }

            return;
        }
        if ($item->status !== 'awaiting_funding') {
            return;
        }
        $attempt = (string) Str::uuid();
        $item->update(['status' => 'queued', 'processing_attempt_id' => $attempt]);
        $this->emit($item->kind === 'human_revision' ? 'create_revision_task' : 'process_item', $order, $item, $attempt);
    }

    public function beginItem(string $itemId, string $attemptId): ?ListingStudioOrderItem
    {
        if (! Str::isUuid($attemptId)) {
            throw new PublicApiException('The processing attempt is invalid.', 'revision_state_conflict', 409);
        }

        return $this->write(function () use ($itemId, $attemptId) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            $order = $item->order()->firstOrFail();
            if (in_array($item->status, ['completed', 'failed', 'cancelled'], true)) {
                return null;
            }
            if (! in_array($order->funding_status, ['reserved', 'settled'], true)) {
                throw new PublicApiException('Payment must be confirmed before editing.', 'payment_required', 409);
            }
            if (($item->processing_attempt_id && $item->processing_attempt_id !== $attemptId)
                || ! in_array($item->status, ['queued', 'processing', 'needs_attention'], true)) {
                throw new PublicApiException('This processing attempt cannot replace the existing operation.', 'revision_state_conflict', 409);
            }
            if ($item->processing_lease_expires_at?->isFuture()) {
                return null;
            }
            $item->update(['status' => 'processing', 'processing_attempt_id' => $attemptId,
                'processing_lease_token' => (string) Str::uuid(), 'processing_lease_expires_at' => now()->addMinutes(5),
                'started_at' => $item->started_at ?? now(), 'error_code' => null]);

            return $item->fresh();
        });
    }

    public function releaseProcessingLease(string $itemId, string $leaseToken): void
    {
        $this->write(fn () => ListingStudioOrderItem::whereKey($itemId)->where('processing_lease_token', $leaseToken)
            ->update(['processing_lease_token' => null, 'processing_lease_expires_at' => null]));
    }

    /** Callback creates/finds a workspace using DB work only; never dispatch from it. */
    public function attachWorkspace(string $itemId, callable $createOrFindWorkspace): StudioWorkspace
    {
        return $this->write(function () use ($itemId, $createOrFindWorkspace) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            if ($item->kind !== 'ai_edit' || ! in_array($item->status, ['processing', 'needs_attention'], true)) {
                throw new PublicApiException('This order is not ready for AI processing.', 'revision_state_conflict', 409);
            }
            $workspace = $item->workspace_id ? StudioWorkspace::findOrFail($item->workspace_id) : $createOrFindWorkspace($item);
            if (! $workspace instanceof StudioWorkspace || $workspace->listing_studio_order_item_id !== $item->id
                || (int) $workspace->created_by !== (int) $item->client_id || $workspace->listing_studio_trial_id !== null) {
                throw new \LogicException('Paid workspace ownership does not match its order item.');
            }
            $item->update(['workspace_id' => $workspace->id]);

            return $workspace;
        });
    }

    public function replaceSourceSnapshot(string $itemId, string $expectedSha256, array $descriptor): ListingStudioOrderItem
    {
        return $this->write(function () use ($itemId, $expectedSha256, $descriptor) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            $old = $item->source_snapshot;
            foreach (['kind', 'id', 'clientId', 'service'] as $field) {
                if (($descriptor[$field] ?? null) !== ($old[$field] ?? null)) {
                    throw new \LogicException('The materialized source identity changed.');
                }
            }
            if (in_array($item->status, ['completed', 'failed', 'cancelled'], true)
                || ! hash_equals($old['sha256'], $expectedSha256)
                || ! hash_equals($expectedSha256, $descriptor['sha256'] ?? '')
                || (int) ($descriptor['clientId'] ?? 0) !== (int) $item->client_id) {
                throw new \LogicException('The materialized source does not match the accepted item.');
            }
            $this->assertMedia($descriptor, 'items/'.$item->id.'/input.');
            $this->assertMedia($descriptor['original'] ?? [], 'items/'.$item->id.'/property-original.');
            if (($descriptor['original']['sha256'] ?? null) !== ($old['original']['sha256'] ?? null)) {
                throw new \LogicException('The property original changed during materialization.');
            }
            $item->update(['source_snapshot' => $descriptor]);

            return $item->fresh();
        });
    }

    public function completeItem(string $itemId, array $descriptor): ListingStudioOrderItem
    {
        return $this->write(function () use ($itemId, $descriptor) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            if ($item->status === 'completed') {
                if (($item->output_descriptor['sha256'] ?? null) !== ($descriptor['sha256'] ?? null)) {
                    throw new PublicApiException('This completed delivery cannot be replaced.', 'revision_state_conflict', 409);
                }

                return $item;
            }
            if (! in_array($item->status, ['processing', 'needs_attention'], true)
                || ($descriptor['itemId'] ?? null) !== $item->id || (int) ($descriptor['clientId'] ?? 0) !== (int) $item->client_id) {
                throw new PublicApiException('This order is not eligible for delivery.', 'revision_state_conflict', 409);
            }
            $prefix = $item->kind === 'ai_edit' ? 'studio/workspaces/'.$item->workspace_id.'/' : 'revision-deliveries/';
            if ($item->kind === 'ai_edit' && ! $item->workspace_id) {
                throw new \LogicException('Paid AI delivery has no owned workspace.');
            }
            $this->assertMedia($descriptor, $prefix);
            $this->assertMedia($descriptor['preview'] ?? [], 'items/'.$item->id.'/result-preview.jpg', true);
            $this->outcomes->assertDeliverableOutput($item, $descriptor);
            $order = $item->order()->firstOrFail();
            if ($order->funding_method === 'credits') {
                if ($order->funding_status !== 'reserved') {
                    throw new PublicApiException('The credit reservation is unavailable.', 'payment_required', 409);
                }
                $this->credits->capture($order);
                $order->update(['funding_status' => 'settled']);
            } elseif ($order->funding_status !== 'settled' || $order->paid_amount_cents !== $order->total_cents || $order->refunded_amount_cents > 0) {
                throw new PublicApiException('Payment must be confirmed before delivery.', 'payment_required', 409);
            }
            $item->update(['status' => 'completed', 'output_descriptor' => $descriptor, 'progress' => 100,
                'completed_at' => now(), 'error_code' => null, 'processing_lease_token' => null, 'processing_lease_expires_at' => null]);

            return $item->fresh();
        });
    }

    public function holdItem(string $itemId, string $recoveryCode): ListingStudioOrderItem
    {
        return $this->write(function () use ($itemId, $recoveryCode) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            if (in_array($item->status, ['completed', 'failed', 'cancelled'], true)) {
                return $item;
            }
            $allowed = ['source_preparation_failed', 'output_unavailable', 'processing_needs_attention', 'processing_uncertain',
                'delivery_finalization_failed', 'revision_needs_attention', 'worker_interrupted', 'payment_review', 'payment_refunded'];
            $item->update(['status' => 'needs_attention', 'error_code' => in_array($recoveryCode, $allowed, true) ? $recoveryCode : 'processing_needs_attention']);

            return $item->fresh();
        });
    }

    public function failItem(string $itemId, string $definitiveCode): ListingStudioOrderItem
    {
        return $this->stopItem($itemId, 'failed', $definitiveCode);
    }

    public function cancelItem(string $itemId, string $definitiveCode): ListingStudioOrderItem
    {
        return $this->stopItem($itemId, 'cancelled', $definitiveCode);
    }

    public function cancelUnpaidOrder(User $client, string $orderId): ListingStudioOrder
    {
        return $this->write(function () use ($client, $orderId) {
            ListingStudioClientAccess::assert(User::findOrFail($client->id));
            $order = ListingStudioOrder::where('client_id', $client->id)->findOrFail($orderId);
            if ($order->item->status === 'cancelled' && $order->paid_amount_cents === 0) {
                return $order;
            }
            if (! $this->canCancelUnpaid($order)) {
                throw new PublicApiException('This order cannot be cancelled while payment or editing needs confirmation.', 'order_cancellation_unavailable', 409);
            }
            $this->cancelItem($order->item->id, 'order_cancelled');
            $order->update(['funding_status' => 'released']);

            return $order->fresh();
        });
    }

    private function canCancelUnpaid(ListingStudioOrder $order): bool
    {
        return $order->funding_method === 'card' && $order->funding_status === 'pending' && $order->paid_amount_cents === 0
            && $order->item->status === 'awaiting_funding'
            && ! \App\Models\ListingStudioCheckoutAttempt::where('order_id', $order->id)->whereNotIn('status', ['expired', 'failed'])->exists();
    }

    private function stopItem(string $itemId, string $state, string $code): ListingStudioOrderItem
    {
        return $this->write(function () use ($itemId, $state, $code) {
            $item = ListingStudioOrderItem::findOrFail($itemId);
            if (in_array($item->status, ['failed', 'cancelled'], true)) {
                return $item;
            }
            if ($item->status === 'completed') {
                throw new PublicApiException('A completed delivery cannot be cancelled or failed.', 'revision_state_conflict', 409);
            }
            $this->outcomes->assertDefinitiveStop($item);
            $order = $item->order()->firstOrFail();
            if ($order->funding_method === 'credits' && $order->funding_status === 'reserved') {
                $this->credits->release($order);
                $order->update(['funding_status' => 'released']);
            } elseif ($order->funding_method === 'card' && $order->paid_amount_cents > $order->refunded_amount_cents) {
                $this->requestRefund($order);
            }
            $item->update(['status' => $state, 'error_code' => in_array($code, ['processing_failed', 'revision_failed', 'order_cancelled'], true)
                ? $code : ($state === 'failed' ? 'processing_failed' : 'order_cancelled'),
                'processing_lease_token' => null, 'processing_lease_expires_at' => null]);

            return $item->fresh();
        });
    }

    /** Caller holds the financial transaction. Stripe dispatch occurs later via outbox. */
    public function requestRefund(ListingStudioOrder $order): void
    {
        $this->assertTransaction();
        if ($order->funding_method !== 'card' || $order->paid_amount_cents <= $order->refunded_amount_cents) {
            return;
        }
        $item = $order->item()->firstOrFail();
        // A later payment event must not reopen a terminal refund operation.
        if (ListingStudioOutbox::where('idempotency_key', 'refund_order:'.$item->id)->exists()) {
            return;
        }
        $order->update(['funding_status' => 'refund_pending']);
        $this->emit('refund_order', $order, $item, $item->processing_attempt_id ?? (string) Str::uuid());
    }

    public function confirmPayment(string $orderId, array $evidence): ListingStudioOrder
    {
        return app(ListingStudioSettlementService::class)->payment($orderId, $evidence);
    }

    public function confirmRefund(string $orderId, array $evidence): ListingStudioOrder
    {
        return app(ListingStudioSettlementService::class)->refund($orderId, $evidence);
    }

    public function present(ListingStudioOrder $order): array
    {
        $item = $order->item()->firstOrFail();
        $reservation = $order->reservation()->first();
        $completed = $item->status === 'completed' && is_array($item->output_descriptor);
        $sourcePreviewAvailable = filled($item->source_snapshot['preview'] ?? null)
            && ! ($item->source_kind === 'upload' && class_exists(\App\Models\ListingStudioUpload::class)
                && \App\Models\ListingStudioUpload::whereKey($item->source_id)->whereNotNull('media_purged_at')->exists());
        $checkoutPending = class_exists(\App\Models\ListingStudioCheckoutAttempt::class)
            && \App\Models\ListingStudioCheckoutAttempt::where('order_id', $order->id)
                ->whereIn('status', ['submitting', 'awaiting_reconciliation', 'awaiting_payment', 'awaiting_webhook', 'paid'])->exists();
        $task = class_exists(\App\Models\ListingStudioRevisionTask::class)
            ? \App\Models\ListingStudioRevisionTask::where('order_item_id', $item->id)->first() : null;

        return ['id' => $order->id, 'createdAt' => $order->created_at->toIso8601String(), 'kind' => $order->kind,
            'service' => $order->service, 'pricing' => $order->pricing_snapshot,
            'funding' => ['method' => $order->funding_method, 'status' => $order->funding_status,
                'reservedCreditCents' => $reservation?->status === 'reserved' ? $reservation->amount_cents : 0,
                'capturedCreditCents' => $reservation?->status === 'captured' ? $reservation->amount_cents : 0,
                'paidAmountCents' => $order->paid_amount_cents, 'refundedAmountCents' => $order->refunded_amount_cents],
            'item' => ['id' => $item->id, 'status' => $item->status, 'progress' => $item->progress,
                'source' => ['kind' => $item->source_kind, 'id' => $item->source_id,
                    'originalPreviewAvailable' => $sourcePreviewAvailable],
                'previewAvailable' => $completed && filled($item->output_descriptor['preview'] ?? null),
                'downloadAvailable' => $completed, 'completedAt' => $item->completed_at?->toIso8601String(),
                'error' => $item->error_code ? ['code' => $item->error_code, 'message' => $item->status === 'needs_attention'
                    ? 'This edit needs recovery. Your existing order is being preserved.'
                    : 'This edit could not be completed. Check its payment status for the credit release or refund.'] : null,
                'revisionFulfillment' => $task ? ['status' => $task->status, 'deliveredAt' => $task->delivered_at?->toIso8601String()] : null],
            'actions' => ['canCheckout' => $order->funding_method === 'card' && $order->funding_status === 'pending'
                && $item->status === 'awaiting_funding' && ! $checkoutPending && $this->catalog->checkoutAvailable()
                && $this->catalog->available($order->kind, $order->service)
                && ! $this->credits->selection($order->client)['billingAttention'],
                'canDownload' => $completed, 'canRequestRevision' => $completed && $this->catalog->available('human_revision', $item->service),
                'canCancel' => $this->canCancelUnpaid($order)]];
    }

    public function nextAction(ListingStudioOrder $order): array
    {
        $present = $this->present($order);
        $type = $present['actions']['canCheckout'] ? 'checkout'
            : ($order->funding_status === 'pending' ? 'await_payment'
                : (in_array($present['item']['status'], ['queued', 'processing', 'needs_attention'], true) ? 'await_processing' : 'none'));

        return ['type' => $type];
    }

    private function emit(string $type, ListingStudioOrder $order, ListingStudioOrderItem $item, string $attempt): void
    {
        ListingStudioOutbox::firstOrCreate(['idempotency_key' => $type.':'.$item->id], [
            'type' => $type, 'aggregate_id' => $order->id, 'payload' => ['orderId' => $order->id, 'itemId' => $item->id, 'attemptId' => $attempt],
            'status' => 'pending', 'available_at' => now(),
        ]);
    }

    private function assertMedia(array $descriptor, string $prefix, bool $exact = false): void
    {
        $path = $descriptor['path'] ?? '';
        if (($descriptor['disk'] ?? null) !== 'listing_studio_orders' || ! is_string($path)
            || str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")
            || ($exact ? $path !== $prefix : ! str_starts_with($path, $prefix))
            || ! preg_match('/\A[a-f0-9]{64}\z/', $descriptor['sha256'] ?? '')
            || ! in_array($descriptor['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_int($descriptor['width'] ?? null) || ! is_int($descriptor['height'] ?? null)
            || $descriptor['width'] < 1 || $descriptor['height'] < 1 || $descriptor['width'] * $descriptor['height'] > 40000000
            || ! is_int($descriptor['byteSize'] ?? null) || $descriptor['byteSize'] < 1 || $descriptor['byteSize'] > 104857600) {
            throw new \LogicException('The private media descriptor does not match its owned item.');
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A Listing Studio financial transaction is required.');
        }
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-order');
    }
}
