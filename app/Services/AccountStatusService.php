<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Unified account-status lifecycle (Req 16, 17).
 *
 * This service is the single, canonical seam for account state transitions. It consolidates the
 * token/session invalidation that previously lived inline on the {@see User} model
 * (User::booted()) and adds the new `locked` state, the safety guards, the restore/credential
 * refresh path, and audit logging.
 *
 * The three states are mutually exclusive and total by construction:
 *   - deleted  → the row is soft-deleted (`deleted_at` set)
 *   - locked   → `locked_at` is set (and `account_status = locked`)
 *   - active   → neither of the above
 *
 * The legacy `account_status` string column is kept in sync so existing callers/middleware that
 * read it (e.g. User::isAccountEligibleForAuthentication()) continue to work unchanged.
 */
class AccountStatusService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_DELETED = 'deleted';

    /** The three states an account may occupy (AC 16.1). */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_LOCKED,
        self::STATUS_DELETED,
    ];

    /** Roles treated as Super_Admin for the admin-delete guard (AC 16.6). */
    private const SUPER_ADMIN_ROLES = ['superadmin', 'super_admin'];

    /** Roles treated as an admin account for the admin-delete guard (AC 16.6). */
    private const ADMIN_ROLES = ['admin'];

    public function __construct(private readonly AuditLogService $auditLog)
    {
    }

    /**
     * Transition a user to active, locked, or deleted, enforcing safety guards and persisting the
     * change in a single transaction (AC 16.2, 16.4-16.8, 17.1-17.3).
     */
    public function setStatus(User $user, string $status, User $actor): User
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Unsupported account status [{$status}].",
            ]);
        }

        // AC 16.5 — an admin cannot lock or delete their own account.
        if ($actor->getKey() === $user->getKey()
            && in_array($status, [self::STATUS_LOCKED, self::STATUS_DELETED], true)) {
            throw new AuthorizationException('You cannot lock or delete your own account.');
        }

        // AC 16.6 — only a Super_Admin may delete an admin account.
        if ($status === self::STATUS_DELETED && $this->isAdminAccount($user) && !$this->isSuperAdmin($actor)) {
            throw new AuthorizationException('Super_Admin privileges are required to delete an admin account.');
        }

        $previous = $this->currentStatus($user);

        DB::transaction(function () use ($user, $status, $actor, $previous): void {
            match ($status) {
                self::STATUS_ACTIVE => $this->restore($user),     // AC 16.8 — forces credential refresh
                self::STATUS_LOCKED => $this->lock($user),
                self::STATUS_DELETED => $this->softDelete($user), // AC 16.2 — soft delete, row retained
            };

            if (in_array($status, [self::STATUS_LOCKED, self::STATUS_DELETED], true)) {
                $this->invalidateSessions($user);                 // AC 17.1, 17.2 — same request
                // AC 16.7 — audit lock/delete with actor, target, action, and timestamp.
                $this->auditLog->record("account.{$status}", $actor, $user, [
                    'previous' => $previous,
                ]);
            }
        });

        return $user->refresh();
    }

    /**
     * Derive the canonical status (AC 16.1 — exactly one of active/locked/deleted).
     */
    public function currentStatus(User $user): string
    {
        if ($user->trashed()) {
            return self::STATUS_DELETED;
        }

        if ($user->locked_at !== null) {
            return self::STATUS_LOCKED;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Session_Invalidation (AC 17.3): revoke authentication tokens, cached authorization data, and
     * active sessions in the SAME request.
     */
    public function invalidateSessions(User $user): void
    {
        // Authentication tokens (Sanctum).
        $user->tokens()->delete();

        // Cached authorization data.
        Cache::forget("authz:user:{$user->getKey()}");

        // Active sessions (database session driver). Guarded so token-only deployments without a
        // sessions table do not error.
        try {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to purge active sessions during invalidation.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Restore a user to active and force a credential refresh (AC 16.8).
     *
     * Restoring a previously-deleted (or locked) user clears the lifecycle flags, revokes any
     * lingering tokens, flags the account for a forced password reset, and sends a reset link.
     */
    public function restore(User $user): void
    {
        if ($user->trashed()) {
            $user->restore();
        }

        $user->forceFill([
            'locked_at' => null,
            'account_status' => self::STATUS_ACTIVE,
            'password_reset_required' => true,
        ])->save();

        // Invalidate any lingering tokens so the restored user must re-authenticate.
        $user->tokens()->delete();

        $this->sendPasswordReset($user);
    }

    private function lock(User $user): void
    {
        $user->forceFill([
            'locked_at' => now(),
            'account_status' => self::STATUS_LOCKED,
        ])->save();
    }

    private function softDelete(User $user): void
    {
        // Keep the legacy string column meaningful for the retained (soft-deleted) row.
        $user->forceFill(['account_status' => self::STATUS_DELETED])->save();
        $user->delete();
    }

    private function sendPasswordReset(User $user): void
    {
        if (empty($user->email)) {
            return;
        }

        try {
            Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to send password reset link on account restore.', [
                'user_id' => $user->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isSuperAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), self::SUPER_ADMIN_ROLES, true);
    }

    private function isAdminAccount(User $user): bool
    {
        return in_array(strtolower((string) $user->role), self::ADMIN_ROLES, true);
    }
}
