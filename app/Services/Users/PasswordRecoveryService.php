<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordRecoveryService
{
    public function issue(User $user, ?string $expectedVerifiedEmail = null): string
    {
        $token = Str::random(64);
        $hash = Hash::make($token);
        return DB::transaction(function () use ($user, $token, $hash, $expectedVerifiedEmail): string {
            $fresh = AccountSecurityMutation::lockUser($user->getKey());
            abort_unless($fresh->isAccountEligibleForAuthentication(), 403, 'This account is no longer active.');
            if ($expectedVerifiedEmail !== null) {
                abort_unless(hash_equals(mb_strtolower(trim($expectedVerifiedEmail)), mb_strtolower(trim($fresh->email)))
                    && app(EmailVerificationPilot::class)->verified($fresh), 403, 'Verify your current email address before creating a password.');
            }
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => mb_strtolower(trim($fresh->email))],
                ['token' => $hash, 'created_at' => now()],
            );
            $user->setRawAttributes($fresh->getAttributes(), true);
            return $token;
        }, 3);
    }

    public function consume(string $email, string $token, string $password): ?User
    {
        $id = User::where('email', $email)->value('id');
        if (!$id) {
            return null;
        }
        $snapshot = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (!$snapshot || !$snapshot->created_at || \Illuminate\Support\Carbon::parse($snapshot->created_at)->addMinutes(60)->isPast()
            || !Hash::check($token, $snapshot->token)) {
            return null;
        }
        $passwordHash = Hash::make($password);
        return DB::transaction(function () use ($id, $email, $snapshot, $passwordHash): ?User {
            $user = AccountSecurityMutation::lockUser($id);
            if (!$user->isAccountEligibleForAuthentication() || mb_strtolower(trim($user->email)) !== $email) {
                return null;
            }
            $record = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();
            if (!$record || !$record->created_at || \Illuminate\Support\Carbon::parse($record->created_at)->addMinutes(60)->isPast()
                || !hash_equals((string) $snapshot->token, (string) $record->token)) {
                return null;
            }
            $user->forceFill(['password' => $passwordHash, 'password_changed_at' => now(), 'password_reset_required' => false])->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return $user;
        }, 3);
    }
}
