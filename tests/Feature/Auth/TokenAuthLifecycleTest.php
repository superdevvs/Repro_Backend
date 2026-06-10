<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Hardened token authentication for the account lifecycle (Req 16.3, 17.4, 17.5).
 *
 * These cover the auth-layer behavior in isolation: tokens are left in place (events disabled so
 * the model's revoke-on-change hooks do not fire) so the assertions exercise the token resolution
 * + eligibility/middleware path rather than the side effect of token revocation.
 */
class TokenAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deleted_user_token_is_resolved_and_rejected_with_unauthorized(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $token = $user->createToken('mobile')->plainTextToken;
        $tokenId = (int) explode('|', $token, 2)[0];

        // Soft-delete without firing model events so the token is NOT auto-revoked. This isolates
        // the authentication lookup: the token still exists and points at a trashed user.
        User::withoutEvents(function () use ($user) {
            $user->delete();
        });

        // The token row still exists (it was not revoked) ...
        $this->assertNotNull(PersonalAccessToken::find($tokenId));

        // ... and the soft-deleted user must be FOUND during token auth and rejected with 401,
        // rather than treated as an absent/unknown user (Req 17.5).
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'This account is no longer active.',
            ]);
    }

    public function test_locked_user_token_is_rejected_with_unauthorized(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $token = $user->createToken('mobile')->plainTextToken;

        // Apply the locked state without firing events so the token survives; the eligibility check
        // (and middleware) must still deny the request (Req 16.3, 17.4).
        User::withoutEvents(function () use ($user) {
            $user->forceFill([
                'locked_at' => now(),
                'account_status' => 'locked',
            ])->save();
        });

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'This account is no longer active.',
            ]);
    }

    public function test_locked_state_is_denied_even_when_status_string_is_active(): void
    {
        // Defense in depth: a stale/incorrect account_status string must not re-enable a locked
        // account. The explicit locked_at check denies authentication.
        $user = User::factory()->create(['account_status' => 'active']);

        User::withoutEvents(function () use ($user) {
            $user->forceFill([
                'locked_at' => now(),
                'account_status' => 'active',
            ])->save();
        });

        $this->assertFalse($user->fresh()->isAccountEligibleForAuthentication());
    }

    public function test_active_user_token_is_accepted(): void
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertOk();
    }
}
