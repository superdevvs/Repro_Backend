<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Support\Collection;

class NoMediaDeliveryEligibility
{
    private const FINALIZE_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
    ];

    private const LINK_ONLY_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
    ];

    private const ALLOWED_STATUSES = [
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_ON_HOLD,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_READY,
    ];

    private const VIDEO_LINK_KEYS = [
        'video_link',
        'video_branded',
        'video_mls',
        'video_generic',
    ];

    /**
     * Decide whether an actor may deliver the whole shoot without uploaded media.
     *
     * The legacy internal/free-shoot path remains available to the roles that
     * could already finalize shoots. The video-link exception is deliberately
     * narrower: only admins, with a qualifying saved link and service mix.
     */
    public function allows(Shoot $shoot, ?User $actor, ?iterable $bookedServices = null): bool
    {
        if (! $actor || ! in_array($this->role($actor), self::FINALIZE_ROLES, true)) {
            return false;
        }

        if (! in_array($this->status($shoot), self::ALLOWED_STATUSES, true)) {
            return false;
        }

        if ($this->hasUploadedMedia($shoot)) {
            return false;
        }

        if ($shoot->allowsNoMediaDelivery()) {
            return true;
        }

        if (! in_array($this->role($actor), self::LINK_ONLY_ROLES, true)) {
            return false;
        }

        return $this->hasValidVideoLink($shoot)
            && $this->hasQualifyingServiceMix($shoot, $bookedServices);
    }

    private function role(User $actor): string
    {
        return strtolower(trim((string) ($actor->role ?? '')));
    }

    private function status(Shoot $shoot): string
    {
        $status = strtolower(trim((string) ($shoot->workflow_status ?: $shoot->status ?: '')));

        return $status === 'booked' ? Shoot::STATUS_SCHEDULED : $status;
    }

    private function hasUploadedMedia(Shoot $shoot): bool
    {
        if ($shoot->relationLoaded('files')) {
            return $shoot->getRelation('files')->isNotEmpty();
        }

        return $shoot->files()->exists();
    }

    private function hasValidVideoLink(Shoot $shoot): bool
    {
        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];

        foreach (self::VIDEO_LINK_KEYS as $key) {
            $url = trim((string) ($tourLinks[$key] ?? ''));
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasQualifyingServiceMix(Shoot $shoot, ?iterable $bookedServices): bool
    {
        /** @var Collection<int, ShootService|array<string, mixed>> $items */
        $items = $bookedServices !== null
            ? collect($bookedServices)
                ->filter(fn ($item) => ! is_array($item) || ! ($item['is_invoice_adjustment'] ?? false))
                ->values()
            : $shoot->serviceItems()->with('service:id,name')->get();

        if ($items->isEmpty()) {
            if (! $shoot->service_id) {
                return true;
            }

            $legacyService = $shoot->relationLoaded('service')
                ? $shoot->getRelation('service')
                : $shoot->service()->first(['id', 'name']);

            return str_contains(strtolower((string) ($legacyService?->name ?? '')), 'test');
        }

        return $items->contains(function (ShootService|array $item): bool {
            if ($item instanceof ShootService) {
                $name = (string) ($item->service?->name ?? '');
                $subtotal = (float) $item->subtotal;
            } else {
                $name = (string) ($item['name'] ?? $item['serviceName'] ?? '');
                $subtotal = array_key_exists('subtotal', $item)
                    ? (float) $item['subtotal']
                    : (float) ($item['price'] ?? 0) * max((int) ($item['quantity'] ?? 1), 1);
            }

            return str_contains(strtolower($name), 'test') || round($subtotal, 2) <= 0.01;
        });
    }
}
