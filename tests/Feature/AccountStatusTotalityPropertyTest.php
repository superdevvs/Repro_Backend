<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 15: Account status is exactly one of three and persists
 *
 * Validates: Requirements 16.1, 16.2, 16.4
 *
 * For any combination of a {@see User}'s `deleted_at` and `locked_at` produced by an arbitrary
 * sequence of {@see AccountStatusService::setStatus()} transitions across the
 * `{active, locked, deleted}` state space, the following sub-properties hold universally:
 *
 *   (T) **Totality (Req 16.1)** — at every observation point, before *and* after each transition,
 *       {@see AccountStatusService::currentStatus()} returns exactly one value drawn from
 *       `{active, locked, deleted}`. There is no "fourth" state and no `null`.
 *
 *   (P) **Persistence (Req 16.4)** — when `setStatus($target, $status, $actor)` returns
 *       successfully, the refreshed model's `currentStatus()` equals `$status`, and a fresh
 *       database read of the same row (via `User::withTrashed()->find($id)`) also yields
 *       `$status`. The new state is durable, not just an in-memory artifact of the call.
 *
 *   (S) **Soft-delete retention (Req 16.2)** — when the transition is `deleted`, the user's row
 *       remains in the `users` table with `deleted_at` set (i.e. soft-deleted via
 *       {@see \Illuminate\Database\Eloquent\SoftDeletes}); the row is *not* hard-deleted, and
 *       `User::withTrashed()->find($id)` continues to find it.
 *
 * Because no PHP property-based-testing library is installed, this test follows the spec's
 * "strong randomization plus deterministic edge cases" approach: 30 randomized sequences of
 * 1-5 transitions per user (drawn from `{active, locked, deleted}` with the only constraint
 * that `deleted → locked` must be routed through `active` — `currentStatus()` derives `deleted`
 * from `trashed()` first, so a direct `deleted → locked` write would not change the observable
 * status and would not be a successful transition under the property), plus the four
 * deterministic edge cases the task calls out (`active→locked→active`, `active→deleted→active`,
 * `active→locked→deleted→active`, and a single transition each).
 *
 * Safety guards (Req 16.5/16.6) are sidestepped uniformly by using a fresh Super_Admin actor
 * per scenario distinct from the target — so self lock/delete never arises (16.5) and an admin
 * target can be deleted (16.6) without restricting the generator.
 */
class AccountStatusTotalityPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized sequences; we run 30 plus the deterministic edge cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Roles drawn from the property's input space (mirrors task 3.5's coverage). */
    private const TARGET_ROLES = ['photographer', 'editor', 'salesRep', 'client', 'admin'];

    /** The complete state space for this property. */
    private const STATUSES = [
        AccountStatusService::STATUS_ACTIVE,
        AccountStatusService::STATUS_LOCKED,
        AccountStatusService::STATUS_DELETED,
    ];

    /** Sequence-length range per scenario. */
    private const MIN_LENGTH = 1;
    private const MAX_LENGTH = 5;

    private AccountStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountStatusService::class);
    }

    /**
     * Build the (role, sequence) generator: 30 randomized scenarios plus the deterministic
     * edge cases the task spells out. Each scenario drives one full property assertion below.
     *
     * @return list<array{role:string, sequence:list<string>, label:string}>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $role = self::TARGET_ROLES[array_rand(self::TARGET_ROLES)];
            $length = mt_rand(self::MIN_LENGTH, self::MAX_LENGTH);
            $sequence = $this->generateValidSequence($length);
            $cases[] = [
                'role' => $role,
                'sequence' => $sequence,
                'label' => "random#{$i} role={$role} seq=[" . implode(',', $sequence) . ']',
            ];
        }

        // Deterministic edge cases — chosen to force interesting transition boundaries.
        // (1) Single-transition cases: each terminal state reached directly from `active`.
        $cases[] = [
            'role' => 'photographer',
            'sequence' => [AccountStatusService::STATUS_LOCKED],
            'label' => 'edge: single active→locked',
        ];
        $cases[] = [
            'role' => 'editor',
            'sequence' => [AccountStatusService::STATUS_DELETED],
            'label' => 'edge: single active→deleted',
        ];
        $cases[] = [
            'role' => 'client',
            'sequence' => [AccountStatusService::STATUS_ACTIVE],
            'label' => 'edge: single active→active (idempotent)',
        ];
        // (2) Restore from locked.
        $cases[] = [
            'role' => 'salesRep',
            'sequence' => [
                AccountStatusService::STATUS_LOCKED,
                AccountStatusService::STATUS_ACTIVE,
            ],
            'label' => 'edge: active→locked→active',
        ];
        // (3) Restore from soft-deleted (round-trip across the SoftDeletes scope).
        $cases[] = [
            'role' => 'photographer',
            'sequence' => [
                AccountStatusService::STATUS_DELETED,
                AccountStatusService::STATUS_ACTIVE,
            ],
            'label' => 'edge: active→deleted→active',
        ];
        // (4) Multi-state path through every state in order.
        $cases[] = [
            'role' => 'editor',
            'sequence' => [
                AccountStatusService::STATUS_LOCKED,
                AccountStatusService::STATUS_DELETED,
                AccountStatusService::STATUS_ACTIVE,
            ],
            'label' => 'edge: active→locked→deleted→active',
        ];
        // (5) Admin target deleted by Super_Admin actor — exercises AC 16.6's allowed branch.
        $cases[] = [
            'role' => 'admin',
            'sequence' => [
                AccountStatusService::STATUS_DELETED,
                AccountStatusService::STATUS_ACTIVE,
            ],
            'label' => 'edge: admin target deleted by superadmin then restored',
        ];

        return $cases;
    }

    /**
     * Generate a random sequence of {@see self::STATUSES} of the given length, with the only
     * constraint that `deleted` is never directly followed by `locked`.
     *
     * Rationale: {@see AccountStatusService::currentStatus()} derives `deleted` from
     * `trashed()` ahead of `locked_at`, so writing `locked_at` on a still-trashed user would
     * not change the observable status — the transition would not be "successful" under the
     * property's own definition. From `deleted`, the only successful next steps are `active`
     * (restore) or `deleted` (idempotent).
     *
     * @return list<string>
     */
    private function generateValidSequence(int $length): array
    {
        $sequence = [];
        // The user always starts in `active`.
        $current = AccountStatusService::STATUS_ACTIVE;

        for ($i = 0; $i < $length; $i++) {
            $candidates = self::STATUSES;
            if ($current === AccountStatusService::STATUS_DELETED) {
                $candidates = [
                    AccountStatusService::STATUS_ACTIVE,
                    AccountStatusService::STATUS_DELETED,
                ];
            }
            $next = $candidates[array_rand($candidates)];
            $sequence[] = $next;
            $current = $next;
        }

        return $sequence;
    }

    /**
     * The property: for any role and any valid sequence of transitions across
     * `{active, locked, deleted}`, `currentStatus()` is always one of the three values
     * (totality), every successful `setStatus()` makes that status observable on the in-memory
     * model *and* on a fresh database read (persistence), and a `deleted` transition retains
     * the row via soft deletion (Req 16.2).
     *
     * Validates: Requirements 16.1, 16.2, 16.4
     */
    public function test_account_status_totality_persistence_and_soft_delete_retention(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache state between iterations so a stale `authz:user:{id}` entry written
            // by `invalidateSessions()` in a prior case never bleeds into this one.
            Cache::flush();

            $contextBase = sprintf(
                'iteration %d (%s)',
                $i,
                $case['label']
            );

            // ----------------------------------------------------------------
            // Setup: a fresh active target with the random role, plus a fresh
            // Super_Admin actor distinct from the target. The Super_Admin role
            // sidesteps both AC 16.5 (target ≠ actor) and AC 16.6 (allowed to
            // delete an admin target) without restricting the generator.
            // ----------------------------------------------------------------
            $target = User::factory()->create([
                'role' => $case['role'],
                'account_status' => AccountStatusService::STATUS_ACTIVE,
            ]);
            $actor = User::factory()->superAdmin()->create();

            // (T) Totality holds at the very first observation point — a freshly created user
            // must derive a value from `{active, locked, deleted}` even before any transition.
            $this->assertSubpropertyTotality(
                $this->service->currentStatus($target),
                "[T] before any transition for {$contextBase}"
            );
            $this->assertSame(
                AccountStatusService::STATUS_ACTIVE,
                $this->service->currentStatus($target),
                "[T] freshly created user must be 'active' for {$contextBase}"
            );

            // ----------------------------------------------------------------
            // Walk the sequence. After every step we re-assert the three sub-
            // properties on both the value returned by setStatus() and an
            // independent fresh database read.
            // ----------------------------------------------------------------
            foreach ($case['sequence'] as $step => $targetStatus) {
                $stepContext = sprintf(
                    'step %d → %s, %s',
                    $step,
                    $targetStatus,
                    $contextBase
                );

                // (T) Totality before the transition (the previous status must still be one
                // of the three values — never a stale or invalid value).
                $this->assertSubpropertyTotality(
                    $this->service->currentStatus($target),
                    "[T] before {$stepContext}"
                );

                // ----- Apply the transition -----
                $returned = $this->service->setStatus($target, $targetStatus, $actor);

                // (P-instance) The model returned by setStatus() reflects the new status.
                $this->assertSame(
                    $targetStatus,
                    $this->service->currentStatus($returned),
                    "[P] currentStatus on the model returned by setStatus() must equal new status for {$stepContext}"
                );

                // (T) Totality after the transition — guaranteed by (P) when (P) holds, but
                // assert explicitly so a regression where currentStatus returns null/'' is
                // caught here rather than only on the equality check.
                $this->assertSubpropertyTotality(
                    $this->service->currentStatus($returned),
                    "[T] after {$stepContext}"
                );

                // (P-fresh-read) An independent fresh database read sees the new status.
                // We always use withTrashed() because an ordinary find() would return null
                // for the soft-deleted case and we want the property to be observable in a
                // single uniform read path across all three statuses.
                $reloaded = User::withTrashed()->find($target->getKey());
                $this->assertNotNull(
                    $reloaded,
                    "[P] fresh DB read MUST find the row (soft-deleted or not) for {$stepContext}"
                );
                $this->assertSame(
                    $targetStatus,
                    $this->service->currentStatus($reloaded),
                    "[P] currentStatus on a fresh DB read must equal new status for {$stepContext}"
                );

                if ($targetStatus === AccountStatusService::STATUS_DELETED) {
                    // (S) Soft-delete retention — the row remains and `deleted_at` is set.
                    $this->assertTrue(
                        $reloaded->trashed(),
                        "[S] row must be soft-deleted (trashed) for {$stepContext}"
                    );
                    $this->assertNotNull(
                        $reloaded->deleted_at,
                        "[S] deleted_at MUST be set on a soft-deleted user for {$stepContext}"
                    );
                    // The default-scoped query MUST NOT find the user (so production reads
                    // see a "deleted" account), but withTrashed() MUST (so the row is
                    // retained, not hard-deleted).
                    $this->assertNull(
                        User::find($target->getKey()),
                        "[S] default-scoped find() must NOT return a soft-deleted user for {$stepContext}"
                    );
                    $this->assertTrue(
                        User::withTrashed()->whereKey($target->getKey())->exists(),
                        "[S] withTrashed()->exists() MUST find the retained row for {$stepContext}"
                    );
                } else {
                    // For active/locked, the user is *not* trashed and is reachable via the
                    // default-scoped query, so the production code path observes the change.
                    $this->assertFalse(
                        $reloaded->trashed(),
                        "[P] user must not be trashed when status={$targetStatus} for {$stepContext}"
                    );
                    $defaultScoped = User::find($target->getKey());
                    $this->assertNotNull(
                        $defaultScoped,
                        "[P] default-scoped find() must return a non-deleted user for {$stepContext}"
                    );
                    $this->assertSame(
                        $targetStatus,
                        $this->service->currentStatus($defaultScoped),
                        "[P] default-scoped currentStatus must equal new status for {$stepContext}"
                    );
                }

                // Carry the freshly-read instance forward so the next step's "before"
                // observation is the same row a production caller would see.
                $target = $reloaded;
            }

            // Detach the target/actor from subsequent iterations so case-to-case state never
            // leaks. forceDelete drops trashed and live rows alike.
            $target->forceDelete();
            $actor->forceDelete();
        }
    }

    /**
     * Assert sub-property (T): the given derived status is exactly one of the three values
     * permitted by Req 16.1. Implemented as a strict membership check so a `null` or empty
     * string would fail loudly even though `assertContains` would otherwise accept it.
     */
    private function assertSubpropertyTotality(string $status, string $context): void
    {
        $this->assertContains(
            $status,
            self::STATUSES,
            "[T] AC 16.1 totality: currentStatus must be exactly one of " .
            implode('|', self::STATUSES) . ", got '{$status}' for {$context}"
        );
    }
}
