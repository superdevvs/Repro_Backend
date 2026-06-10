<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 30: Token authentication includes soft-deleted users
 *
 * Validates: Requirements 17.5
 *
 * For any soft-deleted User presenting a previously issued token, token authentication includes the
 * soft-deleted User in the lookup and returns an unauthorized (401) response rather than treating
 * the User as absent. This is the auth-resolution counterpart to Property 16: here the token is
 * deliberately left alive (the user is soft-deleted with model events disabled so the revoke-on-
 * delete hook does NOT fire) so the assertions exercise the {@see PersonalAccessToken::tokenable()}
 * `withTrashed()` resolution path and the {@see \App\Http\Middleware\EnsureAuthenticatedUserIsActive}
 * 401 rejection — NOT the side effect of token revocation.
 *
 * Concretely, for arbitrary (role, pre-delete account_status, token-count) tuples this asserts:
 *
 *   (A) Baseline — before deletion every previously issued bearer token authenticates against the
 *       protected `/api/user` route (so a post-delete 401 is caused by the soft-delete, not setup).
 *
 *   (B) After a soft-delete performed with model events disabled, every issued token row still
 *       exists (the revoke hook did not run) — the precondition for exercising the lookup path.
 *
 *   (C) Each surviving token RESOLVES to the soft-deleted user via the hardened `tokenable()`
 *       relation: `$token->tokenable` is the trashed User (non-null, matching id, `trashed()`),
 *       i.e. the lookup INCLUDES the soft-deleted user rather than treating it as absent (Req 17.5).
 *
 *   (D) A subsequent request bearing each surviving token receives HTTP 401 with the explicit
 *       "no longer active" message — unauthorized, not a generic "absent user" response (Req 17.5).
 *
 * Because no PHP property-based-testing library is installed, this follows the spec's deterministic
 * "strong randomization plus edge cases" approach with a SEEDED PRNG: 30 randomized cases plus
 * deterministic edge cases. The seed makes failures reproducible.
 */
class SoftDeletedTokenAuthenticationPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases; we run 30 plus deterministic edge cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Fixed seed so the generated sequence is deterministic and any failure reproduces exactly. */
    private const SEED = 173005;

    /** Roles drawn from the property's input space. */
    private const ROLES = ['photographer', 'editor', 'salesRep', 'client', 'admin', 'superadmin'];

    /**
     * Account-status strings the user may carry BEFORE being soft-deleted. The soft-delete itself
     * (trashed state) is what must drive resolution + rejection, regardless of this value.
     */
    private const PRE_DELETE_STATUSES = ['active', 'locked', 'deleted'];

    /**
     * Build the (role, pre-delete status, token-count) generator using a seeded PRNG: 30 randomized
     * cases plus deterministic edge cases. Each tuple drives one full property assertion below.
     *
     * @return list<array{role:string, status:string, tokens:int, label:string}>
     */
    private function casesGenerator(): array
    {
        mt_srand(self::SEED);

        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $role = self::ROLES[mt_rand(0, count(self::ROLES) - 1)];
            $status = self::PRE_DELETE_STATUSES[mt_rand(0, count(self::PRE_DELETE_STATUSES) - 1)];
            // At least one token: a soft-deleted user with no token has nothing to resolve.
            $tokens = mt_rand(1, 5);

            $cases[] = [
                'role' => $role,
                'status' => $status,
                'tokens' => $tokens,
                'label' => "random#{$i} role={$role} preStatus={$status} tokens={$tokens}",
            ];
        }

        // Deterministic edge cases — interesting boundaries.
        // Single token, previously active: the canonical Req 17.5 case.
        $cases[] = ['role' => 'photographer', 'status' => 'active',  'tokens' => 1, 'label' => 'edge: single token, was active'];
        // Maximum tokens: every token row must resolve and be rejected, not just the latest.
        $cases[] = ['role' => 'client',       'status' => 'active',  'tokens' => 5, 'label' => 'edge: many tokens, was active'];
        // Already-"locked" status string before delete: trashed state still drives resolution.
        $cases[] = ['role' => 'editor',       'status' => 'locked',  'tokens' => 2, 'label' => 'edge: was locked then deleted'];
        // Admin role: privileged role is treated identically once soft-deleted.
        $cases[] = ['role' => 'admin',        'status' => 'active',  'tokens' => 3, 'label' => 'edge: admin then deleted'];

        return $cases;
    }

    /**
     * The property: for any random (role, pre-delete status, token-count), soft-deleting the user
     * with model events disabled leaves the tokens alive; each surviving token resolves to the
     * trashed user via the hardened `tokenable()` relation, and any request bearing it is rejected
     * with HTTP 401 rather than treated as an absent user.
     *
     * Validates: Requirements 17.5
     */
    public function test_soft_deleted_user_tokens_resolve_and_are_rejected_with_unauthorized(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            $context = sprintf('iteration %d (%s)', $i, $case['label']);

            // ----------------------------------------------------------------
            // Setup: fresh user with the random role + pre-delete status and N
            // pre-issued tokens. Capture both the plain-text strings (for the
            // auth path) and the token ids (to inspect resolution directly).
            // ----------------------------------------------------------------
            $user = User::factory()->create([
                'role' => $case['role'],
                'account_status' => $case['status'],
            ]);

            $plainTokens = [];
            $tokenIds = [];
            for ($t = 0; $t < $case['tokens']; $t++) {
                $plain = $user->createToken("device-{$t}")->plainTextToken;
                $plainTokens[] = $plain;
                $tokenIds[] = (int) explode('|', $plain, 2)[0];
            }

            // (A) Baseline: a previously active user authenticates; for a non-active pre-delete
            //     status the user is already ineligible, so we only assert the baseline OK path
            //     when the user starts eligible. This proves the post-delete 401 is caused by the
            //     soft-delete for the active-start cases.
            if ($case['status'] === 'active') {
                foreach ($plainTokens as $plain) {
                    Auth::forgetGuards();
                    $this->withHeader('Authorization', 'Bearer ' . $plain)
                        ->getJson('/api/user')
                        ->assertOk()
                        ->assertJsonPath('id', $user->id);
                }
            }

            // ----------------------------------------------------------------
            // Soft-delete WITHOUT firing model events so the revoke-on-delete
            // hook does not run and the tokens survive. This isolates the
            // authentication LOOKUP (Req 17.5) rather than token revocation.
            // ----------------------------------------------------------------
            User::withoutEvents(function () use ($user) {
                $user->delete();
            });

            $this->assertTrue(
                User::withTrashed()->find($user->id)?->trashed() === true,
                "Setup: user must be soft-deleted (trashed) for {$context}"
            );
            $this->assertNull(
                User::find($user->id),
                "Setup: default-scoped find() must NOT return the soft-deleted user for {$context}"
            );

            foreach ($tokenIds as $idx => $tokenId) {
                // (B) The token row still exists — the revoke hook did not fire.
                $token = PersonalAccessToken::find($tokenId);
                $this->assertNotNull(
                    $token,
                    "[B] Token #{$idx} must still exist after an events-disabled soft-delete for {$context}"
                );

                // (C) The token RESOLVES to the soft-deleted user via withTrashed() — the lookup
                //     INCLUDES the trashed user rather than treating it as absent (Req 17.5).
                $tokenable = $token->tokenable;
                $this->assertNotNull(
                    $tokenable,
                    "[C] tokenable() MUST resolve the soft-deleted user (withTrashed) for {$context}"
                );
                $this->assertInstanceOf(User::class, $tokenable);
                $this->assertSame(
                    $user->id,
                    $tokenable->id,
                    "[C] Resolved tokenable must be the same soft-deleted user for {$context}"
                );
                $this->assertTrue(
                    $tokenable->trashed(),
                    "[C] Resolved tokenable must itself be trashed for {$context}"
                );
            }

            // ----------------------------------------------------------------
            // (D) A subsequent request bearing each surviving token is rejected
            //     with HTTP 401 and the explicit "no longer active" message —
            //     unauthorized, not an absent/unknown-user response (Req 17.5).
            // ----------------------------------------------------------------
            foreach ($plainTokens as $idx => $plain) {
                Auth::forgetGuards();
                $response = $this->withHeader('Authorization', 'Bearer ' . $plain)
                    ->getJson('/api/user');

                $response
                    ->assertStatus(401)
                    ->assertJson(['message' => 'This account is no longer active.']);

                $this->assertNotEquals(
                    200,
                    $response->getStatusCode(),
                    "[D] Token #{$idx} for the soft-deleted user MUST NOT authenticate for {$context}"
                );
            }

            // Drop the row entirely so ids cannot collide across iterations.
            $user->forceDelete();
        }
    }
}
