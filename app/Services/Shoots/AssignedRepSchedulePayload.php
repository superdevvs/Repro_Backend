<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;

/** Accept Overview form context; allow bookable service plan edits for assigned reps. */
class AssignedRepSchedulePayload
{
    public function normalize(Shoot $shoot, array $payload): array
    {
        foreach (['address', 'city', 'state', 'zip', 'client_id', 'photographer_id', 'timezone'] as $field) {
            if (array_key_exists($field, $payload)) {
                abort_unless($this->sameValue($payload[$field], $shoot->{$field}), 403, 'Forbidden');
                unset($payload[$field]);
            }
        }

        $details = $shoot->property_details ?? [];
        $metrics = [
            'bedrooms' => $details['bedrooms'] ?? $details['beds'] ?? null,
            'bathrooms' => $details['bathrooms'] ?? $details['baths'] ?? null,
            'sqft' => $details['sqft'] ?? $details['squareFeet'] ?? null,
        ];
        foreach ($metrics as $field => $value) {
            if (array_key_exists($field, $payload)) {
                abort_unless($this->sameValue($payload[$field], $value), 403, 'Forbidden');
                unset($payload[$field]);
            }
        }

        if (array_key_exists('property_details', $payload)) {
            abort_unless(is_array($payload['property_details']), 403, 'Forbidden');
            $presence = in_array($details['presenceOption'] ?? null, ['lockbox', 'other'], true)
                ? $details['presenceOption'] : 'self';
            $expected = array_merge($details, [
                'beds' => $metrics['bedrooms'], 'bedrooms' => $metrics['bedrooms'],
                'baths' => $metrics['bathrooms'], 'bathrooms' => $metrics['bathrooms'],
                'sqft' => $metrics['sqft'], 'squareFeet' => $metrics['sqft'],
                'presenceOption' => $presence,
                'lockboxCode' => $presence === 'lockbox' ? ($details['lockboxCode'] ?? null) : null,
                'lockboxLocation' => $presence === 'lockbox' ? ($details['lockboxLocation'] ?? null) : null,
                'accessContactName' => $presence === 'other' ? ($details['accessContactName'] ?? null) : null,
                'accessContactPhone' => $presence === 'other' ? ($details['accessContactPhone'] ?? null) : null,
            ]);
            foreach ($payload['property_details'] as $field => $value) {
                abort_unless(array_key_exists($field, $expected)
                    && $this->sameValue($value, $expected[$field]), 403, 'Forbidden');
            }
            unset($payload['property_details']);
        }

        $items = $shoot->serviceItems()->get();
        $byService = $items->keyBy('service_id');
        $incomingServiceIds = [];

        foreach (['services' => 'id', 'service_items' => 'service_id'] as $field => $idKey) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            abort_unless(is_array($payload[$field]), 403, 'Invalid service plan payload.');
            $seen = [];
            foreach ($payload[$field] as &$row) {
                abort_unless(is_array($row) && isset($row[$idKey]) && is_scalar($row[$idKey]), 403, 'Each service line needs a valid service id.');
                $serviceId = (int) $row[$idKey];
                abort_unless($serviceId > 0 && ! isset($seen[$serviceId]), 403, 'Duplicate or invalid service id in plan.');
                $seen[$serviceId] = true;
                $incomingServiceIds[$serviceId] = true;
                abort_unless(array_diff(array_keys($row), [$idKey, 'scheduled_at', 'price', 'quantity', 'photographer_pay']) === [], 403, 'Service lines may only include schedule and pricing context fields.');

                $item = $byService->get($serviceId);
                if ($item) {
                    // Existing lines: price/qty/pay are server-owned. Overview may
                    // re-echo catalog prices that drifted from the booked line
                    // (e.g. HDR 275 booked vs 175 catalog). Strip those echoes
                    // instead of sameValue-aborting — otherwise adding a service
                    // like Zillow 3D falsely 403s even though pricing is unchanged.
                    unset($row['price'], $row['quantity'], $row['photographer_pay']);
                    continue;
                }

                // New lines: bookable catalog only. Pricing stays server-owned.
                $catalog = Service::query()->whereKey($serviceId)->first();
                abort_unless(
                    $catalog && ! $catalog->is_migration_only,
                    403,
                    'Only bookable catalog services can be added to this shoot.'
                );
                unset($row['price'], $row['photographer_pay']);
            }
            unset($row);
        }

        if (array_key_exists('service_photographers', $payload)) {
            abort_unless(is_array($payload['service_photographers']), 403, 'Forbidden');
            foreach ($payload['service_photographers'] as $row) {
                abort_unless(is_array($row) && isset($row['service_id'])
                    && is_scalar($row['service_id'])
                    && array_diff(array_keys($row), ['service_id', 'photographer_id']) === [], 403, 'Forbidden');
                $serviceId = (int) $row['service_id'];
                $item = $byService->get($serviceId);
                abort_unless(array_key_exists('photographer_id', $row), 403, 'Forbidden');
                if ($item) {
                    abort_unless($this->sameValue(
                        $row['photographer_id'],
                        $item->photographer_id ?? $shoot->photographer_id
                    ), 403, 'Forbidden');
                } else {
                    abort_unless(isset($incomingServiceIds[$serviceId])
                        && $this->sameValue($row['photographer_id'], $shoot->photographer_id), 403, 'Forbidden');
                }
            }
            unset($payload['service_photographers']);
        }

        // Keep services as the mutation source of truth so new / removed lines apply.
        // Also accept service_items-shaped Overview payloads.
        if (isset($payload['services']) && ! isset($payload['service_items'])) {
            $payload['services'] = array_map(fn (array $row) => array_intersect_key(
                $row,
                array_flip(['id', 'scheduled_at', 'quantity'])
            ), $payload['services']);
        } elseif (isset($payload['services'])) {
            $payload['services'] = array_map(fn (array $row) => array_intersect_key(
                $row,
                array_flip(['id', 'scheduled_at', 'quantity'])
            ), $payload['services']);
            $payload['service_items'] = array_map(fn (array $row) => array_intersect_key(
                $row,
                array_flip(['service_id', 'scheduled_at', 'quantity'])
            ), $payload['service_items']);
        } elseif (isset($payload['service_items'])) {
            // Promote to services so adds are not dropped by targetServicesFor's
            // existing-plan fallback when only service_items arrive.
            $payload['services'] = array_map(fn (array $row) => array_filter([
                'id' => $row['service_id'],
                'scheduled_at' => $row['scheduled_at'] ?? null,
                'quantity' => $row['quantity'] ?? null,
            ], fn ($value) => $value !== null), $payload['service_items']);
            unset($payload['service_items']);
        }

        return $payload;
    }

    private function sameValue(mixed $incoming, mixed $stored): bool
    {
        if ($incoming === $stored) {
            return true;
        }
        if ((is_scalar($incoming) || $incoming === null) && (is_scalar($stored) || $stored === null)) {
            return trim((string) $incoming) === trim((string) $stored)
                || (is_numeric($incoming) && is_numeric($stored) && (float) $incoming === (float) $stored);
        }

        return is_array($incoming) && is_array($stored) && $incoming == $stored;
    }
}
