<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Services\Schedule\ShootScheduleUpdateInput;
use Carbon\Carbon;

/** Accept full-form context without reopening completed production work. */
class DeliveredSalesEditPayload
{
    private const LOCKED_MESSAGE = 'This shoot has been delivered. You can correct its details, services and pricing; scheduling, assignments and unit identities must remain unchanged.';

    public function normalize(Shoot $shoot, array $payload): array
    {
        // Full Overview saves echo these fields. Their mere presence requests a
        // pricing rebuild, which would erase a previously agreed order total.
        foreach (['client_id', 'state'] as $field) {
            if (array_key_exists($field, $payload) && $this->same($field, $payload[$field], $shoot->{$field})) {
                unset($payload[$field]);
            }
        }
        $payload = app(ShootScheduleUpdateInput::class)->normalizeTimestamps($shoot, $payload);
        foreach (['scheduled_at', 'scheduled_date', 'time', 'timezone', 'alternate_scheduled_at', 'alternate_scheduled_date', 'alternate_time', 'photographer_id', 'rep_id', 'service_id', 'service_category', 'shoot_type', 'product_status'] as $field) {
            if (! array_key_exists($field, $payload)) continue;
            abort_unless($this->same($field, $payload[$field], $shoot->{$field}), 403, self::LOCKED_MESSAGE);
            unset($payload[$field]);
        }

        $items = $shoot->serviceItems()->get();
        foreach (['services' => 'id', 'service_items' => 'service_id', 'service_lines' => 'service_id'] as $field => $idKey) {
            if (! array_key_exists($field, $payload)) continue;
            abort_unless(is_array($payload[$field]), 403, self::LOCKED_MESSAGE);
            $seen = [];
            foreach ($payload[$field] as $row) {
                abort_unless(is_array($row), 403, self::LOCKED_MESSAGE);
                $item = $field === 'service_lines'
                    ? $items->first(fn ($item) => isset($row['shoot_service_id']) ? (string) $row['shoot_service_id'] === (string) $item->id : isset($row['client_key']) && $row['client_key'] === $item->client_key)
                    : $items->firstWhere('service_id', $row[$idKey] ?? null);
                if ($item) {
                    abort_unless(! isset($seen[$item->id]) && (string) ($row[$idKey] ?? '') === (string) $item->service_id, 403, self::LOCKED_MESSAGE);
                    $seen[$item->id] = true;
                } else {
                    abort_if(! empty($row['shoot_service_id']), 403, self::LOCKED_MESSAGE);
                }
                foreach (['duration_minutes', 'scheduled_at', 'photographer_id'] as $key) {
                    if (! array_key_exists($key, $row)) continue;
                    $expected = $item ? ($key === 'duration_minutes' ? app(ShootDurationResolver::class)->forServiceItem($item) : $item->{$key}) : null;
                    abort_unless($this->same($key, $row[$key], $expected), 403, self::LOCKED_MESSAGE);
                }
                foreach (['shoot_unit_id', 'client_key'] as $key) {
                    if ($item && array_key_exists($key, $row)) abort_unless($this->same($key, $row[$key], $item->{$key}), 403, self::LOCKED_MESSAGE);
                }
                if ($item && array_key_exists('unit_client_key', $row)) {
                    abort_unless($this->same('unit_client_key', $row['unit_client_key'], $item->unit?->client_key), 403, self::LOCKED_MESSAGE);
                }
            }
            // Unchanged full-form echoes remain a detail-only correction. Only
            // deliberate identity/quantity changes enter service persistence.
            $unchanged = count($payload[$field]) === $items->count() && count($seen) === $items->count();
            foreach ($payload[$field] as $row) {
                $item = $field === 'service_lines'
                    ? $items->first(fn ($item) => isset($row['shoot_service_id']) ? (string) $row['shoot_service_id'] === (string) $item->id : isset($row['client_key']) && $row['client_key'] === $item->client_key)
                    : $items->firstWhere('service_id', $row[$idKey] ?? null);
                $unchanged = $unchanged && $item && (! array_key_exists('quantity', $row) || $this->same('quantity', $row['quantity'], $item->quantity));
            }
            if ($unchanged) unset($payload[$field]);
        }

        if (array_key_exists('service_photographers', $payload)) {
            abort_unless(is_array($payload['service_photographers']), 403, self::LOCKED_MESSAGE);
            foreach ($payload['service_photographers'] as $row) {
                $matches = is_array($row) ? $items->where('service_id', $row['service_id'] ?? null) : collect();
                abort_unless($matches->isNotEmpty() && array_key_exists('photographer_id', $row)
                    && $matches->every(fn ($item) => $this->same('photographer_id', $row['photographer_id'], $item->photographer_id)), 403, self::LOCKED_MESSAGE);
            }
            unset($payload['service_photographers']);
        }

        if (array_key_exists('units', $payload)) {
            $units = $shoot->units()->get();
            abort_unless(is_array($payload['units']) && count($payload['units']) === $units->count(), 403, self::LOCKED_MESSAGE);
            $seen = [];
            foreach ($payload['units'] as $row) {
                $unit = is_array($row) ? $units->firstWhere('client_key', $row['client_key'] ?? null) : null;
                abort_unless($unit && ! isset($seen[$unit->id]), 403, self::LOCKED_MESSAGE);
                $seen[$unit->id] = true;
                foreach (['id', 'label', 'kind', 'sqft', 'beds', 'baths', 'access_notes', 'sort_order'] as $key) {
                    if (array_key_exists($key, $row)) abort_unless($this->same($key, $row[$key], $unit->{$key}), 403, self::LOCKED_MESSAGE);
                }
            }
            unset($payload['units']);
        }

        abort_if(! empty($payload['schedule_adjustments']) || ! empty($payload['complimentary_service_options']), 403, self::LOCKED_MESSAGE);
        unset($payload['schedule_adjustments'], $payload['complimentary_service_options']);
        if (! array_key_exists('service_lines', $payload)) unset($payload['expected_units_revision']);
        return $payload;
    }

    private function same(string $field, mixed $incoming, mixed $stored): bool
    {
        if (($incoming === null || $incoming === '') && ($stored === null || $stored === '')) return true;
        if (str_ends_with($field, '_at') || str_ends_with($field, '_date') || in_array($field, ['time', 'alternate_time'], true)) {
            if (! $incoming || ! $stored) return false;
            try {
                $format = str_ends_with($field, '_date') ? 'Y-m-d' : (str_ends_with($field, 'time') ? 'H:i:s' : 'Y-m-d H:i:s');
                return Carbon::parse($incoming)->utc()->format($format) === Carbon::parse($stored)->utc()->format($format);
            } catch (\Throwable) {
                return false;
            }
        }
        return (is_scalar($incoming) || $incoming === null) && (is_scalar($stored) || $stored === null)
            && ((string) $incoming === (string) $stored || (is_numeric($incoming) && is_numeric($stored) && (float) $incoming === (float) $stored));
    }
}
