<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;

/** Accept Overview form context; allow bookable service plan edits for assigned reps. */
class AssignedRepSchedulePayload
{
    /** FE Overview / modal-save echo keys that must not block schedule or photographer edits. */
    private const SERVICE_ECHO_KEYS = [
        'photographer_id',
        'editor_id',
        'is_deliverable',
        'workflow_status',
        'delivery_status',
        'name',
        'service_name',
        'force_unlock_delivery',
        'unlock_reason',
        'label',
        'type',
        'icon',
        'category',
    ];

    /**
     * Top-level Overview Save echoes that survive address/property normalize and then
     * 403 UpdateShootAction's assigned-rep allow-list. Strip unconditionally — reps
     * cannot edit these via PATCH Overview (sameValue optional; safest = unset).
     * Keep photographer_id / service_photographers / schedule / services / notify_*.
     */
    private const TOP_LEVEL_ECHO_KEYS = [
        'status',
        'workflow_status',
        'listing_type',
        'property_status',
        'notes',
        'shoot_notes',
        'company_notes',
        'photographer_notes',
        'editor_notes',
        'base_quote',
        'total_quote',
        'tax_amount',
        'tax_percent',
        'tax_region',
        'payment_status',
        'payment_type',
        'editor_id',
        'video_editor_id',
        'id',
        'service_id',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'presenceOption',
        'lockboxCode',
        'lockboxLocation',
        'access_notes',
        'accessContactName',
        'accessContactPhone',
        'unit_count',
        'delivery_status',
        'is_flagged',
        'bypass_paywall',
        'discount_type',
        'discount_value',
        'discount_amount',
        'package_name',
        'hero_image',
        'latitude',
        'longitude',
        'mls_id',
        'property_slug',
    ];

    public function normalize(Shoot $shoot, array $payload): array
    {
        // photographer_id is intentionally editable for assigned reps (product decision).
        foreach (self::TOP_LEVEL_ECHO_KEYS as $echoKey) {
            unset($payload[$echoKey]);
        }

        // Overview may re-echo full tour_links; assigned reps may only keep realtor_client_id.
        if (array_key_exists('tour_links', $payload)) {
            if (! is_array($payload['tour_links'])) {
                unset($payload['tour_links']);
            } else {
                $payload['tour_links'] = array_intersect_key(
                    $payload['tour_links'],
                    array_flip(['realtor_client_id'])
                );
                if ($payload['tour_links'] === []) {
                    unset($payload['tour_links']);
                }
            }
        }
        foreach (['address', 'city', 'state', 'zip', 'client_id', 'timezone'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            // Overview occasionally re-echoes null/'' for timezone while the shoot has a
            // stored zone — treat empty as unchanged context, not a clear attempt.
            if ($field === 'timezone'
                && ($payload[$field] === null || $payload[$field] === '')
                && filled($shoot->timezone)) {
                unset($payload[$field]);
                continue;
            }
            abort_unless($this->sameValue($payload[$field], $shoot->{$field}), 403, 'Forbidden');
            unset($payload[$field]);
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
                // Overview re-echoes listing metadata (completeAddress, livingArea, …)
                // that is not part of the sales-rep editable contract. Strip unknown
                // keys instead of 403ing an otherwise valid photographer/schedule save.
                if (! array_key_exists($field, $expected)) {
                    continue;
                }
                $incoming = $value;
                if ($field === 'presenceOption') {
                    // FE often sends null before the picker defaults; treat as 'self'.
                    $incoming = in_array($value, ['lockbox', 'other'], true) ? $value : 'self';
                }
                abort_unless($this->sameValue($incoming, $expected[$field]), 403, 'Forbidden');
            }
            unset($payload['property_details']);
        }

        $items = $shoot->serviceItems()->get();
        $byService = $items->keyBy('service_id');
        $incomingServiceIds = [];
        $liftedPhotographers = [];

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

                // Overview / modal-save often puts photographer_id on the services row.
                // Lift it into service_photographers so reassignment still applies, then
                // strip other known FE echo keys before the schedule/pricing allow-list.
                if (array_key_exists('photographer_id', $row)) {
                    $liftedPhotographers[$serviceId] = [
                        'service_id' => $serviceId,
                        'photographer_id' => $row['photographer_id'],
                    ];
                }
                foreach (self::SERVICE_ECHO_KEYS as $echoKey) {
                    unset($row[$echoKey]);
                }

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

        if ($liftedPhotographers !== []) {
            $existingByService = [];
            foreach ($payload['service_photographers'] ?? [] as $row) {
                if (is_array($row) && isset($row['service_id']) && is_scalar($row['service_id'])) {
                    $existingByService[(int) $row['service_id']] = true;
                }
            }
            if (! isset($payload['service_photographers']) || ! is_array($payload['service_photographers'])) {
                $payload['service_photographers'] = [];
            }
            foreach ($liftedPhotographers as $serviceId => $row) {
                // Explicit service_photographers wins when both shapes are present.
                if (! isset($existingByService[$serviceId])) {
                    $payload['service_photographers'][] = $row;
                }
            }
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
                // Assigned reps may reassign line photographers; service must already
                // be on the shoot or part of the incoming bookable plan.
                abort_unless($item || isset($incomingServiceIds[$serviceId]), 403, 'Forbidden');
            }
            // Keep service_photographers so UpdateShootAction can apply the reassignment.
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
