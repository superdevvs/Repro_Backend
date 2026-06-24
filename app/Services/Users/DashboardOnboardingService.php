<?php

namespace App\Services\Users;

use App\Models\User;

class DashboardOnboardingService
{
    public const VERSION_CLIENT = 1;
    public const VERSION_PHOTOGRAPHER = 1;
    public const VERSION_SALES_REP = 1;
    public const VERSION_EDITING_MANAGER = 1;
    public const VERSION_EDITOR = 1;

    /**
     * Map each onboarded role to its preference key and version constant.
     * 'client' uses the legacy key for backward compatibility.
     */
    private const ROLE_MAP = [
        'client' => ['key' => 'clientDashboardOnboarding', 'version' => self::VERSION_CLIENT],
        'photographer' => ['key' => 'photographerDashboardOnboarding', 'version' => self::VERSION_PHOTOGRAPHER],
        'salesRep' => ['key' => 'salesRepDashboardOnboarding', 'version' => self::VERSION_SALES_REP],
        'editing_manager' => ['key' => 'editingManagerDashboardOnboarding', 'version' => self::VERSION_EDITING_MANAGER],
        'editor' => ['key' => 'editorDashboardOnboarding', 'version' => self::VERSION_EDITOR],
    ];

    /**
     * Lifecycle fields cleared when re-triggering for a newer version.
     */
    private const RESETTABLE_FIELDS = ['completedAt', 'dismissedAt', 'startedAt', 'lastStep'];

    public function isOnboardedRole(?string $role): bool
    {
        return $role !== null && array_key_exists($role, self::ROLE_MAP);
    }

    public function keyForRole(string $role): ?string
    {
        return self::ROLE_MAP[$role]['key'] ?? null;
    }

    public function versionForRole(string $role): ?int
    {
        return self::ROLE_MAP[$role]['version'] ?? null;
    }

    /**
     * Apply (or re-evaluate) the role-aware onboarding block on a metadata array.
     *
     * - Non-onboarded roles return metadata unchanged.
     * - Fresh users get a new block at the current version.
     * - Users whose stored version is below current are re-triggered.
     * - Users already at the current version are left unchanged.
     */
    public function applyEligibility(array $metadata, string $role, ?string $source = null): array
    {
        if (!$this->isOnboardedRole($role)) {
            return $metadata; // Requirement 1.4
        }

        $key = self::ROLE_MAP[$role]['key'];
        $currentVersion = self::ROLE_MAP[$role]['version'];

        $preferences = $metadata['preferences'] ?? [];
        if (!is_array($preferences)) {
            $preferences = [];
        }

        $existing = $preferences[$key] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $storedVersion = isset($existing['version']) ? (int) $existing['version'] : null;

        if ($storedVersion !== null && $storedVersion >= $currentVersion) {
            // Already at (or beyond) current version: leave eligible + lifecycle untouched.
            // Requirement 4.3 — return unchanged.
            $metadata['preferences'] = $preferences;
            return $metadata;
        }

        if ($storedVersion !== null && $storedVersion < $currentVersion) {
            // Version-based re-trigger. Requirement 4.1 / 4.2.
            foreach (self::RESETTABLE_FIELDS as $field) {
                unset($existing[$field]);
            }
        }

        // Fresh application or re-trigger: set core eligibility fields.
        // Preserve any other existing lifecycle values (e.g. createdAt on re-trigger).
        $block = array_replace($existing, array_filter([
            'eligible' => true,
            'version' => $currentVersion,
            'createdAt' => $existing['createdAt'] ?? now()->toISOString(),
            'source' => $source,
        ], fn ($value) => $value !== null));

        $preferences[$key] = $block;
        $metadata['preferences'] = $preferences;

        return $metadata;
    }

    /**
     * Re-evaluate onboarding eligibility for an existing user and persist only
     * when the resulting metadata differs from what is stored.
     *
     * Reuses {@see applyEligibility} so version-based re-triggering happens the
     * same way it does at account-creation/seed paths. This is idempotent: when
     * the stored version already equals the current version the metadata comes
     * back unchanged and no write occurs, so steady-state requests do zero writes.
     *
     * @return bool Whether the user's metadata was changed and saved.
     */
    public function refreshEligibilityForUser(User $user): bool
    {
        $role = $user->role;

        if (!$this->isOnboardedRole($role)) {
            return false; // admin/superadmin and any non-onboarded role: nothing to do.
        }

        $before = is_array($user->metadata) ? $user->metadata : [];
        $after = $this->applyEligibility($before, $role, 'user_fetch');

        if ($after === $before) {
            return false; // Already at current version: no change, no write.
        }

        $user->metadata = $after;
        $user->save();

        return true;
    }
}
