<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootGuestShare;
use Illuminate\Support\Collection;

/** An explicit guest allowlist, never the authenticated shoot presenter. */
class ShootGuestOverview
{
    public function allowsFile(Shoot $shoot, ShootFile $file): bool
    {
        return (int) $file->shoot_id === (int) $shoot->id
            && app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file)
            && app(PublicTourImage::class)->canRetainPublishedFile($file, $shoot);
    }

    public function files(Shoot $shoot): Collection
    {
        $files = $shoot->files()->with('serviceItem')->get()->filter(fn (ShootFile $file) => $this->allowsFile($shoot, $file));
        return app(DeliveryMediaOrderService::class)->applyTo($shoot, $files)->values();
    }

    public function build(ShootGuestShare $share, string $token): array
    {
        $shoot = $share->shoot;
        $plan = app(DeliveryPlanService::class);
        $files = $this->files($shoot);
        $details = $shoot->property_details ?? [];
        $tours = [];
        $units = $shoot->units()->get();
        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        if ($units->isEmpty() && app(PublicTourImage::class)->canPublish($shoot) && $files->isNotEmpty()) {
            $tours[] = ['label' => 'Branded property tour', 'url' => $base.'/tour/branded?shootId='.$shoot->id];
            $tours[] = ['label' => 'MLS property tour', 'url' => $base.'/tour/mls?shootId='.$shoot->id];
        }
        foreach ($units as $unit) {
            if ($files->contains(fn ($file) => (int) $file->serviceItem?->shoot_unit_id === (int) $unit->id)) {
                $tours[] = ['label' => ($unit->label ?: 'Unit '.$unit->id).' property tour',
                    'url' => $base.'/tour/branded?shootId='.$shoot->id.'&unitId='.$unit->id];
            }
        }
        $items = $shoot->serviceItems()->with('service')->get()->keyBy('id');
        $suspended = collect([$shoot->status, $shoot->workflow_status])->contains(fn ($state) =>
            in_array($state, ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined', 'requested'], true));
        foreach ($plan->plan($shoot, null)['components'] as $component) {
            $item = $items->get($component['shoot_service_id']);
            $item?->setRelation('shoot', $shoot);
            $url = $component['external_url'] ?? null;
            if (! $suspended && $component['status'] === 'delivered' && ($component['delivery_method'] ?? null) === 'external'
                && $item?->is_unlocked_for_delivery
                && is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
                $tours[] = ['label' => $component['label'], 'url' => $url];
            }
        }
        $summaries = $plan->componentSummaries($shoot);
        return [
            'property' => [
                'address' => $shoot->address, 'city' => $shoot->city, 'state' => $shoot->state, 'zip' => $shoot->zip,
                'beds' => $details['bedrooms'] ?? $details['beds'] ?? null,
                'baths' => $details['bathrooms'] ?? $details['baths'] ?? null,
                'sqft' => $details['sqft'] ?? $details['squareFeet'] ?? null,
            ],
            'services' => $items->filter(fn ($item) => $item->is_deliverable && ! $item->cancelled_at)
                ->map(fn ($item) => ['name' => $item->service?->name,
                    'components' => $summaries[$item->id] ?? [['label' => 'Delivery', 'status' => $item->delivery_status]]])->values()->all(),
            'files' => $files->map(fn ($file) => [
                'id' => $file->id, 'name' => $file->filename,
                'kind' => $plan->contentKind($file) ?? 'file',
                'preview_url' => route('api.guest-shares.file', ['token' => $token, 'file' => $file->id]),
                'download_url' => route('api.guest-shares.file', ['token' => $token, 'file' => $file->id, 'download' => 1]),
            ])->all(),
            'tours' => collect($tours)->unique('url')->values()->all(),
            'expires_at' => $share->expires_at->toIso8601String(),
        ];
    }
}
