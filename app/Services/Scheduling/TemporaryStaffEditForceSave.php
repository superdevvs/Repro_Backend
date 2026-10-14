<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootManagementAccess;
use Carbon\Carbon;

/** Temporary, explicit staff exception for an existing visit's service edits. */
class TemporaryStaffEditForceSave
{
    public function enabled(): bool
    {
        return (bool) config('availability.temporary_staff_shoot_force_save', false)
            && app(SchedulingBufferSettings::class)->enabled();
    }

    public function eligible(array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        if (! $this->enabled() || ! $shoot?->exists || ! $actor
            || ($payload['action_mode'] ?? '') !== 'update'
            || ! app(ShootAuthorizationSupport::class)->canEditShootAppointment($shoot, $actor)
            || ! in_array(preg_replace('/[_\s-]/', '', strtolower((string) $actor->role)), ['admin', 'superadmin', 'salesrep', 'rep', 'representative'], true)
            || ! in_array((string) ($shoot->workflow_status ?: $shoot->status), ['scheduled', 'booked', 'uploaded', 'completed', 'editing', 'review', 'ready', 'delivered'], true)
            || $shoot->units()->exists()
            || array_key_exists('units', $payload) || array_key_exists('service_lines', $payload)
            || ! empty($payload['schedule_adjustments']) || ! empty($payload['complimentary_service_options'])) {
            return false;
        }
        $payload = app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalizeTimestamps($shoot, $payload);
        foreach (['address', 'city', 'state', 'zip', 'timezone', 'status', 'workflow_status'] as $field) {
            if (array_key_exists($field, $payload) && trim((string) $payload[$field]) !== trim((string) $shoot->{$field})) return false;
        }
        if (array_key_exists('photographer_id', $payload) && (int) $payload['photographer_id'] !== (int) $shoot->photographer_id) return false;
        if (array_key_exists('scheduled_at', $payload)
            && (! $payload['scheduled_at'] || ! $shoot->scheduled_at || ! Carbon::parse($payload['scheduled_at'])->equalTo($shoot->scheduled_at))) return false;
        if (array_key_exists('scheduled_date', $payload) && (string) $payload['scheduled_date'] !== $shoot->scheduled_date?->toDateString()) return false;
        if (array_key_exists('time', $payload) && Carbon::parse($payload['time'] ?: '00:00')->format('H:i:s') !== Carbon::parse($shoot->time ?: '00:00')->format('H:i:s')) return false;

        $before = app(ShootDurationResolver::class)->windowsForShoot($shoot);
        $after = app(VisitPlanBuilder::class)->build($payload, $shoot, $actor);
        $keys = function (array $rows): array {
            $keys = array_map(fn ($row) => (int) $row['photographer_id'].':'.Carbon::parse($row['scheduled_at'] ?? $row['start'])->getTimestamp(),
                array_filter($rows, fn ($row) => ! empty($row['photographer_id']) && ! empty($row['scheduled_at'] ?? $row['start'])));
            sort($keys, SORT_STRING);
            return $keys;
        };

        return $before !== [] && $keys($before) === $keys($after);
    }

    public function canSave(array $result, array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        return $this->eligible($payload, $shoot, $actor)
            && in_array($result['status'] ?? 'available', ['available', 'review_required', 'conflict'], true)
            && array_diff($result['reason_codes'] ?? [], ['outside_working_hours', 'travel_review_required', 'insufficient_travel_time']) === []
            && (! ($result['hard_conflict'] ?? false) || in_array('outside_working_hours', $result['reason_codes'] ?? [], true));
    }

    public function confirmationVersion(array $result, array $payload, Shoot $shoot, User $actor): string
    {
        $shoot = $shoot->fresh() ?? $shoot;
        $payload = app(\App\Services\Schedule\ShootScheduleUpdateInput::class)->normalizeTimestamps($shoot, $payload);
        $services = app(ShootEditablePayloadService::class)->targetServicesFor($shoot, $payload, $actor);
        $target = array_map(fn ($row) => [
            'service_id' => (int) ($row['id'] ?? $row['service_id']),
            'quantity' => (int) ($row['quantity'] ?? 1), 'price' => (float) ($row['price'] ?? 0),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 0),
            'photographer_id' => (int) ($row['photographer_id'] ?? $shoot->photographer_id ?? 0),
            'scheduled_at' => empty($row['scheduled_at']) ? $shoot->scheduled_at?->copy()->utc()->toIso8601String()
                : Carbon::parse($row['scheduled_at'], 'UTC')->utc()->toIso8601String(),
            'editor_id' => isset($row['editor_id']) ? (int) $row['editor_id'] : null,
        ], $services);
        usort($target, fn ($left, $right) => $left['service_id'] <=> $right['service_id']);

        return hash_hmac('sha256', json_encode([
            'purpose' => 'temporary-staff-edit-force-save-v1', 'actor_id' => (int) $actor->id,
            'shoot_id' => (int) $shoot->id,
            'edit_version' => app(ShootManagementAccess::class)->editVersion($shoot),
            'target' => $target,
            'warning' => app(ScheduleFeasibilityService::class)->confirmationVersion($result, $actor),
            'enabled' => $this->enabled(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
