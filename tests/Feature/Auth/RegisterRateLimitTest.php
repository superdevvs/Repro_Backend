<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_rate_limited_per_ip_like_login(): void
    {
        Mail::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', [
                'name' => 'Bot',
                'email' => "bot{$i}@example.com",
                'password' => 'password1',
                'password_confirmation' => 'password1',
            ]);
        }

        $this->postJson('/api/register', [
            'name' => 'Bot',
            'email' => 'bot-over@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'auth_rate_limited');
    }
}
