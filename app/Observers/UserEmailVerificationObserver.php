<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Users\EmailVerificationPilot;

class UserEmailVerificationObserver
{
    public function saving(User $user): void
    {
        if (!$user->isDirty('email')) {
            return;
        }
        if ($user->exists) {
            $user->forceFill(['email_verified_at' => null, 'email_verified_email' => null]);
        }
        if (app(EmailVerificationPilot::class)->startedAt()) {
            $user->email_verification_required_at ??= now();
        }
    }
}
