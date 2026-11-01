<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, User};
use Carbon\Carbon;

/** A catalog correction after capture does not book another photographer visit. */
class PostCaptureServiceCorrection
{
    public function applies(Shoot $shoot, ?User $actor, array $data): bool
    {
        if (($data['action_mode'] ?? 'update') !== 'update'
            // services/service_lines replace the selection; service_items alone
            // patches existing rows and must retain every omitted service.
            || ! array_intersect(['services', 'service_lines'], array_keys($data))
            || ! app(ShootManagementAccess::class)->canEdit($shoot, $actor)
            || $shoot->isComplimentaryReshoot()
            || data_get($shoot->external_booking_payload, 'legacy_migration.historical_payments_only')
            || ! empty($data['schedule_adjustments']) || ! empty($data['complimentary_service_options'])) return false;

        $stages = ['scheduled', 'booked', 'uploaded', 'completed', 'editing', 'review', 'ready', 'delivered', 'ready_for_client', 'admin_verified'];
        if (! in_array(strtolower((string) $shoot->status), $stages, true)
            || ! in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)), $stages, true)
            || (! $shoot->photos_uploaded_at && ! $shoot->completed_at && ! $shoot->files()->exists())) return false;

        $windows = app(ShootDurationResolver::class)->windowsForShoot($shoot);
        if ($windows === [] || ! collect($windows)->every(fn ($window) => $window['start']
            && $window['start']->copy()->addMinutes($window['minutes'])->lte(now()))) return false;

        foreach (['scheduled_at', 'scheduled_date', 'time', 'timezone', 'photographer_id', 'status', 'workflow_status'] as $key) {
            if (array_key_exists($key, $data) && ! $this->same($key, $data[$key], $shoot->{$key}, $shoot)) return false;
        }

        $items = $shoot->serviceItems()->get();
        foreach (['services' => 'id', 'service_items' => 'service_id', 'service_lines' => 'service_id'] as $field => $idKey) {
            if (! isset($data[$field])) continue;
            if (! is_array($data[$field])) return false;
            foreach ($data[$field] as $row) {
                if (! is_array($row)) return false;
                $item = $field === 'service_lines'
                    ? $items->first(fn ($line) => isset($row['shoot_service_id']) ? (int) $line->id === (int) $row['shoot_service_id']
                        : isset($row['client_key']) && $line->client_key === $row['client_key'])
                    : $items->firstWhere('service_id', $row[$idKey] ?? null);
                if (! empty($row['shoot_service_id']) && (! $item || (int) $item->service_id !== (int) ($row[$idKey] ?? 0))) return false;
                foreach (['scheduled_at', 'photographer_id', 'duration_minutes', 'editor_id', 'video_editor_id',
                    'workflow_status', 'delivery_status', 'is_deliverable', 'force_unlock_delivery', 'unlock_reason'] as $key) {
                    if (! array_key_exists($key, $row)) continue;
                    if (! $item) {
                        if (in_array($key, ['scheduled_at', 'photographer_id', 'duration_minutes'], true)) {
                            if ($row[$key] === null || $row[$key] === '' || $key === 'duration_minutes') continue;
                        } else return false;
                    }
                    $expected = $item ? ($key === 'duration_minutes' ? app(ShootDurationResolver::class)->forServiceItem($item) : $item->{$key}) : $shoot->{$key};
                    if (! $this->same($key, $row[$key], $expected, $shoot)) return false;
                }
                if ($item && array_key_exists('shoot_unit_id', $row) && (int) $row['shoot_unit_id'] !== (int) $item->shoot_unit_id) return false;
            }
        }
        foreach ($data['service_photographers'] ?? [] as $row) {
            $item = $items->firstWhere('service_id', $row['service_id'] ?? null);
            if (! $this->same('photographer_id', $row['photographer_id'] ?? null, $item?->photographer_id ?? $shoot->photographer_id, $shoot)) return false;
        }
        if (array_key_exists('units', $data)) {
            $units = $shoot->units()->get();
            if (! is_array($data['units']) || count($data['units']) !== $units->count()) return false;
            $seen = [];
            foreach ($data['units'] as $row) {
                $unit = is_array($row) ? $units->firstWhere('client_key', $row['client_key'] ?? null) : null;
                if (! $unit || isset($seen[$unit->id])) return false;
                $seen[$unit->id] = true;
                foreach (['id', 'label', 'kind', 'sqft', 'beds', 'baths', 'access_notes', 'sort_order'] as $key) {
                    if (array_key_exists($key, $row) && ! $this->same($key, $row[$key], $unit->{$key}, $shoot)) return false;
                }
            }
        }
        return true;
    }

    private function same(string $key, mixed $incoming, mixed $stored, Shoot $shoot): bool
    {
        if (($incoming === null || $incoming === '') && ($stored === null || $stored === '')) return true;
        if ($key === 'scheduled_at') {
            if (! $incoming || ! $stored) return false;
            try { return Carbon::parse($incoming, $shoot->timezone ?: 'UTC')->equalTo(Carbon::parse($stored)); }
            catch (\Throwable) { return false; }
        }
        if ($key === 'scheduled_date') return $incoming && $stored && substr((string) $incoming, 0, 10) === Carbon::parse($stored)->toDateString();
        if ($key === 'time') {
            if (! $incoming || ! $stored) return false;
            try { return Carbon::parse($incoming)->format('H:i:s') === Carbon::parse($stored)->format('H:i:s'); }
            catch (\Throwable) { return false; }
        }
        return (is_scalar($incoming) || $incoming === null) && (is_scalar($stored) || $stored === null)
            && ((string) $incoming === (string) $stored || (is_numeric($incoming) && is_numeric($stored) && (float) $incoming === (float) $stored));
    }
}
