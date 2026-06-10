<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $password = 'Str0ngPass!';

        $user = User::factory()->create([
            'email' => 'login-test@example.com',
            'password' => Hash::make($password),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'account_status',
                ],
            ]);
    }

    public function test_user_cannot_log_in_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'wrong-pass@example.com',
            'password' => Hash::make('Correct123!'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Invalid credentials',
            ]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $password = 'Str0ngPass!';

        $user = User::factory()->create([
            'email' => 'inactive-login@example.com',
            'password' => Hash::make($password),
            'account_status' => 'inactive',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'This account is no longer active.',
            ]);
    }

    public function test_soft_deleted_user_cannot_log_in(): void
    {
        $password = 'Str0ngPass!';

        $user = User::factory()->create([
            'email' => 'deleted-login@example.com',
            'password' => Hash::make($password),
        ]);
        $user->delete();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'This account is no longer active.',
            ]);
    }

    public function test_inactive_token_authenticated_request_is_rejected_and_token_is_revoked(): void
    {
        $user = User::factory()->create([
            'account_status' => 'active',
        ]);
        $token = $user->createToken('stale-token')->plainTextToken;
        $tokenId = (int) explode('|', $token, 2)[0];

        User::withoutEvents(function () use ($user) {
            $user->forceFill(['account_status' => 'suspended'])->save();
        });

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'This account is no longer active.',
            ]);

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_admin_status_deactivation_revokes_existing_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['account_status' => 'active']);
        $targetToken = $target->createToken('target-token')->plainTextToken;
        $targetTokenId = (int) explode('|', $targetToken, 2)[0];
        $adminToken = $admin->createToken('admin-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson('/api/admin/users/' . $target->id, [
                'account_status' => 'suspended',
            ])
            ->assertOk();

        $this->assertSame('suspended', $target->fresh()->account_status);
        $this->assertNull(PersonalAccessToken::find($targetTokenId));
    }
}

