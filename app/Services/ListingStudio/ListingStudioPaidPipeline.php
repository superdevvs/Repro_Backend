<?php

namespace App\Services\ListingStudio;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioOrderItem;
use App\Models\StudioWorkspace;
use App\Services\Studio\StudioProviderSettings;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingStudioPaidPipeline
{
    public function start(string $itemId, string $attemptId): void
    {
        $orders = app(ListingStudioOrderService::class);
        $current = ListingStudioOrderItem::findOrFail($itemId);
        if ($current->kind !== 'ai_edit') {
            return;
        }
        if ($current->status === 'needs_attention' && $current->workspace_id) {
            return;
        }
        $item = $orders->beginItem($itemId, $attemptId);
        if (! $item) {
            $existing = ListingStudioOrderItem::findOrFail($itemId);
            if (in_array($existing->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }
            if ($existing->processing_attempt_id === $attemptId && $existing->workspace_id && $existing->status === 'processing') {
                $workspace = StudioWorkspace::where('listing_studio_order_item_id', $existing->id)->findOrFail($existing->workspace_id);
                ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id']);

                return;
            }
            throw new \RuntimeException('This existing fulfillment attempt is still preparing.');
        }
        if ($item->kind !== 'ai_edit') {
            return;
        }
        $leaseToken = $item->processing_lease_token;
        try {
            try {
                $snapshot = app(ListingStudioPaidMedia::class)->materialize($item);
                $item = $orders->replaceSourceSnapshot($item->id, $item->source_snapshot['sha256'], $snapshot);
                $workspace = $orders->attachWorkspace($item->id, function (ListingStudioOrderItem $fresh): StudioWorkspace {
                    if ($fresh->workspace_id) {
                        return StudioWorkspace::where('listing_studio_order_item_id', $fresh->id)->findOrFail($fresh->workspace_id);
                    }
                    $recipe = ListingStudioPaidRecipe::definition($fresh->service, $fresh->options ?? []);

                    return StudioWorkspace::create([
                        'listing_studio_order_item_id' => $fresh->id, 'created_by' => $fresh->client_id, 'team_id' => null,
                        'name' => 'Listing Studio order', 'preset_id' => $recipe['preset'], 'request_id' => $fresh->id,
                        'media' => [['id' => 'source', 'orderItemId' => $fresh->id, 'mediaRef' => $fresh->source_snapshot['path'], 'kind' => 'image', 'name' => 'Original photo']],
                        'config' => ['prompt' => $recipe['prompt'], 'ratio' => '16:9', 'frames' => [], 'text' => ['title' => '', 'subtitle' => ''], 'adjustments' => $recipe['adjustments']],
                        'status' => 'generating', 'progress' => 0,
                        'operation' => ['id' => (string) Str::uuid(), 'key' => $fresh->id, 'type' => $recipe['type'], 'recipeVersion' => $recipe['version'],
                            'payload' => $recipe['payload'], 'completed' => [], 'requests' => [], 'routing' => [$recipe['routeKey'] => $recipe['route']]],
                    ]);
                });
            } catch (\Throwable $exception) {
                $orders->holdItem($item->id, 'source_preparation_failed');
                throw $exception;
            }
            // The outbox retains this item/attempt after an uncertain queue response. Duplicate deliveries
            // always find the same workspace/operation and the worker's shared lock serializes execution.
            ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id']);
        } finally {
            $orders->releaseProcessingLease($itemId, $leaseToken);
        }
    }

    public function authorize(StudioWorkspace $workspace): ListingStudioOrderItem
    {
        $item = ListingStudioOrderItem::find($workspace->listing_studio_order_item_id);
        if (! $item || $item->kind !== 'ai_edit' || $item->workspace_id !== $workspace->id
            || (int) $workspace->created_by !== (int) $item->client_id || $workspace->team_id !== null
            || $workspace->listing_studio_trial_id || $workspace->shoot_id || $workspace->editing_dispatch_id
            || ! in_array($item->status, ['queued', 'processing', 'needs_attention'], true)
            || count($workspace->media ?? []) !== 1) {
            throw new StudioProviderException('This paid editing request is no longer active.', true);
        }
        $recipe = ListingStudioPaidRecipe::definition($item->service, $item->options ?? []);
        if ($workspace->preset_id !== $recipe['preset'] || ($workspace->operation['recipeVersion'] ?? '') !== $recipe['version']
            || ($workspace->operation['type'] ?? '') !== $recipe['type'] || ($workspace->operation['payload'] ?? []) !== $recipe['payload']
            || ($workspace->config['prompt'] ?? '') !== $recipe['prompt'] || ($workspace->config['adjustments'] ?? []) !== $recipe['adjustments']
            || ($workspace->operation['routing'][$recipe['routeKey']] ?? null) !== $recipe['route']) {
            throw new StudioProviderException('This paid editing recipe needs review.', true);
        }
        if (($workspace->media[0]['orderItemId'] ?? null) !== $item->id) {
            throw new StudioProviderException('This paid workspace source belongs to another order.', true);
        }
        $sourceItem = $this->authorizeSource($workspace->media[0]);
        if ($sourceItem->id !== $item->id) {
            throw new StudioProviderException('This paid workspace source belongs to another order.', true);
        }

        return $item;
    }

    public function authorizeSource(array $media): ListingStudioOrderItem
    {
        $item = ListingStudioOrderItem::find($media['orderItemId'] ?? null);
        if (! $item || $item->kind !== 'ai_edit' || ! in_array($item->status, ['queued', 'processing', 'needs_attention'], true)
            || ($media['id'] ?? '') !== 'source' || ! empty($media['fileId']) || ! empty($media['shootId']) || ! empty($media['stackFileIds'])
            || ($item->source_snapshot['disk'] ?? '') !== ListingStudioPaidStorage::DISK
            || ! str_starts_with($item->source_snapshot['path'] ?? '', 'items/'.$item->id.'/')
            || ($media['mediaRef'] ?? '') !== ($item->source_snapshot['path'] ?? null)) {
            throw new StudioProviderException('This paid source is not available.', true);
        }
        app(ListingStudioPaidMedia::class)->read($item->source_snapshot);

        return $item;
    }

    public function assertMaySubmit(StudioWorkspace $workspace): void
    {
        $item = $this->authorize($workspace);
        $recipe = ListingStudioPaidRecipe::definition($item->service, $item->options ?? []);
        $order = $item->order;
        $client = $item->client;
        if (! config('listing-studio.paid_enabled', false) || ! config('listing-studio.paid_services.'.$item->service, false)
            || ! $order || ! in_array($order->funding_status, ['reserved', 'settled'], true) || $order->refunded_amount_cents > 0
            || ! $client || $client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()
            || ! app(StudioProviderSettings::class)->readiness($recipe['routeKey'], $recipe['route'])['ready']) {
            throw new StudioProviderException('Paid editing is paused. Saved provider progress is retained.', true);
        }
    }

    public function syncWorkspace(StudioWorkspace $workspace): void
    {
        $item = ListingStudioOrderItem::where('workspace_id', $workspace->id)->whereKey($workspace->listing_studio_order_item_id)->first();
        if (! $item || in_array($item->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        $orders = app(ListingStudioOrderService::class);
        if ($workspace->status === 'completed') {
            try {
                $output = collect($workspace->outputs ?? [])->first(fn ($output) => ($output['status'] ?? '') === 'completed' && ($output['kind'] ?? '') === 'image');
                $path = $output['path'] ?? '';
                if (! str_starts_with($path, 'studio/workspaces/'.$workspace->id.'/')) {
                    throw new \RuntimeException('Private output is unavailable.');
                }
                $descriptor = app(ListingStudioPaidMedia::class)->completeOutput($item, ['disk' => ListingStudioPaidStorage::DISK, 'path' => $path]);
                $orders->completeItem($item->id, $descriptor);
            } catch (\Throwable $exception) {
                $orders->holdItem($item->id, 'output_unavailable');
                throw $exception;
            }
        } elseif ($workspace->status === 'failed') {
            if ($workspace->operation['paidFailureDefinitive'] ?? false) {
                try {
                    $orders->failItem($item->id, 'processing_failed');
                } catch (\App\Exceptions\PublicApiException $exception) {
                    if ($exception->errorCode !== 'outcome_unresolved') {
                        throw $exception;
                    }
                    $orders->holdItem($item->id, 'processing_needs_attention');
                }
            } else {
                $orders->holdItem($item->id, 'processing_needs_attention');
            }
        }
    }

    public function hasCheckpoint(StudioWorkspace $workspace): bool
    {
        return ! empty($workspace->operation['requests']) || ! empty($workspace->operation['providerState'])
            || ! empty($workspace->operation['completed']) || ! empty($workspace->outputs);
    }

    /** Operator recovery reuses the accepted workspace, operation and checkpoint. */
    public function resume(string $itemId): void
    {
        $item = ListingStudioOrderItem::findOrFail($itemId);
        if (! $item->workspace_id || ! in_array($item->status, ['needs_attention', 'processing'], true)) {
            throw new StudioProviderException('This item has no held workspace to recover.');
        }
        $job = new ProcessStudioWorkspace($item->workspace_id, 'recovery');
        $lock = Cache::store('database')->lock($job->middleware()[0]->getLockKey($job), 60);
        if (! $lock->get()) {
            throw new StudioProviderException('This workspace is still being processed.');
        }
        $leaseToken = null;
        try {
            $workspace = StudioWorkspace::where('listing_studio_order_item_id', $itemId)->findOrFail($item->workspace_id);
            if (! $this->recoverableState($item->fresh(), $workspace)) {
                throw new StudioProviderException('This workspace is not stale or held. Wait for its existing worker.');
            }
            if ($workspace->status === 'completed') {
                $this->syncWorkspace($workspace);

                return;
            }
            if ($this->unconfirmed($workspace)) {
                throw new StudioProviderException('Verify the existing provider outcome before recovery. No new submission was made.', true);
            }
            $this->authorize($workspace);
            if (! $this->hasCheckpoint($workspace)) {
                $this->assertMaySubmit($workspace);
            }
            foreach ($workspace->operation['providerState'] ?? [] as $key => $saved) {
                if (str_starts_with($key, 'openai-') && ! empty($saved['path']) && ! Storage::disk(ListingStudioPaidStorage::DISK)->exists($saved['path'])) {
                    throw new StudioProviderException('The saved provider result is unavailable. Verify the existing outcome.', true);
                }
            }
            [$workspace, $leaseToken] = LockedWrite::run(fn () => DB::transaction(function () use ($itemId, $workspace) {
                $item = ListingStudioOrderItem::findOrFail($itemId);
                $workspace = StudioWorkspace::where('listing_studio_order_item_id', $itemId)->findOrFail($workspace->id);
                if (! $this->recoverableState($item, $workspace) || ! $item->processing_attempt_id
                    || ($item->processing_lease_expires_at && $item->processing_lease_expires_at->isFuture())) {
                    throw new StudioProviderException('The processing lease is still active. Wait before recovering this item.');
                }
                if ($this->unconfirmed($workspace)) {
                    throw new StudioProviderException('The provider outcome needs confirmation.', true);
                }
                $started = app(ListingStudioOrderService::class)->beginItem($itemId, $item->processing_attempt_id);
                if (! $started) {
                    throw new StudioProviderException('This item is already being recovered.');
                }
                $operation = $workspace->operation;
                unset($operation['paidFailureDefinitive']);
                $workspace->update(['status' => 'generating', 'operation' => $operation, 'error' => null, 'version' => $workspace->version + 1]);

                return [$workspace, $started->processing_lease_token];
            }), 'listing-studio.paid-resume');
            ProcessStudioWorkspace::dispatch($workspace->id, $workspace->operation['id']);
        } finally {
            $lock->release();
            if ($leaseToken) {
                app(ListingStudioOrderService::class)->releaseProcessingLease($itemId, $leaseToken);
            }
        }
    }

    private function recoverableState(ListingStudioOrderItem $item, StudioWorkspace $workspace): bool
    {
        return $item->status === 'needs_attention' || ($item->status === 'processing'
            && (in_array($workspace->status, ['completed', 'failed'], true)
                || ($workspace->isBusy() && $workspace->updated_at->lt(now()->subMinutes(150)))));
    }

    private function unconfirmed(StudioWorkspace $workspace): bool
    {
        $state = $workspace->operation['providerState'] ?? [];
        foreach ($state as $key => $value) {
            if (! $value) {
                continue;
            }
            if (str_starts_with($key, 'fal-submitting-') && empty($workspace->operation['requests'][substr($key, strlen('fal-submitting-'))])) {
                return true;
            }
            if (str_starts_with($key, 'autoenhance-') && empty($value['imageId'])) {
                return true;
            }
            if (str_starts_with($key, 'openai-') && empty($value['path'])) {
                return true;
            }
            if (str_starts_with($key, 'vsai-') && str_ends_with($key, '-submitting') && empty($state[substr($key, 0, -strlen('-submitting'))]['renderId'])) {
                return true;
            }
        }

        return false;
    }
}
