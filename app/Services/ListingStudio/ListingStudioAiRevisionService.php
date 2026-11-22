<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\ListingStudioAiRevision;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Included revisions change saved media only. They never create orders or touch funding. */
class ListingStudioAiRevisionService
{
    public const LIMIT = 3;
    public const VERSION = 'listing-ai-revision-v1';
    private const ACTIVE = ['queued', 'processing', 'needs_attention'];

    public function request(User $client, string $orderId, string $instructions, string $key): ListingStudioAiRevision
    {
        ListingStudioClientAccess::assert($client);
        if (! Str::isUuid($key) || mb_strlen($instructions = trim($instructions)) < 3 || mb_strlen($instructions) > 1500) {
            throw new PublicApiException('Describe the changes in 3–1500 characters.', 'validation_failed', 422);
        }
        $order = ListingStudioOrder::where('client_id', $client->id)->with('item')->findOrFail($orderId);
        $hash = hash('sha256', $orderId.'|'.$instructions);
        $existing = ListingStudioAiRevision::where('order_item_id', $order->item->id)->where('request_key', $key)->first();
        if ($existing) {
            $this->assertReplay($existing, $hash);

            return $existing;
        }
        $route = app(StudioProviderSettings::class)->route('revision');
        if (! $this->enabled() || ! app(StudioProviderSettings::class)->readiness('revision', $route)['ready']) {
            throw new PublicApiException('AI revisions are temporarily unavailable. Your saved versions remain available.', 'ai_revision_unavailable', 409);
        }
        // File validation and provider readiness stay outside the short SQLite write transaction.
        $item = $order->item;
        if (! $this->funded($item) || ! $item->workspace_id || ! $item->output_descriptor) {
            throw new PublicApiException('Finish a paid AI edit before requesting an included revision.', 'ai_revision_unavailable', 409);
        }
        app(ListingStudioPaidMedia::class)->read($item->output_descriptor);
        $revision = LockedWrite::run(fn () => DB::transaction(function () use ($client, $orderId, $instructions, $key, $hash, $route) {
            // Take current locking reads before opening a consistent-read snapshot.
            // Settlement and item lifecycle writes use the same parent-first order.
            $order = ListingStudioOrder::where('client_id', $client->id)->lockForUpdate()->findOrFail($orderId);
            $item = $order->item()->lockForUpdate()->firstOrFail();
            $item->setRelation('order', $order);
            $item->touch(); // Acquire the write guard before counting/reserving the shared allowance.
            $existing = ListingStudioAiRevision::where('order_item_id', $item->id)->where('request_key', $key)->first();
            if ($existing) {
                $this->assertReplay($existing, $hash);

                return $existing;
            }
            if (! $this->enabled() || ! $this->funded($item) || ! $item->workspace_id) {
                throw new PublicApiException('This edit is not eligible for another AI revision.', 'ai_revision_unavailable', 409);
            }
            $workspace = StudioWorkspace::where('listing_studio_order_item_id', $item->id)->findOrFail($item->workspace_id);
            $rows = ListingStudioAiRevision::where('order_item_id', $item->id);
            if ((clone $rows)->whereIn('status', self::ACTIVE)->exists() || $workspace->isBusy()) {
                throw new PublicApiException('Your existing revision is still being processed or recovered.', 'ai_revision_active', 409);
            }
            $used = $rows->count();
            if ($used >= self::LIMIT) {
                throw new PublicApiException('This edit has used all three included AI revisions.', 'ai_revision_limit', 409);
            }
            $base = collect($workspace->outputs ?? [])->first(fn ($output) => ($output['path'] ?? '') === ($item->output_descriptor['path'] ?? null)
                && ($output['status'] ?? '') === 'completed' && ($output['kind'] ?? '') === 'image' && ($output['mediaId'] ?? '') === 'source');
            if (! $base) {
                throw new PublicApiException('This saved version needs recovery before revising.', 'ai_revision_unavailable', 409);
            }
            $revision = ListingStudioAiRevision::create(['client_id' => $client->id, 'order_item_id' => $item->id,
                'sequence' => $used + 1, 'request_key' => $key, 'request_hash' => $hash, 'instructions' => $instructions,
                'base_output_id' => $base['id'], 'base_descriptor' => $item->output_descriptor, 'route' => $route, 'status' => 'queued']);
            $workspace->update(['status' => 'generating', 'progress' => 0, 'error' => null, 'version' => $workspace->version + 1,
                'operation' => ['id' => $revision->id, 'key' => $revision->id, 'type' => 'revision', 'recipeVersion' => self::VERSION,
                    'aiRevisionId' => $revision->id, 'payload' => $this->payload($revision), 'completed' => [], 'requests' => [], 'routing' => ['revision' => $route]]]);
            ListingStudioOutbox::create(['idempotency_key' => 'process_ai_revision:'.$revision->id, 'type' => 'process_ai_revision',
                'aggregate_id' => $order->id, 'payload' => ['revisionId' => $revision->id], 'status' => 'pending', 'available_at' => now()]);

            return $revision;
        }), 'listing-studio.ai-revision-admission');

        return $revision;
    }

    public function start(string $revisionId): void
    {
        $revision = ListingStudioAiRevision::findOrFail($revisionId);
        if (! in_array($revision->status, ['queued', 'processing'], true)) {
            return;
        }
        $item = ListingStudioOrderItem::findOrFail($revision->order_item_id);
        $workspace = StudioWorkspace::findOrFail($item->workspace_id);
        if (($workspace->operation['id'] ?? null) !== $revision->id) {
            throw new StudioProviderException('The saved revision operation no longer matches.', true);
        }
        ProcessStudioWorkspace::dispatch($workspace->id, $revision->id);
    }

    public function authorize(StudioWorkspace $workspace): ListingStudioOrderItem
    {
        $revision = ListingStudioAiRevision::find($workspace->operation['aiRevisionId'] ?? null);
        $item = ListingStudioOrderItem::find($workspace->listing_studio_order_item_id);
        if (! $revision || ! $item || $revision->order_item_id !== $item->id || ! $this->funded($item)
            || ! in_array($revision->status, self::ACTIVE, true) || $item->workspace_id !== $workspace->id
            || (int) $workspace->created_by !== (int) $item->client_id || (int) $revision->client_id !== (int) $item->client_id
            || $workspace->team_id !== null || $workspace->shoot_id || $workspace->editing_dispatch_id || $workspace->listing_studio_trial_id
            || ($workspace->operation['id'] ?? null) !== $revision->id || ($workspace->operation['type'] ?? null) !== 'revision'
            || ($workspace->operation['recipeVersion'] ?? null) !== self::VERSION || ! StudioWorkspace::sameConfig($workspace->operation['payload'] ?? [], $this->payload($revision))
            || ! StudioWorkspace::sameConfig($workspace->operation['routing'] ?? [], ['revision' => $revision->route])
            || count($workspace->media ?? []) !== 1 || ($workspace->media[0]['id'] ?? '') !== 'source'
            || ($workspace->media[0]['orderItemId'] ?? '') !== $item->id
            || ($workspace->media[0]['mediaRef'] ?? '') !== ($item->source_snapshot['path'] ?? null)
            || ! empty($workspace->media[0]['fileId']) || ! empty($workspace->media[0]['shootId']) || ! empty($workspace->media[0]['stackFileIds'])) {
            throw new StudioProviderException('This included AI revision is no longer authorized.', true);
        }
        $base = collect($workspace->outputs ?? [])->firstWhere('id', $revision->base_output_id);
        if (($base['path'] ?? null) !== ($revision->base_descriptor['path'] ?? null)
            || ! str_starts_with($base['path'] ?? '', 'studio/workspaces/'.$workspace->id.'/') || ($base['mediaId'] ?? '') !== 'source') {
            throw new StudioProviderException('The saved revision source does not belong to this edit.', true);
        }
        app(ListingStudioPaidMedia::class)->read($revision->base_descriptor);

        return $item;
    }

    public function assertMaySubmit(StudioWorkspace $workspace): void
    {
        $item = $this->authorize($workspace);
        if (! $this->enabled() || ! $item->client || ! $item->client->isAccountEligibleForAuthentication() || $item->client->role !== 'client'
            || ! app(StudioProviderSettings::class)->readiness('revision', $workspace->operation['routing']['revision'])['ready']) {
            throw new StudioProviderException('AI revisions are paused. Your existing request is saved.', true);
        }
    }

    public function started(StudioWorkspace $workspace): void
    {
        ListingStudioAiRevision::whereKey($workspace->operation['aiRevisionId'] ?? null)->where('status', 'queued')
            ->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
    }

    public function sync(StudioWorkspace $workspace): void
    {
        $revision = ListingStudioAiRevision::find($workspace->operation['aiRevisionId'] ?? null);
        if (! $revision || ! in_array($revision->status, self::ACTIVE, true) || ($workspace->operation['id'] ?? null) !== $revision->id) {
            return;
        }
        if ($workspace->status === 'failed') {
            $revision->update(['status' => ($workspace->operation['paidFailureDefinitive'] ?? false) ? 'failed' : 'needs_attention', 'error_code' => 'revision_processing_failed']);

            return;
        }
        if ($workspace->status !== 'completed') {
            return;
        }
        try {
            $item = $this->authorize($workspace);
            $output = collect($workspace->outputs ?? [])->firstWhere('id', $revision->id.'-source');
            if (($output['status'] ?? '') !== 'completed' || ($output['kind'] ?? '') !== 'image') {
                throw new \RuntimeException('The revision output is not available.');
            }
            $descriptor = app(ListingStudioPaidMedia::class)->completeOutput($item, ['disk' => ListingStudioPaidStorage::DISK, 'path' => $output['path']]);
            LockedWrite::run(fn () => DB::transaction(function () use ($item, $revision, $workspace, $descriptor): void {
                $order = ListingStudioOrder::whereKey($item->order_id)->lockForUpdate()->firstOrFail();
                $item = ListingStudioOrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                $item->setRelation('order', $order);
                $item->touch();
                $fresh = ListingStudioAiRevision::findOrFail($revision->id);
                if ($fresh->status === 'completed') {
                    return;
                }
                if (! $this->funded($item) || (StudioWorkspace::find($workspace->id)?->operation['id'] ?? null) !== $fresh->id) {
                    throw new \RuntimeException('This revision needs payment review.');
                }
                $fresh->update(['status' => 'completed', 'output_descriptor' => $descriptor, 'completed_at' => now(), 'error_code' => null]);
                $item->update(['output_descriptor' => $descriptor]);
            }), 'listing-studio.ai-revision-complete');
        } catch (\Throwable $exception) {
            $revision->refresh();
            if ($revision->status !== 'completed') {
                $revision->update(['status' => 'needs_attention', 'error_code' => 'revision_output_unavailable']);
            }
            throw $exception;
        }
    }

    public function present(ListingStudioOrderItem $item): array
    {
        $rows = ListingStudioAiRevision::where('order_item_id', $item->id)->orderBy('sequence')->get();
        $active = $rows->first(fn ($row) => in_array($row->status, self::ACTIVE, true));
        $workspace = $item->workspace_id ? StudioWorkspace::find($item->workspace_id) : null;
        $ready = $this->enabled() && app(StudioProviderSettings::class)->readiness('revision', app(StudioProviderSettings::class)->route('revision'))['ready'];

        return ['limit' => self::LIMIT, 'used' => $rows->count(), 'remaining' => max(0, self::LIMIT - $rows->count()),
            'canRequest' => $ready && $this->funded($item) && $workspace && ! $workspace->isBusy() && ! $active && $rows->count() < self::LIMIT,
            'active' => $active ? ['id' => $active->id, 'number' => $active->sequence, 'status' => $active->status,
                'progress' => $active->status === 'processing' ? $workspace?->progress : null,
                'error' => $active->status === 'needs_attention' ? 'We are recovering this existing revision. Your previous versions remain available.' : null] : null,
            'versions' => array_merge([['id' => 'initial', 'number' => 0, 'createdAt' => $item->completed_at?->toIso8601String()]],
                $rows->where('status', 'completed')->map(fn ($row) => ['id' => $row->id, 'number' => $row->sequence, 'createdAt' => $row->completed_at?->toIso8601String()])->values()->all())];
    }

    public function version(ListingStudioOrderItem $item, ?string $version): ?array
    {
        if (! $version) {
            return $item->output_descriptor;
        }
        if ($version === 'initial') {
            return ListingStudioAiRevision::where('order_item_id', $item->id)->orderBy('sequence')->first()?->base_descriptor ?? $item->output_descriptor;
        }
        return ListingStudioAiRevision::where('order_item_id', $item->id)->where('status', 'completed')->findOrFail($version)->output_descriptor;
    }

    public function resume(StudioWorkspace $workspace): void
    {
        $job = new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']);
        $lock = Cache::store('database')->lock($job->middleware()[0]->getLockKey($job), 60);
        if (! $lock->get()) {
            throw new StudioProviderException('The existing revision is still running.');
        }
        try {
            $workspace->refresh();
            $revision = ListingStudioAiRevision::findOrFail($workspace->operation['aiRevisionId']);
            if ($workspace->status === 'completed') {
                $this->sync($workspace);
                return;
            }
            if ($revision->status !== 'needs_attention' || app(ListingStudioPaidPipeline::class)->hasUnconfirmedSubmission($workspace)) {
                throw new StudioProviderException('Confirm the existing provider outcome before recovery. No new submission was made.', true);
            }
            $this->authorize($workspace);
            $this->assertMaySubmit($workspace);
            LockedWrite::run(fn () => DB::transaction(function () use ($workspace, $revision): void {
                $operation = $workspace->operation;
                unset($operation['paidFailureDefinitive']);
                $workspace->update(['status' => 'generating', 'operation' => $operation, 'error' => null, 'version' => $workspace->version + 1]);
                $revision->update(['status' => 'processing', 'error_code' => null]);
            }), 'listing-studio.ai-revision-recovery');
            ProcessStudioWorkspace::dispatch($workspace->id, $revision->id);
        } finally {
            $lock->release();
        }
    }

    private function funded(ListingStudioOrderItem $item): bool
    {
        $order = $item->order;
        return $item->kind === 'ai_edit' && $item->status === 'completed' && $order?->funding_status === 'settled' && $order->refunded_amount_cents === 0;
    }

    private function enabled(): bool
    {
        return (bool) config('listing-studio.paid_enabled', false) && (bool) config('listing-studio.ai_revisions_enabled', true);
    }

    private function payload(ListingStudioAiRevision $revision): array
    {
        return ['mediaId' => 'source', 'outputId' => $revision->base_output_id, 'customEdit' => true,
            'prompt' => 'Revise this real-estate listing photograph. Preserve its camera angle, perspective, room geometry, architecture, permanent features and all areas outside the requested change. Keep photographic realism. Follow only these requested visual adjustments: '.$revision->instructions];
    }

    private function assertReplay(ListingStudioAiRevision $revision, string $hash): void
    {
        if (! hash_equals($revision->request_hash, $hash)) {
            throw new PublicApiException('This request key was already used for different changes.', 'idempotency_conflict', 409);
        }
    }
}
