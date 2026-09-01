<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MailService;
use App\Services\Users\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enrol_in_real_totp_and_login_requires_a_valid_code(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create([
            'email' => 'two-factor@example.com',
            'password' => Hash::make($password),
        ]);
        $token = $user->createToken('current-browser')->plainTextToken;
        $otherToken = $user->createToken('other-browser')->accessToken;

        $setup = $this->withToken($token)
            ->postJson('/api/profile/security/two-factor/setup', [
                'current_password' => $password,
            ])
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri', 'expires_in_seconds'])
            ->json();

        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($setup['secret']);

        $recoveryCodes = $this->withToken($token)
            ->postJson('/api/profile/security/two-factor/confirm', [
                'current_password' => $password,
                'code' => $code,
            ])
            ->assertOk()
            ->assertJsonPath('revoked_sessions', 1)
            ->assertJsonCount(8, 'recovery_codes')
            ->json('recovery_codes');

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser->two_factor_confirmed_at);
        $this->assertNotNull($freshUser->two_factor_last_used_step);
        $this->assertNotSame($recoveryCodes, $freshUser->two_factor_recovery_codes);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->id]);
        $this->assertSame(1, $freshUser->tokens()->count());
        $this->assertFalse(app(TwoFactorAuthenticationService::class)->verifyUserCode($freshUser, $code));

        $replacementCodes = $this->withToken($token)
            ->postJson('/api/profile/security/two-factor/recovery-codes', [
                'current_password' => $password,
                'code' => $recoveryCodes[0],
            ])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes')
            ->json('recovery_codes');

        $this->assertFalse(app(TwoFactorAuthenticationService::class)->verifyUserCode($user->fresh(), $recoveryCodes[0], false));
        $this->assertTrue(app(TwoFactorAuthenticationService::class)->verifyUserCode($user->fresh(), $replacementCodes[0], false));

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
            'two_factor_code' => '000000',
        ])->assertUnprocessable();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
            'two_factor_code' => $replacementCodes[0],
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'two_factor_enabled',
        ]);
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'login',
        ]);
    }

    public function test_security_status_and_session_revocation_are_scoped_to_the_user(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $otherUser = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('tablet')->plainTextToken;
        $foreign = $otherUser->createToken('foreign')->plainTextToken;
        $otherId = explode('|', $other, 2)[0];
        $foreignId = explode('|', $foreign, 2)[0];

        $this->withToken($current)
            ->getJson('/api/profile/security')
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('two_factor.enabled', false);

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/'.$otherId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/'.$foreignId, [
                'current_password' => $password,
            ])
            ->assertNotFound();

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/'.$otherId, [
                'current_password' => 'wrong',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherId]);

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/'.$otherId, [
                'current_password' => $password,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreignId]);

        $user->createToken('phone');
        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/others', ['current_password' => 'wrong'])
            ->assertUnprocessable();

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/others', ['current_password' => $password])
            ->assertOk()
            ->assertJsonPath('revoked_count', 1);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_profile_activity_is_self_only_and_sanitized(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        UserActivityLog::record($user, 'profile_updated', 'Profile updated', 'Preferences changed.', null, [
            'ip_address' => '203.0.113.4',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'secret_payload' => 'must-not-leak',
        ]);
        UserActivityLog::record($other, 'profile_updated', 'Other profile updated', 'Not visible.');
        $token = $user->createToken('current')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/profile/activity')
            ->assertOk()
            ->assertJsonCount(1, 'activities')
            ->assertJsonPath('activities.0.title', 'Profile updated')
            ->assertJsonPath('activities.0.ip_address', '203.0.113.4')
            ->assertJsonPath('activities.0.device', 'Chrome on Windows')
            ->assertJsonMissingPath('activities.0.metadata')
            ->assertDontSee('must-not-leak')
            ->assertDontSee('Other profile updated');
    }

    public function test_password_change_revokes_every_session_and_records_the_change(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('stale-reset-token'),
            'created_at' => now(),
        ]);

        $this->withToken($current)
            ->putJson('/api/profile', [
                'current_password' => $password,
                'new_password' => 'Updated456!',
                'new_password_confirmation' => 'Updated456!',
            ])
            ->assertOk()
            ->assertJsonPath('reauth_required', true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->password_changed_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'password_changed',
        ]);
    }

    public function test_two_factor_disable_revokes_other_sessions_and_keeps_the_current_session(): void
    {
        $password = 'Secret123!';
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'password' => Hash::make($password),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ]);
        $current = $user->createToken('current');
        $other = $user->createToken('other')->accessToken;
        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($secret);

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/profile/security/two-factor', [
                'current_password' => $password,
                'code' => $code,
            ])
            ->assertOk()
            ->assertJsonPath('revoked_sessions', 1);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_used_step);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->id]);
    }

    public function test_two_factor_disable_rolls_back_if_other_sessions_cannot_be_revoked(): void
    {
        $password = 'Secret123!';
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = User::factory()->create([
            'password' => Hash::make($password),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ]);
        $current = $user->createToken('current');
        $user->createToken('other');
        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($secret);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_two_factor_disable_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced token deletion failure');
            END
        SQL);

        try {
            $this->withToken($current->plainTextToken)
                ->deleteJson('/api/profile/security/two-factor', [
                    'current_password' => $password,
                    'code' => $code,
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_two_factor_disable_token_delete');
        }

        $user->refresh();
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertSame([], $user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_used_step);
        $this->assertSame(2, $user->tokens()->count());
        $this->assertDatabaseMissing('user_activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'two_factor_disabled',
        ]);
    }

    public function test_an_accepted_totp_step_cannot_be_replayed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00 UTC'));

        try {
            $password = 'Secret123!';
            $secret = 'JBSWY3DPEHPK3PXP';
            $user = User::factory()->create([
                'email' => 'totp-replay@example.com',
                'password' => Hash::make($password),
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => [],
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => null,
            ]);
            $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($secret);
            $expectedStep = intdiv(now()->getTimestamp(), 30);

            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => $password,
                'two_factor_code' => $code,
            ])->assertOk()
                ->assertJsonMissingPath('user.two_factor_last_used_step');

            $this->assertSame($expectedStep, $user->fresh()->two_factor_last_used_step);

            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => $password,
                'two_factor_code' => $code,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('two_factor_code');

            $this->assertSame($expectedStep, $user->fresh()->two_factor_last_used_step);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_two_factor_confirmation_rolls_back_if_other_sessions_cannot_be_revoked(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');
        $setup = $this->withToken($current)
            ->postJson('/api/profile/security/two-factor/setup', [
                'current_password' => $password,
            ])
            ->assertOk()
            ->json();
        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($setup['secret']);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_two_factor_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced token deletion failure');
            END
        SQL);

        try {
            $this->withToken($current)
                ->postJson('/api/profile/security/two-factor/confirm', [
                    'current_password' => $password,
                    'code' => $code,
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_two_factor_token_delete');
        }

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_used_step);
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_both_session_revocation_routes_are_throttled(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create([
            'id' => 50001,
            'password' => Hash::make($password),
        ]);
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->accessToken;

        foreach (range(1, 5) as $attempt) {
            $this->withToken($current)
                ->deleteJson('/api/profile/security/sessions/'.$other->id, [
                    'current_password' => 'wrong',
                ])
                ->assertUnprocessable();
        }

        foreach (range(1, 5) as $attempt) {
            $this->withToken($current)
                ->deleteJson('/api/profile/security/sessions/others', [
                    'current_password' => 'wrong',
                ])
                ->assertUnprocessable();
        }

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/'.$other->id, [
                'current_password' => 'wrong',
            ])
            ->assertStatus(429);

        $this->withToken($current)
            ->deleteJson('/api/profile/security/sessions/others', [
                'current_password' => 'wrong',
            ])
            ->assertStatus(429);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->id]);
    }

    public function test_profile_activity_is_blocked_while_impersonating(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        UserActivityLog::record(
            $target,
            'login',
            'Sensitive session history',
            'This activity must not be exposed through impersonation.',
            null,
            ['ip_address' => '203.0.113.44'],
        );

        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $target->id)
            ->getJson('/api/profile/activity')
            ->assertForbidden()
            ->assertDontSee('Sensitive session history')
            ->assertDontSee('203.0.113.44');
    }

    public function test_logout_revokes_the_current_token_even_when_audit_storage_fails(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('current');
        $tokenId = $token->accessToken->id;
        $failAuditWrites = true;
        UserActivityLog::creating(function () use (&$failAuditWrites): void {
            if ($failAuditWrites) {
                throw new \RuntimeException('Audit storage is unavailable.');
            }
        });

        try {
            $this->withToken($token->plainTextToken)
                ->postJson('/api/logout')
                ->assertOk()
                ->assertJsonPath('message', 'Logged out successfully');
        } finally {
            $failAuditWrites = false;
        }

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_session_revocation_succeeds_when_security_audit_storage_fails(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->accessToken;
        $failAuditWrites = true;
        UserActivityLog::creating(function () use (&$failAuditWrites): void {
            if ($failAuditWrites) {
                throw new \RuntimeException('Audit storage is unavailable.');
            }
        });

        try {
            $this->withToken($current)
                ->deleteJson('/api/profile/security/sessions/'.$other->id, [
                    'current_password' => $password,
                ])
                ->assertOk();
        } finally {
            $failAuditWrites = false;
        }

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->id]);
    }

    public function test_profile_password_change_revokes_tokens_before_audit_and_mail_side_effects(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create([
            'email' => 'profile-security-old@example.com',
            'password' => Hash::make($password),
        ]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');
        $failAuditWrites = true;
        UserActivityLog::creating(function () use (&$failAuditWrites): void {
            if ($failAuditWrites) {
                throw new \RuntimeException('Audit storage is unavailable.');
            }
        });
        $this->partialMock(MailService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendClientEmailVerificationEmail')
                ->once()
                ->andThrow(new \RuntimeException('Mail provider is unavailable.'));
        });

        try {
            $this->withToken($current)
                ->putJson('/api/profile', [
                    'email' => 'profile-security-new@example.com',
                    'current_password' => $password,
                    'new_password' => 'Updated456!',
                    'new_password_confirmation' => 'Updated456!',
                ])
                ->assertOk()
                ->assertJsonPath('reauth_required', true);
        } finally {
            $failAuditWrites = false;
        }

        $user->refresh();
        $this->assertSame('profile-security-new@example.com', $user->email);
        $this->assertTrue(Hash::check('Updated456!', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_profile_password_change_rolls_back_when_token_deletion_fails(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_profile_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced token deletion failure');
            END
        SQL);

        try {
            $this->withToken($current)
                ->putJson('/api/profile', [
                    'current_password' => $password,
                    'new_password' => 'Updated456!',
                    'new_password_confirmation' => 'Updated456!',
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_profile_token_delete');
        }

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('Updated456!', $user->password));
        $this->assertNull($user->password_changed_at);
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_profile_password_change_rolls_back_when_reset_link_deletion_fails(): void
    {
        $password = 'Secret123!';
        $user = User::factory()->create([
            'email' => 'profile-reset-link-atomicity@example.com',
            'password' => Hash::make($password),
        ]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('stale-reset-token'),
            'created_at' => now(),
        ]);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_profile_reset_link_delete
            BEFORE DELETE ON password_reset_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced reset link deletion failure');
            END
        SQL);

        try {
            $this->withToken($current)
                ->putJson('/api/profile', [
                    'current_password' => $password,
                    'new_password' => 'Updated456!',
                    'new_password_confirmation' => 'Updated456!',
                ])
                ->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_profile_reset_link_delete');
        }

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('Updated456!', $user->password));
        $this->assertNull($user->password_changed_at);
        $this->assertSame(2, $user->tokens()->count());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('user_activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'password_changed',
        ]);
    }

    public function test_emailed_password_reset_rolls_back_when_token_deletion_fails(): void
    {
        $password = 'Secret123!';
        $resetToken = 'valid-reset-token';
        $user = User::factory()->create([
            'email' => 'reset-atomicity@example.com',
            'password' => Hash::make($password),
        ]);
        $user->createToken('existing-session');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_emailed_reset_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'forced token deletion failure');
            END
        SQL);

        try {
            $this->postJson('/api/password/reset', [
                'email' => $user->email,
                'token' => $resetToken,
                'password' => 'Updated456!',
                'password_confirmation' => 'Updated456!',
            ])->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_emailed_reset_token_delete');
        }

        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('Updated456!', $user->password));
        $this->assertNull($user->password_changed_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
