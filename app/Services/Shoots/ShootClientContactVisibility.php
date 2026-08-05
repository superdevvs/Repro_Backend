<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Server-side gate for the client's phone number on a shoot payload.
 *
 * Photographers need to reach the client around the appointment only, so the
 * number is unlocked for a bounded window instead of being shipped with every
 * shoot payload: two hours before the scheduled start, through the one hour
 * on-site buffer, plus two hours after that buffer ends.
 *
 * Shoots store no duration, so {@see self::SHOOT_BUFFER_MINUTES} stands in for
 * the appointment itself. The window is evaluated against the absolute
 * `scheduled_at` instant (UTC), which makes it timezone-agnostic; the local
 * `scheduled_date` + `time` pair in the shoot's timezone is only a fallback for
 * legacy rows without `scheduled_at`.
 *
 * {@see frontend/src/utils/clientContactVisibility.ts} mirrors these bounds for
 * display; this class is the authority that decides whether the number leaves
 * the API at all.
 */
class ShootClientContactVisibility
{
    /** Minutes before the scheduled start that the client phone unlocks. */
    public const LEAD_MINUTES = 120;

    /** On-site buffer treated as the appointment itself (no duration is stored). */
    public const SHOOT_BUFFER_MINUTES = 60;

    /** Minutes after the on-site buffer ends that the client phone stays unlocked. */
    public const TRAIL_MINUTES = 120;

    /** Roles that always see the client's phone number. */
    private const PRIVILEGED_ROLES = [
        'admin',
        'superadmin',
        'super_admin',
        'editing_manager',
        'salesrep',
        'sales_rep',
        'finance',
        'accounting',
    ];

    public function __construct(private readonly ShootAuthorizationSupport $authorization)
    {
    }

    /**
     * The client's phone number when the viewer is allowed to see it, else null.
     */
    public function phoneFor(Shoot $shoot, ?User $user): ?string
    {
        return $this->canViewClientPhone($shoot, $user) ? $this->clientPhone($shoot) : null;
    }

    public function canViewClientPhone(Shoot $shoot, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = $this->normalizeRole($user->role ?? '');

        if (in_array($role, self::PRIVILEGED_ROLES, true)) {
            return true;
        }

        // Clients only ever see their own number (their own account detail).
        if ($role === 'client') {
            return (string) $shoot->client_id === (string) $user->id;
        }

        if ($role !== 'photographer') {
            return false;
        }

        return $this->authorization->isPhotographerAssignedToShoot($shoot, $user)
            && $this->isWithinPhotographerWindow($shoot);
    }

    /**
     * Whether "now" sits inside [start - 2h, start + 1h buffer + 2h].
     */
    public function isWithinPhotographerWindow(Shoot $shoot, ?CarbonInterface $now = null): bool
    {
        $start = $this->resolveStart($shoot);
        if (!$start) {
            return false;
        }

        $now = $now ?? Carbon::now();

        return $now->greaterThanOrEqualTo($start->copy()->subMinutes(self::LEAD_MINUTES))
            && $now->lessThanOrEqualTo(
                $start->copy()->addMinutes(self::SHOOT_BUFFER_MINUTES + self::TRAIL_MINUTES)
            );
    }

    private function resolveStart(Shoot $shoot): ?CarbonInterface
    {
        if ($shoot->scheduled_at) {
            return Carbon::parse($shoot->scheduled_at);
        }

        $date = $shoot->scheduled_date?->toDateString();
        if (!$date) {
            return null;
        }

        try {
            return Carbon::parse(
                trim($date . ' ' . ($shoot->time ?: '00:00')),
                $shoot->timezone ?: config('app.timezone')
            )->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clientPhone(Shoot $shoot): ?string
    {
        $client = $shoot->client;
        $phone = trim((string) ($client?->phone ?: $client?->phonenumber ?: ''));

        return $phone !== '' ? $phone : null;
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($role)));
    }
}
