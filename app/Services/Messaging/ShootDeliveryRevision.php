<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootUnit;

/** Stable delivery identity: edits to notes, payment anchors or ordering are not new media. */
class ShootDeliveryRevision
{
    public function forShoot(Shoot $shoot, array $serviceItemIds = []): string
    {
        $files = $shoot->files()->verifiedForFinalizedDelivery()
            ->when($serviceItemIds !== [], fn ($query) => $query->whereIn('shoot_service_id', $serviceItemIds))
            ->orderBy('id')->get()
            ->filter(fn (ShootFile $file) => ! $file->is_hidden && ! $file->isBlockedFromDelivery() && ! $file->isIguideOfflinePackage())
            ->map(fn (ShootFile $file) => $file->only([
                'id', 'shoot_service_id', 'content_version', 'path', 'storage_path', 'file_size', 'media_type',
            ]))->values()->all();
        $services = $shoot->serviceItems()->where('workflow_status', 'delivered')->where('delivery_status', 'delivered')
            ->when($serviceItemIds !== [], fn ($query) => $query->whereIn('id', $serviceItemIds))
            ->orderBy('id')->pluck('id')->all();
        $links = array_intersect_key((array) ($shoot->tour_links ?? []), array_flip([
            'mls', 'generic_mls', 'genericMls', 'branded', 'property_url', 'property_link', 'zillow_3d',
            'video_mls', 'video_branded', 'video_generic', 'video_link', 'matterport_mls', 'matterport_branded',
            'matterport', 'iguide_mls', 'iguide_branded', 'iGuide', 'iguide', 'cubicasa_mls', 'cubicasa_branded',
            'cubicasa_url', 'slideshow', 'slideshow_url', 'neo_tour', 'neotour',
        ]));
        $embeds = collect(data_get($shoot->tour_links, 'embeds', []))->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => array_intersect_key($item, array_flip(['url', 'mls', 'branded', 'label', 'title'])))->values()->all();
        if ($embeds !== []) {
            $links['embeds'] = $embeds;
        }
        if ($shoot->iguide_tour_url) {
            $links['iguide_tour_url'] = $shoot->iguide_tour_url;
        }
        if ($package = $this->publicOfflinePackage($shoot)) {
            $links['offline_package'] = $package;
        }
        ksort($links);
        $units = $shoot->units()->orderBy('id')->get()->mapWithKeys(fn (ShootUnit $unit) => [
            $unit->id => $this->unitIdentity($unit, $serviceItemIds),
        ])->filter()->all();

        return hash('sha256', json_encode([$files, $services, $links, $units], JSON_THROW_ON_ERROR));
    }

    /** Unit labels, notes, sync receipts and pending provider lines are not delivery revisions. */
    public function unitIdentity(ShootUnit $unit, array $serviceItemIds = []): array
    {
        $lines = $unit->serviceItems()->where('is_deliverable', true)->where('workflow_status', '!=', 'cancelled')
            ->where('delivery_status', '!=', 'cancelled')->get();
        $released = $lines->filter(fn ($line) => $line->workflow_status === 'delivered' && $line->delivery_status === 'delivered'
            && ($serviceItemIds === [] || in_array((int) $line->id, $serviceItemIds, true)))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($released === []) {
            return [];
        }
        $safe = app(\App\Services\Shoots\ShootUnitTourScope::class)->releasedTourData($unit, $released, $lines->count());
        $links = array_intersect_key($safe['tour_links'], array_flip([
            'mls', 'generic_mls', 'genericMls', 'branded', 'property_url', 'property_link', 'zillow_3d',
            'video_mls', 'video_branded', 'video_generic', 'video_link', 'embeds', 'matterport_mls', 'matterport_branded',
            'matterport', 'iguide_mls', 'iguide_branded', 'iGuide', 'iguide', 'cubicasa_mls', 'cubicasa_branded',
            'cubicasa_url', 'slideshow', 'slideshow_url', 'neo_tour', 'neotour',
        ]));
        $provider = [];
        $data = $safe['provider_data'];
        foreach (['unit' => $data] + (array) ($data['lines'] ?? []) as $lineId => $lineData) {
            if (! is_array($lineData)) {
                continue;
            }
            if (! empty($lineData['iguide_tour_url'])) {
                $provider[$lineId]['iguide_tour_url'] = $lineData['iguide_tour_url'];
            }
            $package = data_get($lineData, 'iguide_data.manual_offline_package');
            if (is_array($package) && ($package['status'] ?? '') === 'ready'
                && data_get($package, 'publication_attestation.policy') === 'authorized_staff_official_iguide_export') {
                $provider[$lineId]['offline_package'] = [
                    'file_id' => (int) ($package['file_id'] ?? 0),
                    'audiences' => data_get($package, 'publication_attestation.audiences', []),
                ];
            }
        }
        ksort($links);

        return $links === [] && $provider === [] ? [] : ['links' => $links, 'provider' => $provider];
    }

    /** Upload/extraction state alone is not a published client deliverable. */
    public function publicOfflinePackage(Shoot $shoot): array
    {
        $package = data_get($shoot->iguide_data, 'manual_offline_package');
        if (! is_array($package) || ($package['status'] ?? '') !== 'ready') {
            return [];
        }
        $audiences = app(\App\Services\IguideOfflineViewerService::class)->publishedAudiences($shoot);

        return $audiences === [] ? [] : ['file_id' => (int) ($package['file_id'] ?? 0), 'audiences' => $audiences];
    }

    public function isUpdate(Shoot $shoot, string $source, string $revision): bool
    {
        return Message::query()->where('related_shoot_id', $shoot->id)->where('send_source', $source)
            ->whereIn('status', ['SENT', 'DELIVERED', 'SCHEDULED'])
            ->get(['metadata'])->contains(fn (Message $message) => data_get($message->metadata, 'canonical_email.delivery_revision') !== $revision);
    }
}
