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

    /**
     * Cached user-directory lists that must be busted whenever an account's
     * lifecycle changes, so a locked/deleted/restored user does not linger in a
     * dropdown or directory for the cache TTL (QA #15a). Keep in sync with the
     * keys used by the directory endpoints (e.g. UserController::photographers).
     */
    private const USER_DIRECTORY_CACHE_KEYS = [
        'photographers_list_v3',
    ];

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

        // Cached user-directory lists (QA #15a) — bust so a locked/deleted user does
        // not linger in directories/dropdowns until the list cache TTL expires.
        $this->bustUserDirectoryCaches();

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
     * Forget cached user-directory lists so lifecycle changes are reflected
     * immediately rather than after the list cache TTL (QA #15a).
     */
    public function bustUserDirectoryCaches(): void
    {
        foreach (self::USER_DIRECTORY_CACHE_KEYS as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Restore a user to active and force a credential refresh (AC 16.8).
     *
     * Restoring a previously-deleted (or locked) user clears the lifecycle flags, revokes any
     * lingering tokens, flags the account for a forced password reset, and sends a reset link.
     *
     * Restore safety (account lifecycle):
     *   - Blocked once the 14-day restore window has elapsed (`restore_until` in the past).
     *   - The original email is reclaimed only when still free. If a brand-new account has
     *     since reused it, restore is refused with a clear conflict unless the admin supplies
     *     an alternative `$overrideEmail`. The deleted account is NEVER merged into the new one.
     *
     * @param string|null $overrideEmail When the original email is taken, the admin-supplied
     *                                    replacement email to assign to the restored account.
     */
    public function restore(User $user, ?string $overrideEmail = null): void
    {
        // Enforce the 14-day restore window for soft-deleted accounts. A locked account has no
        // window (it was never deleted), so only guard when the row is trashed.
        if ($user->trashed()
            && Schema::hasColumn('users', 'restore_until')
            && $user->restore_until !== null
            && now()->greaterThan($user->restore_until)) {
            throw new AuthorizationException('The 14-day restore window for this account has expired.');
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $restoreUpdates = [
            'locked_at' => null,
            'account_status' => self::STATUS_ACTIVE,
            'password_reset_required' => true,
        ];

        if (Schema::hasColumn('users', 'restore_until')) {
            $restoreUpdates['restore_until'] = null; // close the window — account is active again
        }

        $metadata = is_array($user->metadata) ? $user->metadata : [];

        // Resolve the email to restore with.
        $originalEmail = $metadata['deleted_original_email'] ?? null;

        if ($overrideEmail !== null && $overrideEmail !== '') {
            // Admin chose to restore under a different email. Validate uniqueness against
            // every other account (including other trashed rows).
            $overrideTaken = User::withTrashed()
                ->where('email', $overrideEmail)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($overrideTaken) {
                throw ValidationException::withMessages([
                    'email' => 'That email is already in use. Choose a different one.',
                ]);
            }
            $restoreUpdates['email'] = $overrideEmail;
            unset($metadata['deleted_original_email']);
        } elseif ($originalEmail) {
            // No override: try to reclaim the original email, but only if it is still free.
            $taken = User::where('email', $originalEmail)->where('id', '!=', $user->id)->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => 'This email is already used by a new account. Restore with a different email or cancel.',
                ]);
            }
            $restoreUpdates['email'] = $originalEmail;
            unset($metadata['deleted_original_email']);
        }

        // Reclaim the original username only if still free; otherwise keep the tombstone.
        if (!empty($metadata['deleted_original_username'])) {
            $originalUsername = $metadata['deleted_original_username'];
            $taken = User::where('username', $originalUsername)->where('id', '!=', $user->id)->exists();
            if (!$taken) {
                $restoreUpdates['username'] = $originalUsername;
                unset($metadata['deleted_original_username']);
            }
        }
        $restoreUpdates['metadata'] = $metadata;

        $user->forceFill($restoreUpdates)->save();

        // Invalidate any lingering tokens so the restored user must re-authenticate.
        $user->tokens()->delete();

        // Refresh directory caches so the restored user reappears immediately.
        $this->bustUserDirectoryCaches();

        $this->sendPasswordReset($user);
    }

    /**
     * Restore a soft-deleted/locked account on behalf of an admin, with auditing and an optional
     * email override (used when the original email has been reused by a new account).
     *
     * Surfaces the same exceptions as {@see restore()}:
     *   - AuthorizationException → expired 14-day window.
     *   - ValidationException → email conflict / override already in use.
     */
    public function restoreAccount(User $user, User $actor, ?string $overrideEmail = null): User
    {
        $previous = $this->currentStatus($user);

        DB::transaction(function () use ($user, $actor, $overrideEmail, $previous): void {
            $this->restore($user, $overrideEmail);
            $this->auditLog->record('account.restored', $actor, $user, [
                'previous' => $previous,
            ]);
        });

        return $user->refresh();
    }

    /**
     * Permanently purge a deleted account after its restore window has elapsed.
     *
     * This NEVER force-deletes the user row (doing so would cascade and wipe business history
     * such as shoots, invoices, payments, media and payouts). Instead it:
     *   - anonymizes the surviving row so it reads as "Deleted User" wherever it is still
     *     referenced (old shoots/invoices/logs), scrubbing all personal fields;
     *   - removes the original email/username (and other PII) from metadata and stamps
     *     metadata.purged_at;
     *   - deletes the user's personal child data (OAuth/calendar/onboarding/branding/etc.).
     *
     * Idempotent: a row already carrying metadata.purged_at is left untouched.
     */
    public function purge(User $user): void
    {
        $metadata = is_array($user->metadata) ? $user->metadata : [];
        if (!empty($metadata['purged_at'])) {
            return; // already purged
        }

        DB::transaction(function () use ($user): void {
            $userId = $user->getKey();
            $tombstone = 'purged_' . $userId . '_' . now()->timestamp;

            // Delete personal child data. Business/audit history is intentionally retained.
            foreach (self::PERSONAL_CHILD_TABLES as $child) {
                try {
                    if (Schema::hasTable($child['table'])
                        && Schema::hasColumn($child['table'], $child['column'])) {
                        DB::table($child['table'])->where($child['column'], $userId)->delete();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Unable to purge personal child table.', [
                        'table' => $child['table'],
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Anonymize the surviving row. Strip every personal field, keeping only what is
            // needed for the row to read as an anonymized historical actor.
            $scrub = [
                'name' => 'Deleted User',
                'email' => $tombstone . '@purged.invalid',
                'username' => $tombstone,
                'phonenumber' => null,
                'phone' => null,
                'avatar' => null,
                'bio' => null,
                'about' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'zip' => null,
                'company_name' => null,
                'company_notes' => null,
                'license_number' => null,
                'facebook_url' => null,
                'twitter_url' => null,
                'linkedin_url' => null,
                'pinterest_url' => null,
                'remember_token' => null,
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'account_status' => self::STATUS_DELETED,
            ];

            if (Schema::hasColumn('users', 'restore_until')) {
                $scrub['restore_until'] = null;
            }

            // Drop PII from metadata and stamp the purge.
            $purgedMetadata = is_array($user->metadata) ? $user->metadata : [];
            unset(
                $purgedMetadata['deleted_original_email'],
                $purgedMetadata['deleted_original_username']
            );
            $purgedMetadata['purged_at'] = now()->toIso8601String();
            $scrub['metadata'] = $purgedMetadata;

            // Only scrub columns that actually exist on this schema.
            $scrub = array_filter(
                $scrub,
                fn ($key) => Schema::hasColumn('users', $key),
                ARRAY_FILTER_USE_KEY
            );

            $user->forceFill($scrub)->saveQuietly();
        });

        // Revoke any external credentials that may have re-appeared (defensive; idempotent).
        $this->revokeExternalCredentials($user);
    }

    private function lock(User $user): void
    {
        $user->forceFill([
            'locked_at' => now(),
            'account_status' => self::STATUS_LOCKED,
        ])->save();
    }

    /** Length of the restore window before a deleted account is purged/anonymized. */
    public const RESTORE_WINDOW_DAYS = 14;

    /**
     * Personal child tables that hold a deleted user's private data and must be removed at
     * purge time. Business/audit history (shoots, invoices, payments, shoot_files, payouts,
     * etc.) is intentionally NOT listed — those rows are kept and the user row survives as an
     * anonymized historical actor. Each entry is guarded with Schema::hasTable.
     *
     * @var array<int, array{table: string, column: string}>
     */
    private const PERSONAL_CHILD_TABLES = [
        ['table' => 'oauth_tokens', 'column' => 'user_id'],
        ['table' => 'google_calendar_connections', 'column' => 'user_id'],
        ['table' => 'onboarding_events', 'column' => 'user_id'],
        ['table' => 'photographer_service_areas', 'column' => 'user_id'],
        ['table' => 'user_branding_clients', 'column' => 'user_id'],
        ['table' => 'user_branding', 'column' => 'user_id'],
        ['table' => 'ai_chat_sessions', 'column' => 'user_id'],
        ['table' => 'account_links', 'column' => 'user_id'],
        ['table' => 'shoot_ghost_users', 'column' => 'user_id'],
        ['table' => 'user_activity_logs', 'column' => 'user_id'],
        ['table' => 'google_calendar_event_mappings', 'column' => 'user_id'],
    ];

    private function softDelete(User $user): void
    {
        // Free the email + username so the SAME address can be used to create a brand-new
        // account after deletion, while retaining this row (and its shoot/invoice history
        // and FK references) for referential integrity. The originals are stashed in
        // metadata so a later restore can recover them when still available.
        $metadata = is_array($user->metadata) ? $user->metadata : [];
        $tombstone = 'deleted_' . $user->id . '_' . now()->timestamp;

        $updates = ['account_status' => self::STATUS_DELETED];

        if (!empty($user->email)) {
            $metadata['deleted_original_email'] = $user->email;
            $updates['email'] = $tombstone . '@deleted.invalid';
        }
        if (!empty($user->username)) {
            $metadata['deleted_original_username'] = $user->username;
            $updates['username'] = $tombstone;
        }

        $updates['metadata'] = $metadata;

        // Open the 14-day restore window. After this deadline the scheduled
        // users:purge-deleted command anonymizes the row and removes personal data.
        if (Schema::hasColumn('users', 'restore_until')) {
            $updates['restore_until'] = now()->addDays(self::RESTORE_WINDOW_DAYS);
        }

        $user->forceFill($updates)->save();
        $user->delete();

        // Revoke external credentials immediately so a deleted account cannot keep a live
        // OAuth/calendar connection during the restore window. These are personal and are
        // re-established on restore via the normal reconnect flow.
        $this->revokeExternalCredentials($user);
    }

    /**
     * Delete a deleted user's external OAuth / calendar credentials. Safe to call repeatedly.
     */
    private function revokeExternalCredentials(User $user): void
    {
        $userId = $user->getKey();

        try {
            if (Schema::hasTable('oauth_tokens')) {
                DB::table('oauth_tokens')->where('user_id', $userId)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to revoke oauth_tokens on delete.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        try {
            if (Schema::hasTable('google_calendar_connections')) {
                DB::table('google_calendar_connections')->where('user_id', $userId)->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to revoke google_calendar_connections on delete.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
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
