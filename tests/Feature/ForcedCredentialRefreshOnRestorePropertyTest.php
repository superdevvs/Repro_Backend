<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 29: Restoring a deleted user forces credential refresh
 *
 * Validates: Requirements 16.8
 *
 * For any User restored from a non-active prior state (deleted or locked) back to active via
 * {@see AccountStatusService::setStatus()} with the {@see AccountStatusService::STATUS_ACTIVE}
 * target, the following universal property holds regardless of how many authentication tokens
 * the account was carrying:
 *
 *   (P1) password_reset_required is true on both the returned model and a fresh
 *        `User::withTrashed()` DB read — a credential refresh is forced before the user can
 *        authenticate normally (AC 16.8).
 *   (P2) ALL previously-issued Sanctum tokens are revoked — tokens()->count() === 0 — so any
 *        lingering session cannot survive the restore (AC 16.8).
 *   (P3) The account is observably active again: not trashed, locked_at is null,
 *        account_status === 'active', and currentStatus() === 'active'.
 *   (P4) A password reset link is dispatched exactly once (the forced-refresh side effect).
 *
 * Because no PHP property-based-testing library is installed, this test follows the repo's
 * established "seeded strong randomization plus deterministic edge cases" approach used by the
 * sibling *PropertyTest.php files: a fixed RNG seed drives 30 randomized
 * {prior_state, token_count, role} cases, plus deterministic edge cases that pin the boundaries
 * (zero tokens, many tokens, each prior state). The same universal property must hold for every
 * generated input.
 *
 * The test is hermetic: the {@see Password} facade is faked so no real mail/broker I/O occurs,
 * and the in-memory sqlite connection (configured in phpunit.xml) backs RefreshDatabase.
 */
class ForcedCredentialRefreshOnRestorePropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases; we run 30 plus deterministic edge cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Fixed seed → deterministic, reproducible case stream across runs. */
    private const RANDOM_SEED = 20160829;

    /** Non-active prior states a restore can originate from (AC 16.8). */
    private const PRIOR_STATES = [
        AccountStatusService::STATUS_DELETED,
        AccountStatusService::STATUS_LOCKED,
    ];

    /** Roles drawn from the property's input space for the target user. */
    private const ROLES = ['photographer', 'editor', 'salesRep', 'client', 'admin'];

    /** Upper bound for the randomized token count carried into the restore. */
    private const MAX_TOKENS = 5;

    private AccountStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountStatusService::class);

        // Hermetic: spy the password broker so the forced reset link triggers no real
        // mail/broker I/O, while still letting us assert the side effect fired (P4).
        Password::spy();
    }

    /**
     * Build the (prior_state, token_count, role) generator: 30 seeded-random cases plus the
     * deterministic edge cases that pin the boundaries of the input space.
     *
     * @return list<array{prior_state:string, token_count:int, role:string, label:string}>
     */
    private function casesGenerator(): array
    {
        mt_srand(self::RANDOM_SEED);

        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $cases[] = [
                'prior_state' => self::PRIOR_STATES[array_rand(self::PRIOR_STATES)],
                'token_count' => mt_rand(0, self::MAX_TOKENS),
                'role' => self::ROLES[array_rand(self::ROLES)],
                'label' => "random#{$i}",
            ];
        }

        // Deterministic edge cases — pin each boundary explicitly.
        // (1) Deleted with zero tokens — nothing to revoke, refresh still forced.
        $cases[] = [
            'prior_state' => AccountStatusService::STATUS_DELETED,
            'token_count' => 0,
            'role' => 'photographer',
            'label' => 'edge: deleted, 0 tokens',
        ];
        // (2) Deleted carrying many lingering tokens — all must be revoked.
        $cases[] = [
            'prior_state' => AccountStatusService::STATUS_DELETED,
            'token_count' => self::MAX_TOKENS,
            'role' => 'client',
            'label' => 'edge: deleted, max tokens',
        ];
        // (3) Locked with zero tokens.
        $cases[] = [
            'prior_state' => AccountStatusService::STATUS_LOCKED,
            'token_count' => 0,
            'role' => 'editor',
            'label' => 'edge: locked, 0 tokens',
        ];
        // (4) Locked carrying many lingering tokens.
        $cases[] = [
            'prior_state' => AccountStatusService::STATUS_LOCKED,
            'token_count' => self::MAX_TOKENS,
            'role' => 'admin',
            'label' => 'edge: locked, max tokens',
        ];

        return $cases;
    }

    /**
     * Place a freshly created (active) user into the requested non-active prior state WITHOUT
     * going through invalidateSessions(), so the tokens created afterwards model "lingering"
     * credentials the restore itself must revoke (rather than tokens an earlier lock/delete
     * already cleared).
     */
    private function placeInPriorState(User $user, string $priorState): void
    {
        if ($priorState === AccountStatusService::STATUS_DELETED) {
            $user->forceFill(['account_status' => AccountStatusService::STATUS_DELETED])->save();
            $user->delete(); // soft delete — row retained, deleted_at set
            return;
        }

        // locked
        $user->forceFill([
            'locked_at' => now(),
            'account_status' => AccountStatusService::STATUS_LOCKED,
        ])->save();
    }

    /**
     * The property: for any (prior_state, token_count, role), restoring the user to active
     * forces a credential refresh (password_reset_required = true) AND revokes every lingering
     * token (count 0), leaving the account observably active.
     *
     * Validates: Requirements 16.8
     */
    public function test_restore_to_active_forces_credential_refresh_and_revokes_all_tokens(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache between iterations so a stale authz entry never bleeds across cases.
            Cache::flush();

            $context = sprintf(
                'iteration %d (%s; prior_state=%s token_count=%d role=%s)',
                $i,
                $case['label'],
                $case['prior_state'],
                $case['token_count'],
                $case['role']
            );

            // Actor authorized to restore. Use a Super_Admin so an admin target can also be
            // exercised without tripping the AC 16.6 delete guard during setup.
            $actor = User::factory()->create(['role' => 'superadmin']);

            $target = User::factory()->create([
                'role' => $case['role'],
                'account_status' => AccountStatusService::STATUS_ACTIVE,
                'password_reset_required' => false,
            ]);

            $this->placeInPriorState($target, $case['prior_state']);

            // Re-resolve through the trashed-inclusive scope so a soft-deleted target is found,
            // mirroring how the controller resolves the row before restoring.
            $target = User::withTrashed()->findOrFail($target->getKey());

            // Issue the randomized number of lingering tokens AFTER entering the prior state.
            for ($t = 0; $t < $case['token_count']; $t++) {
                $target->createToken("lingering-{$t}");
            }
            $this->assertSame(
                $case['token_count'],
                $target->tokens()->count(),
                "Pre-restore token count must match the generated input for {$context}"
            );

            // ---- Act: restore to active. ----
            $restored = $this->service->setStatus($target, AccountStatusService::STATUS_ACTIVE, $actor);

            // (P1) Forced credential refresh on the returned model and a fresh DB read.
            $this->assertTrue(
                (bool) $restored->password_reset_required,
                "Restore MUST force a credential refresh (password_reset_required) for {$context}"
            );
            $reloaded = User::withTrashed()->findOrFail($target->getKey());
            $this->assertTrue(
                (bool) $reloaded->password_reset_required,
                "Forced credential refresh MUST persist to the DB for {$context}"
            );

            // (P2) Every previously-issued token revoked.
            $this->assertSame(
                0,
                $reloaded->tokens()->count(),
                "Restore MUST revoke ALL previously-issued tokens for {$context}"
            );

            // (P3) Account is observably active again.
            $this->assertFalse($restored->trashed(), "Restored user MUST NOT be trashed for {$context}");
            $this->assertNull($restored->locked_at, "Restored user MUST have locked_at cleared for {$context}");
            $this->assertSame(
                AccountStatusService::STATUS_ACTIVE,
                $restored->account_status,
                "Restored user account_status MUST be active for {$context}"
            );
            $this->assertSame(
                AccountStatusService::STATUS_ACTIVE,
                $this->service->currentStatus($reloaded),
                "currentStatus MUST report active after restore for {$context}"
            );

            // Clean up so case-to-case state never leaks.
            User::withTrashed()->whereKey($target->getKey())->forceDelete();
            $actor->forceDelete();
        }

        // (P4) The forced password-reset link side effect fired for every restored user.
        Password::shouldHaveReceived('sendResetLink')->times(count($this->casesGenerator()));
    }
}
