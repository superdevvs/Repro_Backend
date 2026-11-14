<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use Illuminate\Support\Collection;

/** Guest links come from released evidence, never an unfiltered tour-links payload. */
class ShootGuestTours
{
    public function build(Shoot $shoot, Collection $files, string $token): array
    {
        $tours = [];
        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $units = $shoot->units()->get();
        if ($units->isEmpty() && app(PublicTourImage::class)->canPublish($shoot) && $files->isNotEmpty()) {
            $tours[] = $this->link('Branded property tour', $base.'/tour/branded?shootId='.$shoot->id, 'property', 'repro');
            $tours[] = $this->link('MLS property tour', $base.'/tour/mls?shootId='.$shoot->id, 'property', 'repro');
        }
        foreach ($units as $unit) {
            if ($files->contains(fn ($file) => (int) $file->serviceItem?->shoot_unit_id === (int) $unit->id)) {
                $tours[] = $this->link(($unit->label ?: 'Unit '.$unit->id).' property tour',
                    $base.'/tour/branded?shootId='.$shoot->id.'&unitId='.$unit->id, 'property', 'repro');
            }
        }
        $plan = app(DeliveryPlanService::class);
        foreach ($files as $file) {
            if ($plan->contentKind($file) === 'video') {
                $tours[] = $this->link($file->filename ?: 'Property video',
                    route('api.guest-shares.file', ['token' => $token, 'file' => $file->id]), 'video', 'repro')
                    + ['file_id' => (int) $file->id];
            }
        }
        $items = $shoot->serviceItems()->with('service')->get()->keyBy('id');
        $suspended = collect([$shoot->status, $shoot->workflow_status])->contains(fn ($state) =>
            in_array($state, ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined', 'requested'], true));
        if ($suspended) return collect($tours)->unique('url')->values()->all();

        foreach ($plan->plan($shoot, null)['components'] as $component) {
            $item = $items->get($component['shoot_service_id']);
            $item?->setRelation('shoot', $shoot);
            $url = $component['external_url'] ?? null;
            if ($component['status'] !== 'delivered' || ($component['delivery_method'] ?? null) !== 'external'
                || ! $item?->is_unlocked_for_delivery || ! $this->validUrl($url)) continue;
            $kind = $component['kind'] === 'video' ? 'video' : '3d';
            $provider = $this->provider($component['external_evidence']['provider'] ?? '', $url);
            $tours[] = $this->link($component['label'], $url, $kind, $provider);
        }

        // Old fully delivered orders predate component receipts. Preserve their
        // existing paid link access, but never use this fallback for partial or
        // reopened work, where each component needs its own release evidence.
        $legacyItems = $items->filter(fn ($item) => $item->is_deliverable && ! $item->cancelled_at);
        $legacyDelivered = $legacyItems->isNotEmpty() && $legacyItems->every(function ($item) use ($shoot) {
            $item->setRelation('shoot', $shoot);
            return $item->workflow_status === 'delivered' && $item->delivery_status === 'delivered' && $item->is_unlocked_for_delivery;
        });
        if ($legacyDelivered && ! $plan->usesComponents($shoot) && ! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
            && ($shoot->workflow_status ?: $shoot->status) === Shoot::STATUS_DELIVERED
            && ! app(ShootClientReleaseAccessService::class)->isPublicReleaseLocked($shoot)) {
            $links = $shoot->tour_links ?? [];
            $bookedVideo = $items->contains(fn ($item) => $item->is_deliverable && ! $item->cancelled_at && $item->service?->supportsVideoIntake());
            $known = [
                'matterport_branded' => ['Matterport 3D tour', '3d', 'matterport'],
                'matterport_mls' => ['Matterport 3D tour (MLS)', '3d', 'matterport'],
                'iguide_branded' => ['iGUIDE 3D tour', '3d', 'iguide'],
                'iguide_mls' => ['iGUIDE 3D tour (MLS)', '3d', 'iguide'],
                'zillow_3d' => ['Zillow 3D Home', '3d', 'zillow'],
                'cubicasa_branded' => ['CubiCasa tour', '3d', 'cubicasa'],
                'cubicasa_mls' => ['CubiCasa tour (MLS)', '3d', 'cubicasa'],
                'video_link' => ['Property video', 'video', 'external'],
                'video_branded' => ['Branded video', 'video', 'external'],
                'video_mls' => ['MLS video', 'video', 'external'],
                'video_generic' => ['Unbranded video', 'video', 'external'],
            ];
            foreach ($known as $key => [$label, $kind, $provider]) {
                if (($kind !== 'video' || $bookedVideo) && $this->validUrl($links[$key] ?? null)) {
                    $tours[] = $this->link($label, $links[$key], $kind, $provider);
                }
            }
            foreach (['iguide' => $shoot->iguide_tour_url, 'cubicasa' => $shoot->cubicasa_tour_url] as $provider => $url) {
                if ($this->validUrl($url)) $tours[] = $this->link($provider === 'iguide' ? 'iGUIDE 3D tour' : 'CubiCasa tour', $url, '3d', $provider);
            }
        }
        return collect($tours)->unique('url')->values()->all();
    }

    private function link(string $label, string $url, string $kind, string $provider): array { return compact('label', 'url', 'kind', 'provider'); }

    private function validUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true);
    }

    private function provider(string $evidence, string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (['iguide', 'matterport', 'zillow', 'cubicasa'] as $provider) {
            if (str_starts_with($evidence, $provider) || str_contains($host, $provider)) return $provider;
        }
        return 'external';
    }
}
