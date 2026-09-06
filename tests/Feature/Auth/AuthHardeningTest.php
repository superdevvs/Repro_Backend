<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\MailService;
use App\Services\Users\AccountSecurityMutation;
use App\Services\Users\EmailHealthService;
use App\Services\Users\EmailVerificationPilot;
use App\Services\Users\PasswordRecoveryService;
use App\Services\Users\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_email_payloads_are_validated_and_still_consume_ip_budget(): void
    {
        $malformed = [['nested' => ['value']], null, 12345, true, ['someone@example.com']];
        foreach (['/api/login', '/api/password/forgot', '/api/password/reset'] as $index => $path) {
            foreach ($malformed as $email) {
                // Sending endpoints permit only three attempts per account/hour;
                // time advances after each malformed account reservation so this
                // part checks validation independently of the sending quota.
                $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                    ->postJson($path, ['email' => $email, 'password' => 'ValidPassword!', 'password_confirmation' => 'ValidPassword!', 'token' => 'invalid'])
                    ->assertUnprocessable()->assertJsonValidationErrors('email');
                $this->travel(61)->minutes();
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
                ->postJson('/api/login', ['email' => ['invalid'], 'password' => 'unused'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/login', ['email' => 'unused@example.com', 'password' => 'unused'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'auth_rate_limited');
    }

    public function withToken($token, $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();
        return parent::withToken($token, $type);
    }

    public function test_impersonation_cannot_bypass_the_original_admin_verification_gate(): void
    {
        $target = User::factory()->create(['role' => 'client', 'email_verified_at' => null]);
        $grandfatheredAdmin = User::factory()->create(['role' => 'admin']);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => null]);
        $token = $admin->createToken('admin-browser')->plainTextToken;
        $this->travel(14)->days();

        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $target->id)
            ->getJson('/api/profile/activity')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->assertNull($target->fresh()->email_verification_required_at);
        $this->assertSame(1, $admin->tokens()->count());

        // The same recovery allowlist applies to the original actor. Clearing
        // impersonation lets the admin inspect their own current-email state.
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', '')->getJson('/api/user')
            ->assertOk()->assertJsonPath('email_verification.required', true);

        $enrolledTarget = User::factory()->create(['role' => 'client', 'email_verified_at' => null]);
        $this->withToken($grandfatheredAdmin->createToken('grandfathered-admin')->plainTextToken)
            ->withHeader('X-Impersonate-User-Id', (string) $enrolledTarget->id)
            ->getJson('/api/profile/activity')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    public function test_impersonation_rejects_an_inactive_original_admin_and_keeps_target_gates(): void
    {
        $target = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'inactive']);
        $token = $admin->createToken('stale-admin-browser')->plainTextToken;
        $this->withToken($token)->withHeader('X-Impersonate-User-Id', (string) $target->id)
            ->getJson('/api/profile/activity')->assertUnauthorized();
        $this->assertSame(0, $admin->tokens()->count());

        $activeAdmin = User::factory()->create(['role' => 'admin']);
        $activeToken = $activeAdmin->createToken('active-admin')->plainTextToken;
        $this->withToken($activeToken)->withHeader('X-Impersonate-User-Id', (string) $target->id)
            ->getJson('/api/user')->assertOk();
        $target->forceFill(['account_status' => 'inactive'])->save();
        $this->withToken($activeToken)->withHeader('X-Impersonate-User-Id', (string) $target->id)
            ->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(1, $activeAdmin->tokens()->count());
    }

    public function test_login_limit_combines_normalized_account_across_ips_and_expires(): void
    {
        $user = User::factory()->create(['email' => 'limit@example.com', 'password' => 'legacy']);
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->postJson('/api/login', ['email' => $i % 2 ? 'LIMIT@example.com' : 'limit@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'legacy'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(16)->minutes();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'legacy'])->assertOk();
    }

    public function test_login_ip_limit_counts_different_unknown_accounts_and_success_refunds_only_its_own_attempt(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => "unknown{$i}@example.com", 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertStatus(429);
        $this->travel(61)->seconds();
        $user = User::factory()->create(['password' => 'legacy']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'legacy'])->assertOk();
        $key = 'login-account:'.hash('sha256', $user->email);
        $this->assertSame(1, (int) DB::table('auth_security_limits')->where('key', $key)->value('attempts'));
    }

    public function test_wrong_mfa_codes_share_login_account_budget(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!', 'two_factor_code' => 'not-a-code'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertStatus(429)->assertJsonMissingPath('token');
    }

    public function test_forgot_unknown_accounts_have_generic_responses_and_identical_limits(): void
    {
        $user = User::factory()->create();
        $this->mock(MailService::class, function ($mock) {
            $mock->shouldReceive('generatePasswordResetLink')->andReturn('https://example.test/reset');
            $mock->shouldReceive('sendPasswordResetEmail')->andThrow(new \RuntimeException('private transport detail'));
        });
        $known = $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        foreach ([$user->email, 'NOBODY@example.com'] as $email) {
            $this->postJson('/api/password/forgot', compact('email'))->assertOk();
            $this->postJson('/api/password/forgot', compact('email'))->assertOk();
            $this->postJson('/api/password/forgot', compact('email'))->assertStatus(429)->assertHeader('Retry-After');
        }
    }

    public function test_reset_token_is_single_use_and_reissuance_invalidates_prior_token(): void
    {
        $user = User::factory()->create(['password' => 'legacy']);
        $user->createToken('old-session');
        $recovery = app(PasswordRecoveryService::class);
        $old = $recovery->issue($user);
        $new = $recovery->issue($user);
        $body = ['email' => $user->email, 'password' => 'NewPassword!', 'password_confirmation' => 'NewPassword!'];
        $this->postJson('/api/password/reset', $body + ['token' => $old])->assertStatus(400);
        $this->postJson('/api/password/reset', $body + ['token' => $new])->assertOk();
        $this->postJson('/api/password/reset', $body + ['token' => $new])->assertStatus(400);
        $this->assertTrue(Hash::check('NewPassword!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_and_resend_have_account_limits_and_forgot_has_a_shared_ip_limit(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->postJson('/api/password/reset', [
                'email' => $user->email, 'token' => 'wrong', 'password' => 'ValidPassword!', 'password_confirmation' => 'ValidPassword!',
            ])->assertStatus(400);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson('/api/password/reset', [
            'email' => strtoupper($user->email), 'token' => 'wrong', 'password' => 'ValidPassword!', 'password_confirmation' => 'ValidPassword!',
        ])->assertStatus(429)->assertHeader('Retry-After');
        $this->mock(MailService::class, fn ($mock) => $mock->shouldReceive('sendClientEmailVerificationEmail')->andReturn(true));
        $token = $user->createToken('resend')->plainTextToken;
        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)->postJson('/api/profile/email-verification/resend')->assertOk();
        }
        $this->postJson('/api/profile/email-verification/resend')->assertStatus(429)->assertHeader('Retry-After');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/password/forgot', ['email' => "absent{$i}@example.com"])->assertOk();
        }
        $this->postJson('/api/password/forgot', ['email' => 'different@example.com'])->assertStatus(429);
    }

    public function test_new_password_policy_covers_all_four_paths_without_rejecting_legacy_login(): void
    {
        $user = User::factory()->create(['password' => 'legacy']);
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['short7!', str_repeat('é', 37), "seven77\0"] as $password) {
            $this->postJson('/api/register', ['name' => 'Test', 'email' => 'policy@example.com', 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => 'invalid', 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->withToken($user->createToken('profile')->plainTextToken)->putJson('/api/profile', ['current_password' => 'legacy', 'new_password' => $password, 'new_password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('new_password');
            $this->withToken($admin->createToken('admin')->plainTextToken)->patchJson('/api/admin/users/'.$user->id.'/password', ['password' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'legacy'])->assertOk();
        $this->assertTrue(Hash::check('legacy', $user->fresh()->password));
    }

    public function test_pending_mfa_secret_is_encrypted_and_bound_to_browser_and_current_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);
        $first = $user->createToken('first')->plainTextToken;
        $second = $user->createToken('second')->plainTextToken;
        $setup = $this->withToken($first)->postJson('/api/profile/security/two-factor/setup', ['current_password' => 'Secret123!'])->assertOk()->json();
        $key = 'profile-security:two-factor-setup:v2:'.$user->id.':'.hash('sha256', 'token:'.explode('|', $first)[0]);
        $this->assertStringNotContainsString($setup['secret'], Cache::get($key));
        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($setup['secret']);
        $this->withToken($second)->postJson('/api/profile/security/two-factor/confirm', ['current_password' => 'Secret123!', 'code' => $code])->assertUnprocessable();
        $user->forceFill(['password' => 'Different123!'])->save();
        $this->withToken($first)->postJson('/api/profile/security/two-factor/confirm', ['current_password' => 'Different123!', 'code' => $code])->assertUnprocessable();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_pending_mfa_setup_expires_and_revoked_token_cannot_finish_mutation(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!']);
        $token = $user->createToken('setup')->plainTextToken;
        $setup = $this->withToken($token)->postJson('/api/profile/security/two-factor/setup', ['current_password' => 'Secret123!'])->assertOk()->json();
        $this->travel(11)->minutes();
        $code = app(TwoFactorAuthenticationService::class)->currentCodeForSecret($setup['secret']);
        $this->postJson('/api/profile/security/two-factor/confirm', ['current_password' => 'Secret123!', 'code' => $code])->assertUnprocessable();
        $original = $user->fresh()->withAccessToken($user->tokens()->first());
        $request = Request::create('/api/profile/security/two-factor/confirm', 'POST');
        $request->setUserResolver(fn () => $original);
        $user->tokens()->delete();
        try {
            app(AccountSecurityMutation::class)->run($request, 'Secret123!', fn () => $this->fail('Revoked token reached mutation.'));
            $this->fail('Expected authentication failure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_pilot_is_opt_in_persistent_and_existing_sessions_are_gated_after_fourteen_days(): void
    {
        $existing = User::factory()->create(['email_verified_at' => null]);
        $this->artisan('auth:start-email-verification-pilot')->assertSuccessful();
        $this->assertNull(app(EmailVerificationPilot::class)->startedAt());
        $this->assertFalse(app(EmailVerificationPilot::class)->status($existing)['reminder']);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $start = app(EmailVerificationPilot::class)->startedAt()->toIso8601String();
        $new = User::factory()->create(['email_verified_at' => null, 'email_status' => 'verified']);
        $token = $new->createToken('existing-browser')->plainTextToken;
        $this->withToken($token)->getJson('/api/profile/activity')->assertOk();
        $this->travel(14)->days();
        $this->getJson('/api/profile/activity')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email_verification.required', true);
        $this->getJson('/api/profile/security')->assertOk();
        $this->withToken($existing->createToken('grandfathered')->plainTextToken)->getJson('/api/profile/activity')->assertOk();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email_verification.enrolled', false)
            ->assertJsonPath('email_verification.reminder', true)->assertJsonPath('email_verification.required', false)
            ->assertJsonPath('email_verification.enforce_at', null);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $this->assertSame($start, app(EmailVerificationPilot::class)->startedAt()->toIso8601String());
        $this->assertNull($existing->fresh()->email_verification_required_at);
    }

    public function test_verification_tracks_current_email_ownership_despite_bounce_and_email_change_enrolls_existing_user(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $user->forceFill(['email' => 'changed@example.com'])->save();
        $this->assertNotNull($user->email_verification_required_at);
        app(EmailHealthService::class)->markVerified($user);
        app(EmailHealthService::class)->markBounced($user, 'test bounce');
        $this->travel(15)->days();
        $this->assertFalse(app(EmailVerificationPilot::class)->status($user->fresh())['required']);
        $enrolled = $user->email_verification_required_at;
        $user->forceFill(['email' => 'changed-again@example.com'])->save();
        $this->assertNull($user->email_verified_email);
        $this->assertEquals($enrolled, $user->email_verification_required_at);
        $this->assertTrue(app(EmailVerificationPilot::class)->status($user->fresh())['required']);
    }

    public function test_required_email_correction_is_narrow_and_requires_current_password(): void
    {
        $this->artisan('auth:start-email-verification-pilot', ['--apply' => true])->assertSuccessful();
        $user = User::factory()->create(['password' => 'Secret123!', 'email_verified_at' => null]);
        $token = $user->createToken('restricted-browser')->plainTextToken;
        $this->travel(15)->days();
        $this->partialMock(EmailHealthService::class, fn ($mock) => $mock->shouldReceive('analyzeForSave')->andReturn(['valid' => true, 'status' => 'unverified']));
        $this->mock(MailService::class, fn ($mock) => $mock->shouldReceive('sendClientEmailVerificationEmail')->andReturn(true));
        $this->withToken($token)->putJson('/api/profile', ['name' => 'blocked'])->assertForbidden();
        $this->withToken($token)->postJson('/api/profile/email-verification/correct', ['email' => 'correct@example.com', 'current_password' => 'wrong'])->assertUnprocessable();
        $name = $user->name;
        $this->withToken($token)->postJson('/api/profile/email-verification/correct', [
            'email' => 'correct@example.com', 'current_password' => 'Secret123!', 'name' => 'ignored', 'role' => 'admin',
        ])->assertOk()->assertJsonPath('reauth_required', true);
        $this->assertSame('correct@example.com', $user->fresh()->email);
        $this->assertSame($name, $user->fresh()->name);
        $this->assertSame('client', $user->fresh()->role);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(app(EmailVerificationPilot::class)->status($user->fresh())['required']);
    }

    public function test_counter_pruning_is_bounded_and_keeps_active_rows(): void
    {
        foreach (range(1, 3) as $id) DB::table('auth_security_limits')->insert(['key' => 'expired-'.$id, 'attempts' => 10, 'expires_at' => now()->timestamp - 1]);
        DB::table('auth_security_limits')->insert(['key' => 'active', 'attempts' => 10, 'expires_at' => now()->timestamp + 60]);
        $this->artisan('auth:prune-security-limits', ['--limit' => 2])->assertSuccessful();
        $this->assertDatabaseCount('auth_security_limits', 2);
        $this->assertDatabaseHas('auth_security_limits', ['key' => 'active', 'attempts' => 10]);
    }

    public function test_email_password_recovery_does_not_remove_mfa(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $token = app(PasswordRecoveryService::class)->issue($user);
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'Changed123!', 'password_confirmation' => 'Changed123!'])->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Changed123!'])->assertStatus(202)->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
    }

    public function test_security_mutations_reject_unsupported_ambient_sessions(): void
    {
        $user = User::factory()->create(['password' => 'Secret123!'])->withAccessToken(new \Laravel\Sanctum\TransientToken);
        $request = Request::create('/api/profile/security/two-factor/setup', 'POST');
        $request->setUserResolver(fn () => $user);
        try {
            app(AccountSecurityMutation::class)->run($request, 'Secret123!', fn () => $this->fail('Ambient session reached a security mutation.'));
            $this->fail('Expected authentication failure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_verification_confirmation_and_profile_email_delivery_run_outside_security_transactions(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'password' => 'Secret123!']);
        $baseline = DB::transactionLevel();
        $url = app(\App\Services\Users\ClientEmailVerificationLinkService::class)->buildUrl($user);
        $this->mock(MailService::class, function ($mock) use ($baseline) {
            $mock->shouldReceive('sendClientEmailVerifiedEmail')->once()->andReturnUsing(function () use ($baseline) {
                $this->assertSame($baseline, DB::transactionLevel(), 'Provider confirmation retained a database write lock.');
                return true;
            });
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnUsing(function () use ($baseline) {
                $this->assertSame($baseline, DB::transactionLevel(), 'Profile notification retained a database write lock.');
                return true;
            });
        });
        $this->get($url)->assertOk();
        $this->partialMock(EmailHealthService::class, function ($mock) use ($baseline) {
            $mock->shouldReceive('analyzeForSave')->once()->andReturnUsing(function () use ($baseline) {
                $this->assertSame($baseline, DB::transactionLevel(), 'DNS analysis retained a database write lock.');
                return ['valid' => true, 'status' => 'unverified'];
            });
        });
        $this->withToken($user->createToken('email-correction')->plainTextToken)->putJson('/api/profile', [
            'email' => 'updated@example.com', 'current_password' => 'Secret123!',
        ])->assertOk()->assertJsonPath('reauth_required', true);
    }

    public function test_changed_email_after_verification_cannot_receive_a_password_creation_token_from_old_proof(): void
    {
        $user = User::factory()->create();
        app(EmailHealthService::class)->markVerified($user);
        $snapshot = clone $user;
        $user->forceFill(['email' => 'changed-after-proof@example.com'])->save();
        try {
            app(PasswordRecoveryService::class)->issue($snapshot, $snapshot->email);
            $this->fail('Stale address proof issued a reset token.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_signed_out_rate_limit_events_are_redacted_and_reported_once_per_window(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => "unknown{$i}@example.com", 'password' => 'do-not-log-this'])->assertUnauthorized();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'do-not-log-this'])->assertStatus(429);
        }
        \Illuminate\Support\Facades\Log::shouldHaveReceived('notice')->once()->withArgs(function ($message, $context) {
            return $message === 'Authentication rate limit exceeded.'
                && array_keys($context) === ['scope', 'request_id']
                && $context['scope'] === 'login-ip'
                && is_string($context['request_id']) && $context['request_id'] !== '';
        });
    }
}
