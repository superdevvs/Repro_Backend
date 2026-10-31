<?php

namespace App\Services\Studio;

use App\Models\Template;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/** One boundary for list, search, deep links and legacy project submissions. */
final class StudioTemplateAccess
{
    private const STAFF = ['admin', 'superadmin', 'editing_manager', 'editor'];
    private const PRIVILEGED = ['admin', 'superadmin', 'editing_manager'];

    public static function scope(Builder $query, Authenticatable $user): Builder
    {
        if (!self::allowed($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('team_id', self::teamId($user))
            ->where(function (Builder $visible) use ($user): void {
                $visible->where('created_by', $user->getAuthIdentifier());
                if (self::staff($user)) {
                    $visible->orWhere('visibility', 'team');
                }
                if (in_array($user->role, self::PRIVILEGED, true)) {
                    $visible->orWhereNull('visibility');
                }
            });
    }

    public static function canView(Authenticatable $user, Template $template): bool
    {
        if (!self::allowed($user) || (int) $template->team_id !== self::teamId($user)) {
            return false;
        }
        if (self::owns($user, $template)) {
            return true;
        }

        return ($template->visibility === 'team' && self::staff($user))
            || ($template->visibility === null && in_array($user->role, self::PRIVILEGED, true));
    }

    public static function canEdit(Authenticatable $user, Template $template): bool
    {
        return self::canView($user, $template)
            && (self::owns($user, $template)
                || ($template->visibility === null && in_array($user->role, self::PRIVILEGED, true)));
    }

    public static function canShare(Authenticatable $user, Template $template): bool
    {
        return self::canView($user, $template) && self::owns($user, $template) && self::staff($user);
    }

    public static function owns(Authenticatable $user, Template $template): bool
    {
        return (int) $template->created_by === (int) $user->getAuthIdentifier();
    }

    public static function staff(Authenticatable $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    public static function teamId(Authenticatable $user): int
    {
        return (int) ($user->team_id ?? data_get($user->metadata, 'team_id') ?? $user->getAuthIdentifier());
    }

    private static function allowed(Authenticatable $user): bool
    {
        return self::staff($user) || ($user->role === 'client' && !StudioClientAccess::isPaused($user));
    }
}
