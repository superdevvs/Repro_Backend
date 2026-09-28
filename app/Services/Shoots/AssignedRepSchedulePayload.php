<?php

namespace App\Services\Shoots;

use App\Models\Shoot;

/** Accept the Overview form's unchanged context without granting edit rights to it. */
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
        foreach (['services' => 'id', 'service_items' => 'service_id'] as $field => $idKey) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            abort_unless(is_array($payload[$field]) && count($payload[$field]) === $items->count()
                && $byService->count() === $items->count(), 403, 'Forbidden');
            $seen = [];
            foreach ($payload[$field] as &$row) {
                abort_unless(is_array($row) && isset($row[$idKey]) && is_scalar($row[$idKey]), 403, 'Forbidden');
                $item = $byService->get($row[$idKey]);
                abort_unless($item && ! isset($seen[$item->service_id]), 403, 'Forbidden');
                $seen[$item->service_id] = true;
                abort_unless(array_diff(array_keys($row), [$idKey, 'scheduled_at', 'price', 'quantity', 'photographer_pay']) === [], 403, 'Forbidden');
                foreach (['price', 'quantity', 'photographer_pay'] as $context) {
                    if (array_key_exists($context, $row)) {
                        abort_unless($this->sameValue($row[$context], $item->{$context}), 403, 'Forbidden');
                        unset($row[$context]);
                    }
                }
            }
            unset($row);
        }

        if (array_key_exists('service_photographers', $payload)) {
            abort_unless(is_array($payload['service_photographers']), 403, 'Forbidden');
            foreach ($payload['service_photographers'] as $row) {
                abort_unless(is_array($row) && isset($row['service_id'])
                    && is_scalar($row['service_id'])
                    && array_diff(array_keys($row), ['service_id', 'photographer_id']) === [], 403, 'Forbidden');
                $item = $byService->get($row['service_id']);
                abort_unless($item && array_key_exists('photographer_id', $row)
                    && $this->sameValue($row['photographer_id'], $item->photographer_id ?? $shoot->photographer_id), 403, 'Forbidden');
            }
            unset($payload['service_photographers']);
        }

        // Start from the existing service plan; only pass explicit schedule changes
        // through the normal mutation/availability checks.
        if (isset($payload['services']) && ! isset($payload['service_items'])) {
            $payload['service_items'] = array_map(fn (array $row) => [
                'service_id' => $row['id'],
                ...array_intersect_key($row, array_flip(['scheduled_at'])),
            ], $payload['services']);
        }
        unset($payload['services']);
        if (isset($payload['service_items'])) {
            $payload['service_items'] = array_map(fn (array $row) => array_intersect_key(
                $row, array_flip(['service_id', 'scheduled_at'])
            ), $payload['service_items']);
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
