<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountStatusService;
use App\Services\AuditLogService;
use App\Services\RolePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Account lifecycle endpoints (Req 16-18).
 *
 * This controller exposes account-status transitions (active/locked/deleted, Req 16-17) which
 * delegate to {@see AccountStatusService}, and account-type conversion (Req 18). convertType is
 * intentionally self-contained because changing a role does not require the lifecycle service.
 */
class AccountStatusController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly RolePermissionService $rolePermissions,
        private readonly AccountStatusService $accountStatus,
    ) {
    }

    /**
     * List soft-deleted accounts that are still within (or recently past) the restore window,
     * for the Settings → Deleted Accounts panel. Already-purged rows are excluded.
     */
    public function deletedAccounts(Request $request): JsonResponse
    {
        $now = now();

        $accounts = User::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get()
            ->filter(function (User $user) {
                $metadata = is_array($user->metadata) ? $user->metadata : [];
                return empty($metadata['purged_at']); // hide already-anonymized rows
            })
            ->map(function (User $user) use ($now) {
                $metadata = is_array($user->metadata) ? $user->metadata : [];
                $restoreUntil = $user->restore_until;
                $daysRemaining = $restoreUntil
                    ? max(0, (int) ceil($now->floatDiffInDays($restoreUntil, false)))
                    : null;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'original_email' => $metadata['deleted_original_email'] ?? null,
                    'original_username' => $metadata['deleted_original_username'] ?? null,
                    'role' => $user->role,
                    'deleted_at' => optional($user->deleted_at)->toIso8601String(),
                    'restore_until' => optional($restoreUntil)->toIso8601String(),
                    'days_remaining' => $daysRemaining,
                    'restorable' => $restoreUntil === null || $now->lessThanOrEqualTo($restoreUntil),
                ];
            })
            ->values();

        return response()->json(['data' => $accounts]);
    }

    /**
     * Restore a soft-deleted account within its 14-day window.
     *
     * Accepts an optional `email` override for when the original email has been reused by a new
     * account. Maps service exceptions:
     *   - AuthorizationException → 403 (expired window / privilege) via the global handler.
     *   - ValidationException → 422 (email conflict / override already in use).
     */
    public function restore(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email'],
        ]);

        $updated = $this->accountStatus->restoreAccount(
            $user,
            $request->user(),
            $validated['email'] ?? null
        );

        return response()->json([
            'id' => $updated->id,
            'status' => $this->accountStatus->currentStatus($updated),
            'email' => $updated->email,
        ]);
    }

    /**
     * Set a user's account status to active, locked, or deleted (Req 16, 17).
     *
     * Delegates the transition, safety guards, session invalidation, and audit logging to
     * {@see AccountStatusService::setStatus()}. The service throws:
     *   - AuthorizationException → mapped to 403 by the global API exception handler
     *     (self lock/delete per AC 16.5; non-superadmin deleting an admin per AC 16.6).
     *   - ValidationException → mapped to 422 (unsupported status value, AC 16.1).
     *
     * On success returns the persisted, canonical status (AC 16.4).
     */
    public function setStatus(Request $request, User $user): JsonResponse
    {
        // AC 16.1 — status must be one of the three canonical states. The service re-validates,
        // but rejecting here yields a 422 before any work is attempted.
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(AccountStatusService::STATUSES)],
        ]);

        $updated = $this->accountStatus->setStatus($user, $validated['status'], $request->user());

        return response()->json([
            'id' => $updated->id,
            'status' => $this->accountStatus->currentStatus($updated), // AC 16.4 — persisted status
        ]);
    }

    /**
     * Convert a user to a different account type / role (Req 18).
     *
     * - AC 18.3: rejects an account type that is not a defined role with a validation error (422).
     * - AC 18.1: updates the user's role to the selected value.
     * - AC 18.2: clears the cached authorization entry so subsequent requests reflect the new role.
     * - AC 18.4: writes an Audit_Log entry with the acting admin, timestamp, target user,
     *            previous account type, and new account type.
     */
    public function convertType(Request $request, User $user): JsonResponse
    {
        $definedRoles = $this->rolePermissions->roleIds();

        $validated = $request->validate([
            'account_type' => ['required', 'string', Rule::in($definedRoles)], // AC 18.3
        ]);

        $newType = $validated['account_type'];
        $previousType = $user->role;

        $user->update(['role' => $newType]); // AC 18.1

        // AC 18.2 — drop any cached authorization so the next request derives authz from the new role.
        Cache::forget("authz:user:{$user->id}");

        // AC 18.4 — audit the conversion with previous + new account type.
        $this->auditLog->record('account.type_converted', $request->user(), $user, [
            'previous_type' => $previousType,
            'new_type' => $newType,
        ]);

        return response()->json([
            'id' => $user->id,
            'role' => $user->role,
            'previous_type' => $previousType,
            'new_type' => $newType,
        ]);
    }
}
