<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiDestination;
use App\Models\AryeoApiExport;

class Inventory
{
    public function read(AryeoApiDestination $d): array
    {
        return array_map(fn ($a) => $this->project($a), app(Client::class)->listing($d->installation, '/place-assets', ['filters[place_id]' => $d->provider_place_id]));
    }

    public function project(array $a): array
    {
        return ['id' => $a['id'] ?? null, 'asset_id' => data_get($a, 'asset.id'), 'asset_type' => data_get($a, 'asset.resource_type'),
            'type' => data_get($a, 'asset.type'), 'mime_type' => data_get($a, 'asset.mime_type'), 'subsection_id' => $a['subsection_id'] ?? null,
            'position' => $a['position'] ?? null, 'title' => $a['title'] ?? null,
            'displays_on_property_websites' => $a['displays_on_property_websites'] ?? null,
            'display_type' => $a['display_type'] ?? null, 'delivery_id' => $a['delivery_id'] ?? null];
    }

    public function assertPackage(AryeoApiExport $export, array $remote, bool $afterDelivery = false): void
    {
        $assets = $export->assets()->get();
        $new = $assets->where('ownership', 'created')->pluck('place_asset_id')->filter()->all();
        $baselineNow = array_values(array_filter($remote, fn ($a) => ! in_array($a['id'], $new, true)));
        abort_unless($baselineNow === $export->remote_baseline, 409, 'remote_inventory_changed');
        $ids = array_column($remote, 'id');
        abort_unless(count($ids) === count(array_unique($ids)), 409, 'remote_inventory_ambiguous');
        $expectedNew = $assets->where('ownership', 'created')->pluck('place_asset_id')->all();
        $actualNew = array_column(array_values(array_filter($remote, fn ($a) => ! $a['delivery_id'] || ($afterDelivery && $a['delivery_id'] === $export->provider_delivery_id))), 'id');
        sort($expectedNew);
        sort($actualNew);
        abort_unless($expectedNew === $actualNew, 409, 'unexpected_undelivered_assets');
        foreach ($assets as $asset) {
            $actual = collect($remote)->firstWhere('id', $asset->place_asset_id);
            abort_unless($actual && $actual['asset_id'] === $asset->provider_asset_id
                && $actual['asset_type'] === ($asset->source['kind'] === 'file' ? 'file' : 'link'), 409, 'asset_association_mismatch');
            if ($asset->ownership === 'created') {
                if ($asset->source['kind'] === 'file') {
                    abort_unless($actual['mime_type'] === $asset->source['mime_type'], 409, 'ingested_format_mismatch');
                }
                $expected = $asset->presentation;
                abort_unless(is_array($expected), 409, 'asset_presentation_unverified');
                $expected['delivery_id'] = $afterDelivery ? $export->provider_delivery_id : null;
                abort_unless($expected === $actual, 409, 'asset_presentation_changed');
            }
            if ($afterDelivery && $asset->ownership === 'created') {
                abort_unless($actual['delivery_id'] === $export->provider_delivery_id, 409, 'delivery_evidence_incomplete');
            }
        }
        // New assets must retain approved order within each section.
        foreach (['photos', 'floor_plans', 'videos', 'interactive_content'] as $section) {
            $expected = $assets->filter(fn ($a) => $a->ownership === 'created' && $a->source['section'] === $section)
                ->sortBy(fn ($a) => $a->source['order'])->pluck('place_asset_id')->values()->all();
            $actual = array_values(array_filter($ids, fn ($id) => in_array($id, $expected, true)));
            abort_unless($expected === $actual, 409, 'remote_order_changed');
        }
    }
}
