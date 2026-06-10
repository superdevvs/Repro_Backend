<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 16: Locked or deleted users are denied access immediately
 *
 * Validates: Requirements 16.3, 17.1, 17.2, 17.3, 17.4
 *
 * For any user set to locked or deleted via {@see AccountStatusService::setStatus()}, session
 * invalidation occurs **within the same request** — revoking that user's authentication tokens
 * and cached authorization data — and any subsequent request bearing a previously issued token is
 * rejected with HTTP 401, including for soft-deleted users (whose token still resolves via the
 * hardened {@see \App\Models\PersonalAccessToken::tokenable()} relation).
 *
 * Concretely, this test asserts the following universal sub-properties for arbitrary
 * (target-role, pre-token-count, transition) tuples:
 *
 *   (A) Before the transition, every previously issued token authenticates against the
 *       protected `/api/user` route (baseline: tokens are usable).
 *
 *   (B) When `AccountStatusService::setStatus()` returns from a `locked` or `deleted`
 *       transition, the target user has zero remaining personal-access tokens (Req 17.1, 17.2,
 *       17.3 — same-request revocation).
 *
 *   (C) When `AccountStatusService::setStatus()` returns, the target user's cached authorization
 *       entry (`authz:user:{id}`) is gone (Req 17.3 — cached authorization invalidated).
 *
 *   (D) After the transition, every previously issued bearer token receives an HTTP 401
 *       response from the protected `/api/user` route (Req 16.3, 17.4 — locked/deleted users
 *       cannot continue an active session).
 *
 * Because no PHP property-based-testing library is installed, this test follows the spec's
 * "strong randomization plus deterministic edge cases" approach: 30 randomized
 * {role, num_pre_tokens, transition} cases plus deterministic edge cases (zero pre-tokens, the
 * maximum pre-token count, and an admin target deleted by a Super_Admin actor). The same
 * universal property must hold for every generated input.
 */
class LockOrDeleteAccessDeniedPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases; we run 30 plus 6 deterministic edge cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Roles drawn from the property's input space. */
    private const TARGET_ROLES = ['photographer', 'editor', 'salesRep', 'client', 'admin'];

    /** The two transitions whose immediacy this property tests. */
    private const TRANSITIONS = [
        AccountStatusService::STATUS_LOCKED,
        AccountStatusService::STATUS_DELETED,
    ];

    private AccountStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountStatusService::class);
    }

    /**
     * Build the (role, num_pre_tokens, transition) generator: 30 randomized cases plus 6
     * deterministic edge cases. Each tuple drives one full property assertion below.
     *
     * @return list<array{role:string, tokens:int, status:string, label:string}>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $role = self::TARGET_ROLES[array_rand(self::TARGET_ROLES)];
            $cases[] = [
                'role' => $role,
                'tokens' => mt_rand(0, 5),
                'status' => self::TRANSITIONS[array_rand(self::TRANSITIONS)],
                'label' => "random#{$i} role={$role}",
            ];
        }

        // Deterministic edge cases — chosen to force interesting boundaries.
        // Zero pre-tokens: revocation must still be safe and the cache + middleware path must hold.
        $cases[] = ['role' => 'photographer', 'tokens' => 0, 'status' => 'locked',  'label' => 'edge: zero tokens, lock'];
        $cases[] = ['role' => 'editor',       'tokens' => 0, 'status' => 'deleted', 'label' => 'edge: zero tokens, delete'];
        // Many pre-tokens: ensures the implementation revokes every token, not just the latest.
        $cases[] = ['role' => 'client',       'tokens' => 5, 'status' => 'locked',  'label' => 'edge: many tokens, lock'];
        $cases[] = ['role' => 'salesRep',     'tokens' => 5, 'status' => 'deleted', 'label' => 'edge: many tokens, delete'];
        // Admin target locked by an admin (allowed; only admin DELETE requires Super_Admin per AC 16.6).
        $cases[] = ['role' => 'admin',        'tokens' => 3, 'status' => 'locked',  'label' => 'edge: admin target locked by admin'];
        // Admin target deleted by a Super_Admin actor (the only path that satisfies AC 16.6).
        $cases[] = ['role' => 'admin',        'tokens' => 3, 'status' => 'deleted', 'label' => 'edge: admin target deleted by superadmin'];

        return $cases;
    }

    /**
     * Pick an actor authorized to apply the given transition to a target with the given role.
     * AC 16.6: a non-Super_Admin admin cannot delete an admin account; otherwise an admin actor
     * is sufficient. AC 16.5 is automatically satisfied because the actor is a fresh user.
     */
    private function actorFor(string $targetRole, string $status): User
    {
        if ($status === AccountStatusService::STATUS_DELETED && strtolower($targetRole) === 'admin') {
            return User::factory()->create(['role' => 'superadmin']);
        }
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * The property: for any random (target-role, pre-token-count, lock|delete), setting the
     * target's account status to that transition immediately revokes their tokens and cached
     * authorization within the same request, and a subsequent request with any previously
     * issued token is rejected with HTTP 401.
     *
     * Validates: Requirements 16.3, 17.1, 17.2, 17.3, 17.4
     */
    public function test_locked_or_deleted_users_are_denied_access_immediately(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache state between iterations so a stale authz key from a prior case never
            // satisfies (or contradicts) the assertion below.
            Cache::flush();

            $context = sprintf(
                'iteration %d (%s, tokens=%d, status=%s)',
                $i,
                $case['label'],
                $case['tokens'],
                $case['status']
            );

            // ----------------------------------------------------------------
            // Setup: fresh active user with the random role + N pre-issued
            // tokens. We capture the plain-text token strings so we can later
            // exercise the actual auth path the way a stale client would.
            // ----------------------------------------------------------------
            $target = User::factory()->create([
                'role' => $case['role'],
                'account_status' => AccountStatusService::STATUS_ACTIVE,
            ]);

            $plainTokens = [];
            for ($t = 0; $t < $case['tokens']; $t++) {
                $plainTokens[] = $target->createToken("device-{$t}")->plainTextToken;
            }

            $this->assertSame(
                $case['tokens'],
                PersonalAccessToken::where('tokenable_id', $target->id)
                    ->where('tokenable_type', User::class)
                    ->count(),
                "Setup: target should have {$case['tokens']} tokens before the transition for {$context}"
            );

            // Seed the cached-authorization entry so we can detect that
            // setStatus() clears it as part of session invalidation (AC 17.3).
            Cache::put("authz:user:{$target->id}", ['cached'], 300);
            $this->assertNotNull(
                Cache::get("authz:user:{$target->id}"),
                "Setup: authz cache for target should be primed before the transition for {$context}"
            );

            // ----------------------------------------------------------------
            // (A) Baseline: every previously issued bearer token authenticates
            //     against /api/user before the transition. This proves the
            //     401s observed after the transition are caused by the
            //     transition itself, not by a malformed test setup. We flush
            //     the AuthManager guard cache before every HTTP call so the
            //     Sanctum guard does not carry a previously resolved user
            //     across requests inside the same test method (its
            //     RequestGuard memoizes `$user` per instance, and the
            //     Application reuses the guard instance across `getJson` calls).
            // ----------------------------------------------------------------
            foreach ($plainTokens as $idx => $plain) {
                Auth::forgetGuards();
                $this->withHeader('Authorization', 'Bearer ' . $plain)
                    ->getJson('/api/user')
                    ->assertOk()
                    ->assertJsonPath('id', $target->id);
            }

            // ----------------------------------------------------------------
            // (B + C) Apply the transition. The implementation MUST revoke
            //     tokens and clear cached authorization within this single
            //     synchronous call (Req 17.1, 17.2, 17.3).
            // ----------------------------------------------------------------
            $actor = $this->actorFor($case['role'], $case['status']);
            $this->service->setStatus($target, $case['status'], $actor);

            // (B) Tokens are revoked within the same request.
            $this->assertSame(
                0,
                PersonalAccessToken::where('tokenable_id', $target->id)
                    ->where('tokenable_type', User::class)
                    ->count(),
                "[B] All previously-issued tokens MUST be revoked synchronously by setStatus() for {$context}"
            );

            // (C) Cached authorization data is cleared.
            $this->assertNull(
                Cache::get("authz:user:{$target->id}"),
                "[C] Cached authorization (authz:user:{$target->id}) MUST be cleared by setStatus() for {$context}"
            );

            // ----------------------------------------------------------------
            // (D) Subsequent request with each previously issued token is
            //     rejected with HTTP 401 (Req 16.3, 17.4). For soft-deleted
            //     targets this also exercises Req 17.5 (token resolution
            //     includes trashed users) — without `withTrashed()` the
            //     middleware would return a generic unauthenticated, but
            //     either way the response code must be 401.
            // ----------------------------------------------------------------
            foreach ($plainTokens as $idx => $plain) {
                Auth::forgetGuards();
                $response = $this->withHeader('Authorization', 'Bearer ' . $plain)
                    ->getJson('/api/user');
                $response->assertStatus(401);
                $this->assertNotEquals(
                    200,
                    $response->getStatusCode(),
                    "[D] Token #{$idx} previously issued to the {$case['status']} user MUST NOT authenticate for {$context}"
                );
            }

            // Detach the target from subsequent iterations so case-to-case
            // state never leaks. For soft-deleted targets the row remains
            // (intentionally — AC 16.2), but it carries no tokens or cache;
            // forceDelete drops the row entirely so user_id values cannot
            // collide across iterations.
            $target->forceDelete();
            $actor->forceDelete();
        }
    }
}
