<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailVerificationPilot
{
    public function startedAt(): ?Carbon
    {
        // Safe during the deployment interval before the additive migration runs.
        if (!Schema::hasTable('auth_security_rollouts')) {
            return null;
        }
        $value = DB::table('auth_security_rollouts')->where('name', 'email-verification')->value('started_at');
        return $value ? Carbon::parse($value) : null;
    }

    public function verified(User $user): bool
    {
        if ($user->email_verified_at !== null && $user->email_verification_required_at === null && $user->email_verified_email === null) {
            return true; // Existing unchanged accounts retain their recorded proof.
        }
        return $user->email_verified_at !== null
            && is_string($user->email_verified_email)
            && hash_equals(mb_strtolower(trim($user->email)), $user->email_verified_email);
    }

    public function status(User $user): array
    {
        $start = $this->startedAt();
        $enrolled = $start !== null && $user->email_verification_required_at !== null;
        $deadline = $start?->copy()->addDays(14);
        $verified = $this->verified($user);
        return [
            'enrolled' => $enrolled,
            'verified' => $verified,
            'reminder' => $start !== null && !$verified,
            'required' => $enrolled && !$verified && now()->greaterThanOrEqualTo($deadline),
            'enforce_at' => $enrolled ? $deadline->toIso8601String() : null,
        ];
    }
}
