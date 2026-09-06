<?php

namespace App\Services\Studio;

use App\Exceptions\StudioClientAccessPaused;
use Illuminate\Contracts\Auth\Authenticatable;

class StudioClientAccess
{
    public static function isPaused(?Authenticatable $user): bool
    {
        return $user !== null
            && strtolower(trim((string) ($user->role ?? 'client'))) === 'client'
            && ! config('studio.client_access_enabled', false);
    }

    public static function authorize(?Authenticatable $user): void
    {
        if (self::isPaused($user)) {
            throw new StudioClientAccessPaused;
        }
    }
}
