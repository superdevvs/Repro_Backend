<?php

namespace App\Services\Photographers;

use App\Models\User;
use App\Services\Users\PhotographerAddressPolicy;

/** Explicit, viewer-scoped pins; never geocodes or returns raw account metadata. */
class AvailabilityMapLocation
{
    public function __construct(private PhotographerAddressPolicy $addresses) {}

    /**
     * Null coordinates mean unavailable. An exact-address owner can resolve the
     * canonical profile address when stored coordinates are absent. Region/hidden
     * visibility never falls back to private home coordinates or address fields.
     *
     * @return array{photographer_id:int,latitude:?float,longitude:?float,precision:?string,label:?string,geocoding_address:?array{address:string,city:string,state:string,zip:string}}
     */
    public function present(User $viewer, User $photographer): array
    {
        $location = [
            'photographer_id' => (int) $photographer->id,
            'latitude' => null,
            'longitude' => null,
            'precision' => null,
            'label' => null,
            'geocoding_address' => null,
        ];

        if (! $this->addresses->isPhotographer($photographer)
            || ! $this->addresses->canViewFullAddress($viewer, $photographer)) {
            return $location;
        }

        $location['geocoding_address'] = $this->canonicalGeocodingAddress($photographer);

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
            // Existing valid coordinates need no additional private address data.
            'geocoding_address' => null,
        ]);
    }

    /** @return array{address:string,city:string,state:string,zip:string}|null */
    private function canonicalGeocodingAddress(User $photographer): ?array
    {
        $address = [];
        foreach (['address' => 255, 'city' => 100, 'state' => 100, 'zip' => 20] as $field => $limit) {
            $value = $photographer->getAttribute($field);
            if ($value !== null && (! is_string($value) || mb_strlen($value) > $limit)) {
                return null;
            }
            $address[$field] = is_string($value) ? trim($value) : '';
        }

        // A street-only legacy value is too ambiguous to locate confidently.
        // Never substitute metadata/homeAddress aliases or pending changes.
        return $address['address'] !== '' && $address['city'] !== '' && $address['state'] !== ''
            ? $address : null;
    }
}
