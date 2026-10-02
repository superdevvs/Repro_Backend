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
        $staff = $access->hasRole($actor, ['admin', 'superadmin', 'editing_manager', 'salesRep']);
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
        $primaryRep = $access->hasRole($actor, ['salesRep']);
        $requestReview = $shoot && in_array($payload['action_mode'] ?? '', ['reschedule', 'alternate'], true)
            && $access->canTriageShootRequests($shoot, $actor);
        if ($requestReview && ! $primaryRep) {
            // Secondary sales roles already have shared request-review rights;
            // they do not gain generic booking mutation rights from this check.
            $secondaryActor = clone $actor;
            foreach ($actor->secondary_roles ?? [] as $role) {
                $secondaryActor->role = $role;
                if ($access->hasRole($secondaryActor, ['salesRep'])) {
                    return true;
                }
            }
        }
        if (! $primaryRep) {
            return false;
        }

        // Broad read access does not grant a rep permission to change another
        // rep's active booking. Shared request-review paths retain their authority;
        // mutation adapters set action_mode themselves from the authorized action.
        return ! $shoot || (int) $shoot->rep_id === (int) $actor->id
            || $access->canManageRequestedShoot($shoot, $actor)
            || $access->canManageHoldShoot($shoot, $actor)
            || $requestReview;
    }

    public function isAdmin(?User $actor): bool
    {
        return app(ShootAuthorizationSupport::class)->hasRole($actor, ['admin', 'superadmin']);
    }
}
