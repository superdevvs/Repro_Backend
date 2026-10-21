<?php

namespace App\Services\Scheduling;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;

/** Preview access never grants permission to mutate a booking or its neighbors. */
class TravelScheduleAccess
{
    public function authorizePayload(array $payload, ?Shoot $shoot, ?User $actor): void
    {
        abort_unless($actor, 401);
        $access = app(ShootAuthorizationSupport::class);
        $staff = $access->hasRole($actor, ['admin', 'superadmin', 'editing_manager', 'salesRep'])
            || app(\App\Services\Shoots\ShootManagementAccess::class)->isSalesRep($actor);
        if ($shoot) {
            abort_unless($access->canViewShootDetails($shoot, $actor)
                || ($staff && $access->canTriageShootRequests($shoot, $actor)), 403);
        } else {
            abort_unless($staff || $access->isClientUser($actor), 403);
            abort_if(! $staff && isset($payload['client_id']) && (int) $payload['client_id'] !== (int) $actor->id, 403);
        }
        if (! empty($payload['travel_location_confirmed'])) {
            abort_unless($this->canOverride($payload, $shoot, $actor), 403, 'Only an authorized administrator or sales rep may verify a building.');
        }
    }

    public function canOverride(array $payload, ?Shoot $shoot, ?User $actor): bool
    {
        $access = app(ShootAuthorizationSupport::class);
        if ($access->hasRole($actor, ['admin', 'superadmin'])) {
            return true;
        }
        if (! $actor || $shoot?->isImportDraft()) {
            return false;
        }
        $management = app(\App\Services\Shoots\ShootManagementAccess::class);
        if (! $management->isSalesRep($actor)) {
            return false;
        }

        // Match the write's booking permissions, including secondary sales roles
        // and explicit denies. Read access or an assignment alone is insufficient.
        if (! $shoot) return app(\App\Services\RolePermissionService::class)->userCan($actor, 'book-shoot', 'create');
        if (! $management->canEditAppointment($shoot, $actor)) return false;
        return match ($payload['action_mode'] ?? '') {
            'reschedule', 'alternate' => $access->canTriageShootRequests($shoot, $actor),
            'approve' => $access->canManageRequestedShoot($shoot, $actor),
            'schedule' => $access->canManageHoldShoot($shoot, $actor),
            default => true,
        };
    }

    public function isAdmin(?User $actor): bool
    {
        return app(ShootAuthorizationSupport::class)->hasRole($actor, ['admin', 'superadmin']);
    }
}
