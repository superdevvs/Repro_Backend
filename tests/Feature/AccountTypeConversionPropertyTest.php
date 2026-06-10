<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 17: Account-type conversion applies the new role
 *                                              or rejects invalid roles
 *
 * **Validates: Requirements 18.1, 18.2, 18.3**
 *
 * For any source role drawn from the defined-roles set and any target role drawn from the
 * defined ∪ undefined pool, the convertType endpoint exhibits the universal bi-conditional:
 *
 *   - When the target role IS a defined role (RolePermissionService::roleIds()):
 *       * The HTTP response is 200.
 *       * The user's `role` column is updated to the target role and persisted (AC 18.1).
 *       * The response body carries `previous_type` (= source role) and `new_type` (= target).
 *       * Exactly one new Audit_Log entry is written for action `account.type_converted`,
 *         attributed to the acting admin and recording previous + new type (AC 18.4 — covered
 *         here as a corollary of "conversion succeeds").
 *
 *   - When the target role is NOT a defined role (empty string, gibberish, near-miss typos,
 *     wrong casing, wrong shape):
 *       * The HTTP response is 422 (AC 18.3).
 *       * The user's `role` column is unchanged from the source role.
 *       * No Audit_Log entry is written for the rejected conversion.
 *
 * Authorization (AC 18.2) is verified indirectly by routing through the `role:` middleware
 * with an admin actor and asserting persisted role updates that subsequent authorization
 * checks read from.
 *
 * No PBT library is configured for the backend, so this test uses the same deterministic
 * generator approach used by ShootEditingPayloadFilteringPropertyTest /
 * ShootDatePreservationPropertyTest: a seeded PRNG produces 25+ randomized {source, target}
 * cases drawn from defined ∪ undefined target pools, plus a fixed table of deterministic
 * edge cases (every defined→every defined transition; explicitly invalid strings such as
 * '', 'not_a_role', 'admin_typo'). Each generated case asserts every invariant above against
 * the live HTTP endpoint at PATCH /api/admin/users/{user}/convert-type.
 */
class AccountTypeConversionPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seven defined roles, kept in sync with RolePermissionService::roleIds() and
     * config('permissions.roles'). Hardcoded here because PHPUnit data providers run
     * before Laravel is bootstrapped, so app(RolePermissionService::class) is unavailable.
     * The first assertion in the test method re-validates this list against the live
     * RolePermissionService and fails loudly if the source of truth ever drifts.
     *
     * @var list<string>
     */
    private const DEFINED_ROLES = [
        'superadmin',
        'admin',
        'editing_manager',
        'salesRep',
        'photographer',
        'editor',
        'client',
    ];

    /**
     * Pool of strings deliberately NOT in the defined-roles set. Includes empty input,
     * generic gibberish, near-miss typos of real role names, wrong shapes (snake_case
     * vs camelCase), and case-sensitive mismatches.
     *
     * Note: trailing/leading whitespace strings (e.g. 'photographer ') are intentionally
     * NOT included here because Laravel's global TrimStrings middleware normalizes those
     * inputs before validation. After trimming, 'photographer ' becomes the defined role
     * 'photographer', so the request is correctly validated as a defined role. That is
     * framework input normalization, not a hole in AC 18.3 — the property under test is
     * "the value the controller validates against the defined-roles set", not "the raw
     * pre-trim string".
     *
     * @var list<string>
     */
    private const UNDEFINED_ROLES = [
        '',
        'not_a_role',
        'admin_typo',
        'super_admin',     // wrong shape — defined id is 'superadmin'
        'sales_rep',       // wrong shape — defined id is 'salesRep'
        'photo_editor',    // not a defined role (editing lanes are user metadata)
        'video_editor',    // not a defined role
        'manager',
        'guest',
        'ROOT',
        'Admin',           // case-sensitive mismatch
        'photographer.',   // adjacent-character mismatch
    ];

    /**
     * Generator yielding (label, source, target, expectedDefined) tuples covering:
     *   1) every defined → every defined transition (49 cases, AC 18.1)
     *   2) every undefined target string against a representative source (12 cases, AC 18.3)
     *   3) seeded-random {source, target} cases drawn from defined ∪ undefined (30 cases)
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function conversionCaseProvider(): iterable
    {
        $defined = self::DEFINED_ROLES;
        $undefined = self::UNDEFINED_ROLES;

        // 1) Deterministic edge cases — every defined → every defined transition.
        // Conversion to the same role is a valid no-op on the role column, but a
        // new audit entry is still written per AC 18.4. The property holds either way.
        foreach ($defined as $source) {
            foreach ($defined as $target) {
                $label = "edge: defined→defined: {$source} → {$target}";
                yield $label => [$label, $source, $target, true];
            }
        }

        // 2) Deterministic edge cases — every undefined target against a representative
        // defined source role. The endpoint must reject all of these with 422.
        foreach ($undefined as $target) {
            $label = sprintf('edge: defined→undefined: photographer → %s', json_encode($target));
            yield $label => [$label, 'photographer', $target, false];
        }

        // 3) Seeded random cases — reproducible across runs by setting a fixed seed.
        // mt_srand applies process-wide state, but each iteration is fully determined
        // by the seed and the case index, so the case sequence is reproducible.
        mt_srand(20260619);

        $randomCases = 30;
        for ($i = 0; $i < $randomCases; $i++) {
            // Random source ALWAYS drawn from the defined-roles pool.
            $source = $defined[mt_rand(0, count($defined) - 1)];

            // Random target drawn from defined ∪ undefined; biased ~1:1 so both
            // branches of the bi-conditional are consistently exercised.
            $expectedDefined = mt_rand(0, 1) === 0;
            $target = $expectedDefined
                ? $defined[mt_rand(0, count($defined) - 1)]
                : $undefined[mt_rand(0, count($undefined) - 1)];

            $label = sprintf(
                'random: case %02d: %s → %s (%s)',
                $i,
                $source,
                json_encode($target),
                $expectedDefined ? 'defined' : 'undefined',
            );
            yield $label => [$label, $source, $target, $expectedDefined];
        }
    }

    /**
     * Property 17 — every (source, target) case satisfies the bi-conditional invariant:
     * defined targets succeed (200) and write exactly one audit entry recording previous
     * + new type; undefined targets are rejected (422) and leave the user's role unchanged
     * with no audit entry written.
     */
    #[Test]
    #[DataProvider('conversionCaseProvider')]
    public function convert_type_applies_or_rejects_per_target_role(
        string $label,
        string $source,
        string $target,
        bool $expectDefined,
    ): void {
        // Sanity — the data provider's defined/undefined classification must match the
        // live RolePermissionService, which is the same oracle the controller's
        // Rule::in(...) validator uses. Catches drift between this test and config.
        $definedRoles = app(RolePermissionService::class)->roleIds();
        $this->assertSame(
            $expectDefined,
            in_array($target, $definedRoles, true),
            "Test classification disagrees with RolePermissionService for [{$label}]",
        );

        // Distinct admin actor and target so any self-conversion edge cases are not
        // confounded with the property under test.
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create(['role' => $source]);

        Sanctum::actingAs($admin);

        // RefreshDatabase wipes the audit table between test methods, so this should
        // always be 0; capturing it explicitly makes the +1 assertion robust to any
        // future setup that pre-populates audit rows.
        $previousAuditCount = UserActivityLog::query()
            ->where('event_type', 'account.type_converted')
            ->count();

        $response = $this->patchJson(
            "/api/admin/users/{$targetUser->id}/convert-type",
            ['account_type' => $target],
        );

        if ($expectDefined) {
            // AC 18.1 — conversion succeeds and the role is updated to the target.
            $response->assertOk()
                ->assertJson([
                    'id' => $targetUser->id,
                    'role' => $target,
                    'previous_type' => $source,
                    'new_type' => $target,
                ]);

            $this->assertSame(
                $target,
                $targetUser->fresh()->role,
                "Role must persist as the new type for [{$label}]",
            );

            // Corollary of "conversion succeeded": exactly one new audit entry
            // attributed to the acting admin, recording both previous and new types.
            $newAuditCount = UserActivityLog::query()
                ->where('event_type', 'account.type_converted')
                ->count();

            $this->assertSame(
                $previousAuditCount + 1,
                $newAuditCount,
                "Expected exactly one new audit entry for [{$label}]",
            );

            $entry = UserActivityLog::query()
                ->where('event_type', 'account.type_converted')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($entry, "Audit entry must exist for [{$label}]");
            $this->assertSame($admin->id, $entry->actor_user_id, "Audit actor mismatch for [{$label}]");
            $this->assertSame((int) $targetUser->id, (int) $entry->target_id, "Audit target mismatch for [{$label}]");
            $this->assertIsArray($entry->metadata);
            $this->assertSame($source, $entry->metadata['previous_type'] ?? null, "previous_type metadata mismatch for [{$label}]");
            $this->assertSame($target, $entry->metadata['new_type'] ?? null, "new_type metadata mismatch for [{$label}]");
        } else {
            // AC 18.3 — invalid roles are rejected with HTTP 422.
            $response->assertStatus(422);

            // The user's role must remain unchanged.
            $this->assertSame(
                $source,
                $targetUser->fresh()->role,
                "Role must NOT change when conversion is rejected for [{$label}]",
            );

            // No audit entry is written for a rejected conversion.
            $newAuditCount = UserActivityLog::query()
                ->where('event_type', 'account.type_converted')
                ->count();

            $this->assertSame(
                $previousAuditCount,
                $newAuditCount,
                "No audit entry should be written for rejected conversion in [{$label}]",
            );
        }
    }
}
