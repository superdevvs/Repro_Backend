<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Application personal access token (Req 17.5).
 *
 * Sanctum resolves the authenticated user by loading the token's `tokenable` morph relation. The
 * default relation applies every global scope on the related model, so the {@see SoftDeletes}
 * scope on {@see User} silently excludes soft-deleted users — making a deleted user's token look
 * like it belongs to nobody (the user is "treated as absent" and the request falls through to a
 * generic unauthenticated response).
 *
 * Overriding `tokenable()` with `withTrashed()` includes soft-deleted users in the authentication
 * lookup so the token resolves to the (trashed) user. The {@see \App\Http\Middleware\EnsureAuthenticatedUserIsActive}
 * middleware then rejects that user with an explicit 401, satisfying Req 17.5 (return unauthorized
 * for a soft-deleted user rather than treating the user as absent).
 *
 * `MorphTo::withTrashed()` only applies the `withTrashed` macro to related models that define it
 * (i.e. those using SoftDeletes), so tokens belonging to non-soft-deletable models are unaffected.
 *
 * Sanctum also saves `last_used_at` on every authenticated request. Each save is a database write,
 * so {@see save()} skips a save whose only change is `last_used_at` while the stored value is newer
 * than `sanctum.last_used_write_interval` seconds (0 restores the per-request save).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function save(array $options = [])
    {
        if ($this->lastUsedAtSaveCanBeSkipped()) {
            // Keep the model equal to the stored row.
            $this->attributes['last_used_at'] = $this->getRawOriginal('last_used_at');

            return true;
        }

        return parent::save($options);
    }

    private function lastUsedAtSaveCanBeSkipped(): bool
    {
        $interval = (int) config('sanctum.last_used_write_interval', 300);

        if ($interval <= 0 || ! $this->exists || array_keys($this->getDirty()) !== ['last_used_at']) {
            return false;
        }

        $stored = $this->getOriginal('last_used_at');
        $next = $this->last_used_at;

        return $stored !== null && $next !== null
            && $next->greaterThanOrEqualTo($stored)
            && $stored->diffInSeconds($next) < $interval;
    }

    /**
     * Get the tokenable model that the access token belongs to, including soft-deleted users.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function tokenable()
    {
        return $this->morphTo('tokenable')->withTrashed();
    }
}
