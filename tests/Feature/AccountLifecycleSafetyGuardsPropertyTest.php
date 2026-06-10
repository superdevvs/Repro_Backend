<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 28: Account lifecycle safety guards
 *
 * Validates: Requirements 16.5, 16.6
 *
 * For arbitrary (target, actor, status) tuples, the following universal property holds for
 * {@see AccountStatusService::setStatus()}:
 *
 *   (Guard fires) IF the tuple matches a safety-guard predicate —
 *     (G1) actor.id == target.id AND status ∈ {locked, deleted}
 *          (AC 16.5 — an account cannot lock or delete itself), OR
 *     (G2) target.role == 'admin' AND status == 'deleted' AND
 *          actor.role NOT IN {'superadmin','super_admin'}
 *          (AC 16.6 — Super_Admin privileges are required to delete an admin account)
 *   THEN setStatus throws an {@see AuthorizationException} AND the target's persisted state
 *   (account_status, locked_at, deleted_at, password_reset_required, tokens count, role) is
 *   bit-for-bit identical before and after the call. Both guards short-circuit before the DB
 *   transaction, so a guarded call is observably a no-op.
 *
 *   (Guard does NOT fire) FOR every other (target, actor, status) tuple permitted by validation,
 *   setStatus returns successfully and the target's currentStatus reflects the new value both
 *   on the returned model and on a fresh `User::withTrashed()` database read.
 *
 * Because no PHP property-based-testing library is installed, this test follows the spec's
 * "strong randomization plus deterministic edge cases" approach: 30 randomized
 * {target_role, actor_role, target_is_actor, status} cases plus deterministic edge cases that
 * each exercise one limb of the guard predicate explicitly (self lock, self delete, self active,
 * non-Super_Admin deleting an admin, Super_Admin deleting an admin succeeds, admin locking an
 * admin succeeds). The same universal property must hold for every generated input.
 */
class AccountLifecycleSafetyGuardsPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases; we run 30 plus deterministic edge cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Roles drawn from the property's input space for the target user. */
    private const TARGET_ROLES = ['photographer', 'editor', 'salesRep', 'client', 'admin'];

    /**
     * Roles drawn from the property's input space for the actor (the user invoking
     * setStatus). We restrict the actor to admin-tier roles because non-admin actors are
     * never authorized at the controller layer to invoke this transition; the guards under
     * test (AC 16.5/16.6) are about which admin-tier actors may hit which targets.
     */
    private const ACTOR_ROLES = ['admin', 'superadmin'];

    /** The complete state space for the requested status. */
    private const STATUSES = [
        AccountStatusService::STATUS_ACTIVE,
        AccountStatusService::STATUS_LOCKED,
        AccountStatusService::STATUS_DELETED,
    ];

    /** Roles treated as Super_Admin for the AC 16.6 predicate (mirrors the service constant). */
    private const SUPER_ADMIN_ROLES = ['superadmin', 'super_admin'];

    private AccountStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountStatusService::class);
    }

    /**
     * Build the (target_role, actor_role, target_is_actor, status) generator: 30 randomized
     * cases plus the deterministic edge cases the task spells out. Each tuple drives one full
     * property assertion below.
     *
     * @return list<array{target_role:string, actor_role:string, target_is_actor:bool, status:string, label:string}>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $cases[] = [
                'target_role' => self::TARGET_ROLES[array_rand(self::TARGET_ROLES)],
                'actor_role' => self::ACTOR_ROLES[array_rand(self::ACTOR_ROLES)],
                'target_is_actor' => (bool) mt_rand(0, 1),
                'status' => self::STATUSES[array_rand(self::STATUSES)],
                'label' => "random#{$i}",
            ];
        }

        // Deterministic edge cases — one per limb of the guard predicate.
        // (1) Self lock — AC 16.5 must reject (G1).
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'admin',
            'target_is_actor' => true,
            'status' => AccountStatusService::STATUS_LOCKED,
            'label' => 'edge: self lock (admin)',
        ];
        // (2) Self delete — AC 16.5 must reject (G1).
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'admin',
            'target_is_actor' => true,
            'status' => AccountStatusService::STATUS_DELETED,
            'label' => 'edge: self delete (admin)',
        ];
        // (3) Self active — guard does NOT apply (status ∉ {locked, deleted}); transition succeeds.
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'admin',
            'target_is_actor' => true,
            'status' => AccountStatusService::STATUS_ACTIVE,
            'label' => 'edge: self active (allowed — guard limited to lock/delete)',
        ];
        // (4) Non-Super_Admin deleting an admin — AC 16.6 must reject (G2).
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'admin',
            'target_is_actor' => false,
            'status' => AccountStatusService::STATUS_DELETED,
            'label' => 'edge: non-superadmin deleting admin',
        ];
        // (5) Super_Admin deleting an admin — guard does NOT apply; transition succeeds.
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'superadmin',
            'target_is_actor' => false,
            'status' => AccountStatusService::STATUS_DELETED,
            'label' => 'edge: superadmin deleting admin (allowed by AC 16.6)',
        ];
        // (6) Admin locking an admin — AC 16.6 only restricts deletion of an admin, not locking.
        $cases[] = [
            'target_role' => 'admin',
            'actor_role' => 'admin',
            'target_is_actor' => false,
            'status' => AccountStatusService::STATUS_LOCKED,
            'label' => 'edge: admin locking admin (allowed — AC 16.6 covers delete only)',
        ];
        // (7) Self lock for a non-admin — G1 must still reject regardless of role.
        $cases[] = [
            'target_role' => 'photographer',
            'actor_role' => 'admin',
            'target_is_actor' => true,
            'status' => AccountStatusService::STATUS_LOCKED,
            'label' => 'edge: self lock (photographer) — G1 is role-independent',
        ];
        // (8) Non-admin target deleted by an admin — neither guard applies; transition succeeds.
        $cases[] = [
            'target_role' => 'photographer',
            'actor_role' => 'admin',
            'target_is_actor' => false,
            'status' => AccountStatusService::STATUS_DELETED,
            'label' => 'edge: admin deleting photographer (allowed)',
        ];

        return $cases;
    }

    /**
     * Decide, for the given case, whether either safety guard SHOULD fire. This mirrors the
     * predicate in {@see AccountStatusService::setStatus()} so a discrepancy between the
     * service's behavior and the spec's predicate fails the test.
     */
    private function expectGuard(array $case): bool
    {
        $isSelf = $case['target_is_actor'];
        $status = $case['status'];

        // (G1) AC 16.5 — no self lock / self delete.
        if ($isSelf
            && in_array($status, [AccountStatusService::STATUS_LOCKED, AccountStatusService::STATUS_DELETED], true)) {
            return true;
        }

        // (G2) AC 16.6 — Super_Admin required to delete an admin (only when target ≠ actor; the
        // self case is already covered by G1 because status='deleted' is one of {locked,deleted}).
        if (!$isSelf
            && strtolower($case['target_role']) === 'admin'
            && $status === AccountStatusService::STATUS_DELETED
            && !in_array(strtolower($case['actor_role']), self::SUPER_ADMIN_ROLES, true)) {
            return true;
        }

        return false;
    }

    /**
     * Produce a comparable state snapshot from a fresh `withTrashed()` DB read. The same set of
     * fields is captured before and after each guarded call; a guard that short-circuits MUST
     * leave every captured field bit-for-bit identical.
     */
    private function snapshot(int $userId): array
    {
        $reloaded = User::withTrashed()->find($userId);
        $this->assertNotNull(
            $reloaded,
            "snapshot() requires the user row to exist (id={$userId})"
        );

        return [
            'role' => $reloaded->role,
            'account_status' => $reloaded->account_status,
            'locked_at' => optional($reloaded->locked_at)->toIso8601String(),
            'deleted_at' => optional($reloaded->deleted_at)->toIso8601String(),
            'password_reset_required' => (bool) $reloaded->password_reset_required,
            'trashed' => $reloaded->trashed(),
            'tokens_count' => $reloaded->tokens()->count(),
        ];
    }

    /**
     * The property: for any random (target_role, actor_role, target_is_actor, status), the
     * tuple's classification under the guard predicate determines the observable outcome of
     * {@see AccountStatusService::setStatus()}: a guarded tuple throws AuthorizationException
     * with no state change; an un-guarded tuple succeeds and persists the new status.
     *
     * Validates: Requirements 16.5, 16.6
     */
    public function test_account_lifecycle_safety_guards_universal_property(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache state between iterations so a stale `authz:user:{id}` entry from a
            // prior case never bleeds into this one.
            Cache::flush();

            $context = sprintf(
                'iteration %d (%s; target_role=%s actor_role=%s self=%s status=%s)',
                $i,
                $case['label'],
                $case['target_role'],
                $case['actor_role'],
                $case['target_is_actor'] ? 'true' : 'false',
                $case['status']
            );

            // ----------------------------------------------------------------
            // Setup: fresh active target with the random role. The actor is
            // either the target itself (self case) or a fresh user with the
            // random actor role. A pre-existing token is created on the target
            // so the snapshot can detect any inadvertent invalidateSessions()
            // call inside a guarded transition (the guard short-circuits
            // before invalidateSessions, so the token MUST survive).
            // ----------------------------------------------------------------
            $target = User::factory()->create([
                'role' => $case['target_role'],
                'account_status' => AccountStatusService::STATUS_ACTIVE,
            ]);
            $target->createToken('pre-call');

            if ($case['target_is_actor']) {
                // Use the same instance so AccountStatusService's getKey() === getKey() check
                // resolves the way a self-action through the controller would.
                $actor = $target;
            } else {
                $actor = User::factory()->create([
                    'role' => $case['actor_role'],
                ]);
            }

            $expectGuard = $this->expectGuard($case);

            $before = $this->snapshot($target->getKey());

            if ($expectGuard) {
                // (Guard fires) — assert AuthorizationException AND zero state change.
                $thrown = null;
                try {
                    $this->service->setStatus($target, $case['status'], $actor);
                } catch (AuthorizationException $exception) {
                    $thrown = $exception;
                }

                $this->assertNotNull(
                    $thrown,
                    'AccountStatusService::setStatus MUST throw AuthorizationException ' .
                    "when a safety guard applies for {$context}"
                );

                $after = $this->snapshot($target->getKey());
                $this->assertSame(
                    $before,
                    $after,
                    "Guarded setStatus call MUST NOT mutate persisted state for {$context}; " .
                    'expected snapshot equality before/after the rejected transition'
                );

                // The in-memory model passed in must also be unchanged (no half-applied write
                // before the guard threw).
                $this->assertSame(
                    AccountStatusService::STATUS_ACTIVE,
                    $this->service->currentStatus($target->refresh()),
                    "In-memory currentStatus must remain 'active' after a guarded reject for {$context}"
                );
            } else {
                // (Guard does not fire) — assert successful, persisted transition.
                $returned = $this->service->setStatus($target, $case['status'], $actor);

                $this->assertSame(
                    $case['status'],
                    $this->service->currentStatus($returned),
                    "Successful setStatus MUST reflect the new status on the returned model for {$context}"
                );

                $reloaded = User::withTrashed()->find($target->getKey());
                $this->assertNotNull(
                    $reloaded,
                    "Fresh DB read MUST find the row (soft-deleted or not) for {$context}"
                );
                $this->assertSame(
                    $case['status'],
                    $this->service->currentStatus($reloaded),
                    "Successful setStatus MUST persist the new status to the DB for {$context}"
                );
            }

            // Detach the target/actor from subsequent iterations so case-to-case state never
            // leaks. forceDelete drops trashed and live rows alike.
            User::withTrashed()->whereKey($target->getKey())->forceDelete();
            if (!$case['target_is_actor']) {
                $actor->forceDelete();
            }
        }
    }
}
