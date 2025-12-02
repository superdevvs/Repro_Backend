<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}

