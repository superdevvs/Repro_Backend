<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** New work may change; the original appointment and delivered service execution may not. */
class ActiveServiceEditGuard
{
    public function normalize(Shoot $shoot, array $data): array
    {
        if (! app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)) return $data;
        $data = app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalizeTimestamps($shoot, $data);
        foreach (['scheduled_at', 'scheduled_date', 'time', 'timezone', 'photographer_id'] as $field) {
            if (! array_key_exists($field, $data)) continue;
            if (! $this->same($field, $data[$field], $shoot->{$field})) {
                throw ValidationException::withMessages([$field => 'Keep the original shoot appointment. Update the unfinished service or book a linked Additional Work visit.']);
            }
            unset($data[$field]);
        }
        $items = $shoot->serviceItems()->get();
        foreach (['services', 'service_items', 'service_lines', 'service_photographers'] as $field) {
            foreach ($data[$field] ?? [] as $index => $row) {
                $item = $this->item($items, $row);
                if (! $item) continue;
                if ($item->delivery_status !== 'delivered') {
                    if (($row['delivery_status'] ?? null) === 'delivered' || ($row['workflow_status'] ?? null) === 'delivered') {
                        throw ValidationException::withMessages(["$field.$index.delivery_status" => 'Finalize this service after its new output has been reviewed.']);
                    }
                    continue;
                }
                foreach (['scheduled_at', 'photographer_id', 'duration_minutes', 'editor_id', 'video_editor_id', 'workflow_status', 'delivery_status'] as $key) {
                    if (! array_key_exists($key, $row)) continue;
                    $stored = $key === 'duration_minutes' ? app(ShootDurationResolver::class)->forServiceItem($item) : $item->{$key};
                    $echo = $stored;
                    if ($stored === null && in_array($key, ['scheduled_at', 'photographer_id'], true)) $echo = $shoot->{$key};
                    if (! $this->same($key, $row[$key], $stored) && ! $this->same($key, $row[$key], $echo)) {
                        throw ValidationException::withMessages(["$field.$index.$key" => 'This service is already delivered. Manage the unfinished service instead.']);
                    }
                    $data[$field][$index][$key] = $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : $stored;
                }
            }
        }
        return $data;
    }

    public function changesCapture(Shoot $shoot, array $data): bool
    {
        foreach (['address', 'city', 'state', 'zip', 'timezone', 'photographer_id', 'scheduled_at', 'scheduled_date', 'time', 'property_details', 'schedule_adjustments'] as $field) {
            if (array_key_exists($field, $data) && ! $this->same($field, $data[$field], $shoot->{$field})) return true;
        }
        $items = $shoot->serviceItems()->get();
        foreach (['services', 'service_items', 'service_lines', 'service_photographers'] as $field) {
            foreach ($data[$field] ?? [] as $row) {
                $item = $this->item($items, $row);
                foreach (['scheduled_at', 'photographer_id', 'duration_minutes'] as $key) {
                    if (! array_key_exists($key, $row)) continue;
                    if (! $item) {
                        if ($key !== 'duration_minutes' && filled($row[$key])) return true;
                    } else {
                        $stored = $key === 'duration_minutes' ? app(ShootDurationResolver::class)->forServiceItem($item) : $item->{$key};
                        if (! $this->same($key, $row[$key], $stored)) return true;
                    }
                }
            }
        }
        return false;
    }

    private function item($items, array $row): ?ShootService
    {
        if (isset($row['shoot_service_id'])) return $items->firstWhere('id', (int) $row['shoot_service_id']);
        if (isset($row['client_key'])) return $items->firstWhere('client_key', $row['client_key']);
        return $items->firstWhere('service_id', (int) ($row['service_id'] ?? $row['id'] ?? 0));
    }

    private function same(string $key, mixed $incoming, mixed $stored): bool
    {
        if (($incoming === null || $incoming === '') && ($stored === null || $stored === '')) return true;
        if ($key === 'scheduled_at' && $incoming && $stored) {
            try { return Carbon::parse($incoming)->equalTo(Carbon::parse($stored)); } catch (\Throwable) { return false; }
        }
        if ($key === 'scheduled_date' && $incoming && $stored) return substr((string) $incoming, 0, 10) === Carbon::parse($stored)->toDateString();
        if ($key === 'time' && $incoming && $stored) return substr((string) $incoming, 0, 5) === substr((string) $stored, 0, 5);
        if (is_array($incoming) || is_array($stored)) return $incoming === $stored;
        return (string) $incoming === (string) $stored;
    }
}
