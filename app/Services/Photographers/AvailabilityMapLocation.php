<?php

namespace App\Services\Photographers;

use App\Models\User;
use App\Services\Users\PhotographerAddressPolicy;

/** Explicit, viewer-scoped pins; never geocodes or returns raw account metadata. */
class AvailabilityMapLocation
{
    public function __construct(private PhotographerAddressPolicy $addresses) {}

    /**
     * Null coordinates mean unavailable. No stored, explicitly safe region source
     * currently exists, so region/hidden visibility never falls back to home pins.
     *
     * @return array{photographer_id:int,latitude:?float,longitude:?float,precision:?string,label:?string}
     */
    public function present(User $viewer, User $photographer): array
    {
        $location = [
            'photographer_id' => (int) $photographer->id,
            'latitude' => null,
            'longitude' => null,
            'precision' => null,
            'label' => null,
        ];

        if (! $this->addresses->isPhotographer($photographer)
            || ! $this->addresses->canViewFullAddress($viewer, $photographer)) {
            return $location;
        }

        $metadata = $photographer->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        if (! is_array($metadata)) {
            return $location;
        }

        // Same stored coordinate aliases as PhotographerPickerMapBuilder.
        $latitude = $metadata['latitude'] ?? $metadata['lat'] ?? null;
        $longitude = $metadata['longitude'] ?? $metadata['lng'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return $location;
        }
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (! is_finite($latitude) || ! is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            return $location;
        }

        return array_replace($location, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'precision' => 'base',
            'label' => 'Base',
        ]);
    }
}
