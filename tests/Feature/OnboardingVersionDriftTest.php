<?php

namespace Tests\Feature;

use App\Services\Users\DashboardOnboardingService;
use Tests\TestCase;

/**
 * Frontend/backend onboarding version drift guard.
 *
 * The onboarding subsystem keeps two parallel sources of version truth:
 *   - Backend: the VERSION_* constants in {@see DashboardOnboardingService}.
 *   - Frontend: each role's `version` field in
 *     frontend/src/features/dashboard/config/dashboardOnboardingConfig.ts.
 *
 * Bumping one without the other causes drift: the backend would re-trigger
 * onboarding (or not) out of step with the frontend config, breaking the
 * version-based re-trigger contract for logged-in users.
 *
 * We cannot import the TypeScript config into PHPUnit, so this test acts as a
 * documented lock on the backend side. The expected integers below are the
 * canonical versions that MUST match the frontend config.
 *
 * SINGLE BUMP CHECKLIST — when you change a backend VERSION_* constant you MUST:
 *   1. Update the matching expected value in this test.
 *   2. Bump the matching role `version` in
 *      frontend/src/features/dashboard/config/dashboardOnboardingConfig.ts
 *      so the frontend stays in lock-step.
 * If you change a backend version without updating this expectation, this test
 * fails on purpose — that failure is the reminder to complete the checklist.
 */
class OnboardingVersionDriftTest extends TestCase
{
    /**
     * Canonical expected versions, mirrored from the frontend config.
     * Keep these in sync with dashboardOnboardingConfig.ts (see checklist above).
     */
    private const EXPECTED_VERSIONS = [
        'VERSION_CLIENT' => 1,
        'VERSION_PHOTOGRAPHER' => 1,
        'VERSION_SALES_REP' => 1,
        'VERSION_EDITING_MANAGER' => 1,
        'VERSION_EDITOR' => 1,
    ];

    /**
     * Canonical role -> preference key mapping, mirrored from the frontend
     * config's `onboardingKey` values. 'client' uses the legacy key.
     */
    private const EXPECTED_ROLE_KEYS = [
        'client' => 'clientDashboardOnboarding',
        'photographer' => 'photographerDashboardOnboarding',
        'salesRep' => 'salesRepDashboardOnboarding',
        'editing_manager' => 'editingManagerDashboardOnboarding',
        'editor' => 'editorDashboardOnboarding',
    ];

    public function test_backend_versions_match_expected_lock(): void
    {
        $this->assertSame(
            self::EXPECTED_VERSIONS['VERSION_CLIENT'],
            DashboardOnboardingService::VERSION_CLIENT,
            'VERSION_CLIENT drifted. Update this expectation AND the frontend client.version.'
        );
        $this->assertSame(
            self::EXPECTED_VERSIONS['VERSION_PHOTOGRAPHER'],
            DashboardOnboardingService::VERSION_PHOTOGRAPHER,
            'VERSION_PHOTOGRAPHER drifted. Update this expectation AND the frontend photographer.version.'
        );
        $this->assertSame(
            self::EXPECTED_VERSIONS['VERSION_SALES_REP'],
            DashboardOnboardingService::VERSION_SALES_REP,
            'VERSION_SALES_REP drifted. Update this expectation AND the frontend salesRep.version.'
        );
        $this->assertSame(
            self::EXPECTED_VERSIONS['VERSION_EDITING_MANAGER'],
            DashboardOnboardingService::VERSION_EDITING_MANAGER,
            'VERSION_EDITING_MANAGER drifted. Update this expectation AND the frontend editing_manager.version.'
        );
        $this->assertSame(
            self::EXPECTED_VERSIONS['VERSION_EDITOR'],
            DashboardOnboardingService::VERSION_EDITOR,
            'VERSION_EDITOR drifted. Update this expectation AND the frontend editor.version.'
        );
    }

    public function test_role_key_mapping_matches_canonical_keys(): void
    {
        $service = new DashboardOnboardingService();

        foreach (self::EXPECTED_ROLE_KEYS as $role => $expectedKey) {
            $this->assertTrue(
                $service->isOnboardedRole($role),
                "Role '{$role}' is expected to be an onboarded role."
            );
            $this->assertSame(
                $expectedKey,
                $service->keyForRole($role),
                "Preference key for role '{$role}' drifted from the canonical frontend onboardingKey."
            );
        }
    }

    public function test_role_versions_resolve_to_expected_lock(): void
    {
        $service = new DashboardOnboardingService();

        $this->assertSame(self::EXPECTED_VERSIONS['VERSION_CLIENT'], $service->versionForRole('client'));
        $this->assertSame(self::EXPECTED_VERSIONS['VERSION_PHOTOGRAPHER'], $service->versionForRole('photographer'));
        $this->assertSame(self::EXPECTED_VERSIONS['VERSION_SALES_REP'], $service->versionForRole('salesRep'));
        $this->assertSame(self::EXPECTED_VERSIONS['VERSION_EDITING_MANAGER'], $service->versionForRole('editing_manager'));
        $this->assertSame(self::EXPECTED_VERSIONS['VERSION_EDITOR'], $service->versionForRole('editor'));
    }
}
