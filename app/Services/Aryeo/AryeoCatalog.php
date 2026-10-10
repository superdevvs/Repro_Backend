<?php

namespace App\Services\Aryeo;

use App\Models\AryeoConnection;
use App\Models\Shoot;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootDownloadAssetClassifier;
use App\Services\Shoots\ShootUnitTourScope;

class AryeoCatalog
{
    public function query(AryeoConnection $connection)
    {
        return Shoot::query()->whereIn('client_id', $connection->client_ids ?? []);
    }

    public function shoot(AryeoConnection $connection, int $id): Shoot
    {
        return $this->query($connection)->findOrFail($id);
    }

    public function identity(Shoot $shoot): array
    {
        return [
            'id' => $shoot->id, 'client_id' => $shoot->client_id,
            'client_email' => $shoot->client?->email,
            'address' => $shoot->address, 'city' => $shoot->city, 'state' => $shoot->state, 'zip' => $shoot->zip,
            'scheduled_date' => $shoot->scheduled_date?->format('Y-m-d'),
            'status' => $shoot->status, 'workflow_status' => $shoot->workflow_status,
            // Only return the explicit source field, never guess that the new ID
            // or an unrelated provider ID is the historical Pro identity.
            'imported_pro_shoot_id' => data_get($shoot->external_booking_payload, 'legacy_migration.pro_shoot_id')
                ?? (data_get($shoot->external_booking_payload, 'legacy_migration.source') === 'pro.reprophotos.com'
                    ? data_get($shoot->external_booking_payload, 'legacy_migration.source_id') : null),
            'units' => $shoot->units->map(fn ($u) => ['id' => $u->id, 'label' => $u->label, 'kind' => $u->kind])->values()->all(),
        ];
    }

    public function readiness(Shoot $shoot, ?int $unitId, ?array $requirements = null): array
    {
        $shoot->loadMissing(['units', 'serviceItems.service', 'files.serviceItem.service.category']);
        $unit = $unitId ? $shoot->units->firstWhere('id', $unitId) : null;
        abort_if($unitId && ! $unit, 404);
        $blockers = [];
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        if (in_array(strtolower((string) $shoot->status), ['cancelled', 'on_hold', 'import_draft', 'requested'])
            || in_array($status, ['cancelled', 'on_hold', 'import_draft', 'requested'])) {
            $blockers[] = 'shoot_not_released';
        }
        if (! in_array($status, ['ready', 'ready_for_client', 'delivered', 'completed'])) {
            $blockers[] = 'editing_not_approved';
        }
        $dashboardDelivered = $status === 'delivered' || strtolower((string) $shoot->status) === 'delivered';
        if (! $dashboardDelivered) {
            $blockers[] = 'dashboard_delivery_pending';
        }
        if (! $shoot->admin_verified_at) {
            $blockers[] = 'admin_verification_required';
        }
        if ($shoot->suppressesExternalNotifications()) {
            $blockers[] = 'external_delivery_suppressed';
        }
        if ($shoot->units->count() > 1 && ! $unit) {
            $blockers[] = 'unit_required';
        }

        $lines = $shoot->serviceItems;
        $files = $shoot->files;
        $links = $shoot->tour_links ?? [];
        if ($unit) {
            $projection = app(ShootUnitTourScope::class)->project($shoot, $unit);
            $files = $projection->files;
            $lines = $unit->serviceItems;
            $links = $unit->tour_links ?? [];
        }
        $release = app(ShootClientReleaseAccessService::class);
        $paymentKnown = $shoot->payment_status === 'paid' || $shoot->total_quote !== null;
        $paid = $paymentKnown && $release->resolvePaymentStatus($shoot) === 'paid';
        $wholeReleased = $shoot->bypass_paywall || $paid;
        $approvedLine = fn ($line) => $line && $line->is_deliverable && in_array($line->delivery_status, ['ready', 'delivered']);
        $unlocked = fn ($line) => $approvedLine($line)
            && ($wholeReleased || $line->force_unlock_delivery || ($line->is_unlocked_for_delivery && in_array($line->delivery_status, ['ready', 'delivered'])));
        $classifier = app(ShootDownloadAssetClassifier::class);
        $assets = [];
        $withheld = 0;
        foreach ($files->sortBy('sort_order') as $file) {
            if ($file->is_hidden || $file->isBlockedFromDelivery()
                || ! in_array($file->workflow_stage, ['completed', 'verified'])
                || app(ShootAuthorizationSupport::class)->isRawCameraFile($file)
                || $classifier->type($file) === 'other') {
                continue;
            }
            if ($file->serviceItem && ! $approvedLine($file->serviceItem)) {
                continue;
            }
            if ($file->workflow_stage !== 'verified' && ! $file->verified_at
                && (! $shoot->admin_verified_at || ($file->uploaded_at ?? $file->created_at)?->gt($shoot->admin_verified_at))) {
                continue;
            }
            if (! $wholeReleased && ! $unlocked($file->serviceItem)) {
                $withheld++;

                continue;
            }
            if ($file->serviceItem?->delivery_status === 'cancelled') {
                continue;
            }
            $assets[] = [
                'id' => $file->id, 'type' => $classifier->type($file), 'filename' => $file->filename,
                'bytes' => $file->file_size, 'sha256' => data_get($file->metadata, 'sha256'),
                'extra' => (bool) $file->is_extra, 'order' => $file->sort_order,
                'revision' => hash('sha256', json_encode([$file->updated_at, $file->path, $file->storage_path, $file->file_size])),
            ];
        }
        $tours = [];
        // Per-service release of links is not inferred from a staff's access.
        // Fees have no media to release. They must not hide an approved tour.
        $deliverableLines = $lines->filter(fn ($line) => $line->is_deliverable);
        $allLinksReleased = $lines->isNotEmpty()
            ? ($deliverableLines->isNotEmpty() && $deliverableLines->every($unlocked))
            : ($wholeReleased && ! $unit);
        if ($allLinksReleased) {
            foreach (['zillow_3d', 'matterport_branded', 'matterport_mls', 'iguide_branded', 'iguide_mls', 'video_link', 'video_branded', 'video_mls'] as $key) {
                $url = $links[$key] ?? null;
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://')) {
                    $tours[] = ['id' => $key, 'url' => $url];
                }
            }
        }
        $available = ['photos' => 0, 'floorplans' => 0, 'videos' => 0, 'tours' => count($tours)];
        foreach ($assets as $asset) {
            $available[$asset['type']]++;
        }
        if (! $paid) {
            $blockers[] = 'payment_required';
        }
        if ($requirements === null) {
            $blockers[] = 'request_requirements_unknown';
        }
        foreach ($requirements ?? [] as $kind => $count) {
            // A requested category need not specify a quantity in the Showcase
            // email. Include all approved media; require at least one of that kind.
            $minimum = $count === null ? 1 : $count;
            if (($available[$kind] ?? 0) < $minimum) {
                $blockers[] = 'missing_'.$kind;
            }
        }
        if (array_sum($available) === 0) {
            $blockers[] = 'no_approved_media';
        }
        // Availability describes the shoot; the manifest describes this request.
        // Null means a requested category with no specified quantity, while zero
        // means it was not requested and must not be uploaded or receipted.
        if ($requirements !== null) {
            $requested = fn (string $kind) => array_key_exists($kind, $requirements)
                && ($requirements[$kind] === null || $requirements[$kind] > 0);
            $assets = array_values(array_filter($assets, fn ($asset) => $requested($asset['type'])));
            $tours = $requested('tours') ? $tours : [];
            if (! $assets && ! $tours) {
                $blockers[] = 'no_approved_media';
            }
        }
        $version = hash('sha256', json_encode([$shoot->id, $unitId, $assets, $tours]));

        return [
            'shoot_id' => $shoot->id, 'unit_id' => $unitId, 'eligible' => ! $blockers,
            'dashboard' => ['paid' => $paid, 'delivered' => $dashboardDelivered, 'summary_required' => false],
            'blockers' => array_values(array_unique($blockers)), 'required' => $requirements,
            'available' => $available, 'withheld_for_payment' => $withheld,
            'media_version' => $version, 'assets' => $assets, 'tours' => $tours,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
