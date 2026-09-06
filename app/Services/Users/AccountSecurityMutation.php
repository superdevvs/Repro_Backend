<?php

namespace App\Services\Users;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountSecurityMutation
{
    public static function lockUser(int|string $id): User
    {
        // SQLite ignores SELECT FOR UPDATE. A no-op write acquires its writer
        // reservation before reading credentials; other databases lock this row.
        DB::table('users')->where('id', $id)->update(['id' => DB::raw('id')]);
        return User::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function run(Request $request, ?string $password, Closure $callback): mixed
    {
        abort_if($request->attributes->get('is_impersonating'), 403, 'Security changes are unavailable while impersonating.');
        $original = $request->user();
        if ($password !== null && !Hash::check($password, (string) $original->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $result = DB::transaction(function () use ($request, $callback) {
            $request->attributes->set('security_after_commit', []);
            $original = $request->user();
            $user = self::lockUser($original->getKey());
            abort_unless($user->isAccountEligibleForAuthentication(), 403, 'This account is no longer active.');
            $token = $original->currentAccessToken();
            if ($token && method_exists($token, 'getKey')) {
                $freshToken = $user->tokens()->whereKey($token->getKey())->first();
                abort_unless($freshToken && (!$freshToken->expires_at || $freshToken->expires_at->isFuture()), 401, 'Please sign in again.');
                $user->withAccessToken($freshToken);
            } else {
                // These API flows issue persisted bearer sessions, not web login
                // sessions. A stale ambient session has no revocation proof here.
                abort(401, 'Sign in with a dashboard session to change security settings.');
            }
            if (!hash_equals((string) $original->getRawOriginal('password'), (string) $user->getRawOriginal('password'))) {
                abort(401, 'Please sign in again.');
            }
            // The verified hash is identical under the lock; never repeat an
            // expensive password hash while holding SQLite's writer reservation.
            return $callback($user);
        }, 3);
        $callbacks = $request->attributes->get('security_after_commit', []);
        $request->attributes->remove('security_after_commit');
        foreach ($callbacks as $afterCommit) {
            $afterCommit();
        }
        return $result;
    }

    public static function afterCommit(Request $request, Closure $callback): void
    {
        $callbacks = $request->attributes->get('security_after_commit', []);
        $callbacks[] = $callback;
        $request->attributes->set('security_after_commit', $callbacks);
    }
}
