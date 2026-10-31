<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioSourceResolver;
use App\Exceptions\PublicApiException;
use App\Models\ListingStudioGuestTrial;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioUpload;
use App\Models\User;

class ListingStudioPaidSourceResolver implements ListingStudioSourceResolver
{
    public function __construct(private ListingStudioPaidMedia $media) {}

    public function resolve(User $client, string $kind, string $sourceKind, string $sourceId): array
    {
        $this->media->authorizeClient($client);
        if ($kind === 'ai_edit' && $sourceKind === 'upload') {
            $source = $this->media->ownedUpload($client, $sourceId);
            $descriptor = $this->media->descriptor(ListingStudioPaidStorage::DISK, $source->path);
            if (! hash_equals($source->sha256, $descriptor['sha256'])) {
                $this->unavailable();
            }
            $original = $descriptor;
            $preview = $this->media->descriptor(ListingStudioPaidStorage::DISK, $source->preview_path);
        } elseif ($kind === 'human_revision' && $sourceKind === 'paid_item') {
            $source = ListingStudioOrderItem::where('client_id', $client->id)->findOrFail($sourceId);
            if ($source->status !== 'completed' || ! $source->output_descriptor) {
                $this->unavailable();
            }
            $descriptor = $source->output_descriptor;
            if (($descriptor['itemId'] ?? null) !== $source->id || (int) ($descriptor['clientId'] ?? 0) !== (int) $client->id) {
                $this->unavailable();
            }
            $preview = $descriptor['preview'] ?? null;
            $original = $source->source_snapshot['original'] ?? $source->source_snapshot;
            $this->media->read($descriptor);
            $this->media->read($original);
        } elseif ($kind === 'human_revision' && $sourceKind === 'claimed_trial') {
            $source = ListingStudioGuestTrial::where('client_id', $client->id)->findOrFail($sourceId);
            if ($source->status !== 'completed' || ! $source->claimed_at || $source->media_purged_at || $source->hasExpired()
                || ! app(ListingStudioGuestTrialService::class)->outputPathValid($source, (string) $source->output_path)
                || ! str_starts_with($source->source_path, 'trials/'.$source->id.'/')) {
                $this->unavailable();
            }
            $descriptor = $this->media->descriptor(ListingStudioGuestTrialService::DISK, $source->output_path);
            $original = $this->media->descriptor(ListingStudioGuestTrialService::DISK, $source->source_path);
            $preview = $this->media->descriptor(ListingStudioGuestTrialService::DISK, $source->preview_path);
        } else {
            throw new PublicApiException('Choose an owned photo supported by this service.', 'validation_failed', 422);
        }

        return ['kind' => $sourceKind, 'id' => $sourceId, 'clientId' => (int) $client->id, 'service' => $sourceKind === 'upload' ? null : $source->service, 'expiresAt' => $sourceKind === 'upload' ? $source->expires_at->toIso8601String() : null, ...$descriptor, 'original' => $original, 'preview' => $preview];
    }

    /** Revalidate database ownership under the financial acceptance transaction; no filesystem reads. */
    public function pin(array $snapshot, string $itemId): void
    {
        $item = ListingStudioOrderItem::findOrFail($itemId);
        if ((int) ($snapshot['clientId'] ?? 0) !== (int) $item->client_id || ($snapshot['kind'] ?? '') !== $item->source_kind
            || ($snapshot['id'] ?? '') !== $item->source_id) {
            $this->unavailable();
        }
        if ($item->source_kind === 'upload') {
            $source = ListingStudioUpload::where('client_id', $item->client_id)->find($item->source_id);
            if (! $source || $item->kind !== 'ai_edit' || $source->status !== 'ready' || $source->media_purged_at
                || $source->expires_at->isPast() || ! hash_equals($source->sha256, $snapshot['sha256'] ?? '')
                || $source->path !== ($snapshot['path'] ?? '') || ($snapshot['disk'] ?? '') !== ListingStudioPaidStorage::DISK) {
                $this->unavailable();
            }
            $source->update(['pinned_at' => $source->pinned_at ?? now()]);
        } elseif ($item->source_kind === 'paid_item') {
            $source = ListingStudioOrderItem::where('client_id', $item->client_id)->find($item->source_id);
            if (! $source || $item->kind !== 'human_revision' || $source->status !== 'completed' || $source->service !== ($snapshot['service'] ?? null)
                || ($source->output_descriptor['sha256'] ?? '') !== ($snapshot['sha256'] ?? '')
                || ($source->output_descriptor['path'] ?? '') !== ($snapshot['path'] ?? '')) {
                $this->unavailable();
            }
        } elseif ($item->source_kind === 'claimed_trial') {
            $source = ListingStudioGuestTrial::where('client_id', $item->client_id)->find($item->source_id);
            if (! $source || $item->kind !== 'human_revision' || $source->status !== 'completed' || ! $source->claimed_at
                || $source->media_purged_at || $source->hasExpired() || $source->service !== ($snapshot['service'] ?? null) || $source->output_path !== ($snapshot['path'] ?? '')
                || ($snapshot['disk'] ?? '') !== ListingStudioGuestTrialService::DISK) {
                $this->unavailable();
            }
        } else {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw new PublicApiException('The source is no longer available. Choose an owned completed photo or upload again.', 'source_unavailable', 422);
    }
}
