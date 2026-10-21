<?php

namespace App\Services\Scheduling;

use App\Exceptions\PublicApiResponseException;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** A deliberate, signed staff exception for one booking or reschedule. */
class WorkingHoursOverride
{
    public function eligible(array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        $action = $payload['action_mode'] ?? '';
        $management = app(\App\Services\Shoots\ShootManagementAccess::class);
        $authorized = $actor && (strtolower((string) $actor->role) === 'superadmin'
            || ($management->isSalesRep($actor) && ($shoot ? $management->can($actor)
                : app(\App\Services\RolePermissionService::class)->userCan($actor, 'book-shoot', 'create'))));
        $allowed = $shoot
            ? $shoot->exists && in_array($action, ['update', 'reschedule'], true)
                && in_array((string) ($shoot->workflow_status ?: $shoot->status), ['scheduled', 'booked'], true)
                && $management->canEditAppointment($shoot, $actor)
                && ($action !== 'reschedule' || app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canReviewShootRequests($actor))
                && empty($payload['complimentary_service_options'])
                && (!isset($payload['status']) || $payload['status'] === $shoot->status)
                && (!isset($payload['workflow_status']) || $payload['workflow_status'] === $shoot->workflow_status)
            : $action === 'create';
        return $authorized && $allowed
            && (int) ($payload['client_id'] ?? $shoot?->client_id ?? 0) !== (int) $actor->id
            && ! filter_var($payload['is_client_request'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && empty($payload['schedule_adjustments']);
    }

    public function canSave(array $result, array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        return $this->eligible($payload, $shoot, $actor) && ! empty($result['enabled'])
            && in_array('outside_working_hours', $result['reason_codes'] ?? [], true)
            && array_diff($result['reason_codes'] ?? [], ['outside_working_hours', 'travel_review_required', 'insufficient_travel_time']) === []
            && ! empty($result['visits']);
    }

    public function confirmationVersion(array $result, ?User $actor): string
    {
        $shoot = $result['_plans'][0]['shoot'] ?? null;
        $target = $shoot ? $shoot->id.':'.app(\App\Services\Shoots\ShootManagementAccess::class)->editVersion($shoot) : 'new';
        return hash_hmac('sha256', 'booking-working-hours-v2:'.$target.':'.app(ScheduleFeasibilityService::class)->confirmationVersion($result, $actor),
            (string) config('app.key'));
    }

    public function assertConfirmed(array $result, array $payload, ?Shoot $shoot, ?User $actor): void
    {
        abort_unless($this->eligible($payload, $shoot, $actor), 403, 'An authorized superadmin or sales rep must confirm this working-hours exception.');
        if (! $this->canSave($result, $payload, $shoot, $actor)) {
            throw ValidationException::withMessages(['working_hours_override' => 'This schedule cannot use a working-hours override. Refresh availability; overlapping bookings must be resolved.']);
        }
        if (! filter_var($payload['working_hours_override_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['working_hours_override_confirmed' => 'Review the scheduling warnings and explicitly confirm booking outside working hours.']);
        }
        $length = mb_strlen(trim((string) ($payload['working_hours_override_reason'] ?? '')));
        if ($length < 5 || $length > 500) {
            throw ValidationException::withMessages(['working_hours_override_reason' => 'Explain the working-hours exception (5 to 500 characters).']);
        }
        $expected = $this->confirmationVersion($result, $actor);
        if (! is_string($payload['working_hours_override_confirmation_version'] ?? null)
            || ! hash_equals($expected, $payload['working_hours_override_confirmation_version'])) {
            $result['can_override_working_hours'] = true;
            $result['working_hours_confirmation_version'] = $expected;
            $message = 'The schedule or warning changed. Review the current warnings and confirm the working-hours exception again.';
            throw new PublicApiResponseException(response()->json(['message' => $message,
                'errors' => ['working_hours_override_confirmation_version' => [$message]],
                'feasibility' => app(ScheduleFeasibilityService::class)->publicResult($result)], 422));
        }
    }
}
