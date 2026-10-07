<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\User;
use App\Services\NominatimRequestThrottler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Trust server verification, not coordinate/place-id fields posted by a client. */
class TravelLocationResolver
{
    private array $memo = [];

    private ?float $lookupDeadline = null;

    public function __construct(private NominatimRequestThrottler $throttler) {}

    public function reset(): void
    {
        $this->memo = [];
        $this->lookupDeadline = microtime(true) + 3;
    }

    public function forShoot(Shoot $shoot): array
    {
        return $this->forPayload([], $shoot);
    }

    public function forPayload(array $payload, ?Shoot $shoot = null, ?User $actor = null): array
    {
        $address = [];
        foreach (['address', 'city', 'state', 'zip'] as $field) {
            $address[$field] = trim((string) ($payload[$field] ?? $shoot?->{$field} ?? ''));
        }
        $city = $this->normalize($address['city']);
        $base = $this->baseAddress($address['address'], $city);
        $state = $this->state($address['state']);
        $zip = preg_match('/^\d{5}(?:-\d{4})?$/', $address['zip']) ? substr($address['zip'], 0, 5) : '';
        $complete = preg_match('/^\d+[A-Z]?(?:[-\/]\d+)?\s+\S+/', $base) === 1 && $city !== ''
            && preg_match('/^[A-Z]{2}$/', $state) === 1 && $zip !== '';
        $hash = hash('sha256', json_encode([$base, $city, $state, $zip, 'US']));
        $location = [
            'version' => 1, 'address' => $address['address'], 'base_address' => $base,
            'city' => $city, 'state' => $state, 'zip' => $zip,
            'full_address' => $complete ? "$base, $city, $state $zip, US" : '',
            'address_hash' => $hash, 'complete' => $complete,
            'latitude' => null, 'longitude' => null, 'source' => 'unverified', 'precision' => 'unknown',
            'verified' => false, 'building_key' => null, 'verified_by' => null, 'verified_at' => null,
            'reason_code' => $complete ? 'location_unverified' : 'location_incomplete',
        ];
        // Ignore payload.property_details.schedule_location entirely. Only a
        // signed, address-bound record already stored on this shoot is reusable.
        $metadata = $shoot?->property_details['schedule_location'] ?? null;
        if (is_array($metadata) && $this->validMetadata($metadata, $hash)) {
            $location = array_merge($location, $this->metadataFields($metadata));
        }
        $confirmation = filter_var($payload['travel_location_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($confirmation && ! $this->canConfirm($actor)) {
            throw ValidationException::withMessages(['travel_location_confirmed' => 'Only authorized scheduling staff can confirm a building address.']);
        }
        if (! $complete) {
            return $location;
        }
        if (! $location['verified'] || $location['precision'] !== 'exact') {
            $coordinates = $this->exactGeocode($location);
            if ($coordinates) {
                $location = array_merge($location, $coordinates, [
                    'source' => 'nominatim_exact_address', 'precision' => 'exact', 'verified' => true,
                    'building_key' => 'address:'.$hash, 'verified_at' => now('UTC')->toIso8601String(),
                    'reason_code' => 'exact_address_verified',
                ]);
            }
        }
        if ($confirmation) {
            $location['verified'] = true;
            $location['building_key'] = 'address:'.$hash;
            $location['source'] = 'staff_confirmed';
            $location['verified_by'] = (int) $actor->id;
            $location['verified_at'] = now('UTC')->toIso8601String();
            $location['reason_code'] = 'staff_confirmed_address';
        }

        return $location;
    }

    /** Store only this result under property_details.schedule_location. */
    public function persistedMetadata(array $location): array
    {
        $metadata = $this->metadataFields($location);
        $metadata['signature'] = $this->signature($metadata);

        return $metadata;
    }

    private function metadataFields(array $location): array
    {
        $fields = ['version', 'address_hash', 'latitude', 'longitude', 'source', 'precision',
            'verified', 'building_key', 'verified_by', 'verified_at', 'reason_code'];
        $metadata = [];
        foreach ($fields as $field) {
            $metadata[$field] = $location[$field] ?? null;
        }
        foreach (['latitude', 'longitude'] as $field) {
            $metadata[$field] = is_numeric($metadata[$field]) ? (float) $metadata[$field] : null;
        }

        return $metadata;
    }

    private function validMetadata(array $metadata, string $hash): bool
    {
        return ($metadata['version'] ?? null) === 1 && ($metadata['address_hash'] ?? null) === $hash
            && is_string($metadata['signature'] ?? null) && config('app.key')
            && hash_equals($this->signature($this->metadataFields($metadata)), $metadata['signature']);
    }

    private function signature(array $metadata): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('Application key is required to verify scheduling locations.');
        }

        return hash_hmac('sha256', json_encode($metadata, JSON_PRESERVE_ZERO_FRACTION), $key);
    }

    private function canConfirm(?User $actor): bool
    {
        return $actor && in_array(strtolower(trim((string) $actor->role)),
            ['admin', 'superadmin', 'super_admin', 'rep', 'salesrep', 'sales_rep'], true);
    }

    private function exactGeocode(array $location): ?array
    {
        if (array_key_exists($location['address_hash'], $this->memo)) {
            return $this->memo[$location['address_hash']];
        }
        if (DB::transactionLevel() > 0) {
            return null;
        }
        try {
            $deadline = $this->lookupDeadline ??= microtime(true) + 3;
            if (microtime(true) >= $deadline) {
                return $this->memo[$location['address_hash']] = null;
            }
            // Share one short budget across all locations and alternative checks.
            // A busy provider supplies no proof; never hold an HTTP worker waiting
            // behind another geocoder or turn an unknown location into verified.
            $response = $this->throttler->run(function () use ($location, $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('Scheduling location lookup budget exhausted.');
                }
                // cURL uses integer milliseconds; zero would disable its timeout.
                $remaining = max(0.001, $remaining);
                return Http::withHeaders([
                    'User-Agent' => config('services.nominatim.user_agent'),
                ])->connectTimeout(min(1, $remaining))->timeout(min(3, $remaining))->get('https://nominatim.openstreetmap.org/search', [
                    'street' => $location['base_address'], 'city' => $location['city'],
                    'state' => $location['state'], 'postalcode' => $location['zip'], 'countrycodes' => 'us',
                    'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 3,
                ]);
            }, $deadline);
            if ($response->successful() && is_array($response->json())) {
                foreach ($response->json() as $result) {
                    $parts = is_array($result['address'] ?? null) ? $result['address'] : [];
                    $street = $this->baseAddress(trim(($parts['house_number'] ?? '').' '.($parts['road'] ?? '')));
                    $postcode = substr((string) ($parts['postcode'] ?? ''), 0, 5);
                    $country = strtolower((string) ($parts['country_code'] ?? ''));
                    if ($street !== $location['base_address'] || $postcode !== $location['zip'] || $country !== 'us') {
                        continue;
                    }
                    $lat = $result['lat'] ?? null;
                    $lng = $result['lon'] ?? null;
                    if (is_numeric($lat) && is_numeric($lng) && is_finite((float) $lat) && is_finite((float) $lng)
                        && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
                        return $this->memo[$location['address_hash']] = ['latitude' => (float) $lat, 'longitude' => (float) $lng];
                    }
                }
            }
        } catch (Throwable) {
            // A failed geocode supplies no location proof. Only a separate staff
            // confirmation can authorize routing this complete address afterward.
        }

        return $this->memo[$location['address_hash']] = null;
    }

    private function baseAddress(string $address, string $city = ''): string
    {
        // Legacy imports sometimes append the city to the street field. Remove
        // only that exact locality suffix following a recognizable street type;
        // do not trim a legitimate street named after the city.
        $normalized = $this->normalize($address);
        if ($city !== '' && str_ends_with($normalized, ' '.$city)) {
            $street = substr($normalized, 0, -strlen(' '.$city));
            if (preg_match('/^\d+[A-Z]?(?:[-\/]\d+)?\s+.+\s+(?:STREET|ST|ROAD|RD|AVENUE|AVE|BOULEVARD|BLVD|DRIVE|DR|LANE|LN|COURT|CT|PLACE|PL|PARKWAY|PKWY|HIGHWAY|HWY|TERRACE|TER|WAY)$/', $street)) {
                $address = $street;
            }
        }
        // Strip only a recognized terminal unit marker and identifier. Preserve
        // house fractions, directional words, building names and campus details.
        $base = preg_replace('/(?:,\s*|\s+)(?:APARTMENT|APT|UNIT|SUITE|STE)\.?\s+[A-Z0-9][A-Z0-9-]*\s*$/i', '', trim($address));
        $base = $this->normalize($base ?? $address);

        return strtr($base, [' NORTH ' => ' N ', ' SOUTH ' => ' S ', ' EAST ' => ' E ', ' WEST ' => ' W ']) === '' ? ''
            : preg_replace_callback('/\b(STREET|ROAD|AVENUE|BOULEVARD|DRIVE|LANE|COURT|PLACE|PARKWAY|HIGHWAY|TERRACE)\b/',
                fn ($match) => ['STREET' => 'ST', 'ROAD' => 'RD', 'AVENUE' => 'AVE', 'BOULEVARD' => 'BLVD', 'DRIVE' => 'DR', 'LANE' => 'LN',
                    'COURT' => 'CT', 'PLACE' => 'PL', 'PARKWAY' => 'PKWY', 'HIGHWAY' => 'HWY', 'TERRACE' => 'TER'][$match[1]],
                strtr($base, [' NORTH ' => ' N ', ' SOUTH ' => ' S ', ' EAST ' => ' E ', ' WEST ' => ' W ']));
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper(str_replace(['.', ','], '', $value))) ?? '');
    }

    private function state(string $state): string
    {
        $state = $this->normalize($state);
        $names = ['ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR', 'CALIFORNIA' => 'CA', 'COLORADO' => 'CO',
            'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE', 'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI',
            'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA', 'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA',
            'MAINE' => 'ME', 'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN', 'MISSISSIPPI' => 'MS',
            'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE', 'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ',
            'NEW MEXICO' => 'NM', 'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH', 'OKLAHOMA' => 'OK',
            'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI', 'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD',
            'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA',
            'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY'];

        return $names[$state] ?? $state;
    }
}
