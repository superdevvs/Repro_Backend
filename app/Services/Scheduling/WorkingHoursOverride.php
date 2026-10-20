<?php

namespace App\Services\Scheduling;

use App\Exceptions\PublicApiResponseException;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** A deliberate, signed superadmin exception for one new booking. */
class WorkingHoursOverride
{
    public function eligible(array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        return $actor && strtolower((string) $actor->role) === 'superadmin'
            && ! $shoot && ($payload['action_mode'] ?? '') === 'create'
            && (int) ($payload['client_id'] ?? 0) !== (int) $actor->id
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
        return hash_hmac('sha256', 'new-booking-working-hours-v1:'.app(ScheduleFeasibilityService::class)->confirmationVersion($result, $actor),
            (string) config('app.key'));
    }

    public function assertConfirmed(array $result, array $payload, ?Shoot $shoot, ?User $actor): void
    {
        abort_unless($this->eligible($payload, $shoot, $actor), 403, 'Only a superadmin may override working hours for a new staff booking.');
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
