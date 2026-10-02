<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\EmailHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExistingAccountRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AccountCreatedNotificationService::class)->shouldNotReceive('dispatch');
        $this->partialMock(EmailHealthService::class)->shouldNotReceive('analyzeForSave');
    }

    #[DataProvider('existingEmailVariants')]
    public function test_existing_unverified_accounts_get_actionable_json_without_mutations(string $storedEmail, string $submittedEmail): void
    {
        $user = User::factory()->unverified()->create(['email' => $storedEmail]);
        $snapshot = $user->fresh()->getRawOriginal();
        $message = 'An account with this email already exists. Please verify your email, then log in.';

        // Even a browser-style request must receive JSON rather than a redirect
        // or the public construction page. Other signup fields are unnecessary.
        $this->withHeader('Accept', 'text/html')->post('/api/register', ['email' => $submittedEmail])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('message', $message)
            ->assertJsonPath('errors.email.0', $message)
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $this->assertSame($snapshot, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('client_email_verification_tokens', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public static function existingEmailVariants(): array
    {
        return [
            'exact' => ['existing@example.com', 'existing@example.com'],
            'submitted case and spaces' => ['existing@example.com', '  EXISTING@EXAMPLE.COM  '],
            'legacy stored case and spaces' => [' Existing@Example.com ', 'existing@example.com'],
        ];
    }

    public function test_verified_accounts_are_directed_to_log_in(): void
    {
        User::factory()->create(['email' => 'verified@example.com']);

        $this->postJson('/api/register', ['email' => 'VERIFIED@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('message', 'An account with this email already exists. Please log in.')
            ->assertJsonPath('email_verification_required', false);
    }

    public function test_verification_of_a_previous_email_does_not_count_as_current_proof(): void
    {
        $user = User::factory()->create(['email' => 'current@example.com']);
        $user->forceFill(['email_verified_email' => 'previous@example.com'])->save();

        $this->postJson('/api/register', ['email' => $user->email])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('email_verification_required', true);
    }

    #[DataProvider('unavailableAccountStates')]
    public function test_unavailable_accounts_are_preserved_and_directed_to_support(string $state): void
    {
        $user = User::factory()->unverified()->create(['email' => 'unavailable@example.com']);
        if ($state === 'deleted') {
            $user->delete();
        } else {
            $user->forceFill($state === 'locked' ? ['locked_at' => now()] : ['account_status' => 'inactive'])->save();
        }
        $snapshot = User::withTrashed()->findOrFail($user->id)->getRawOriginal();

        $this->postJson('/api/register', ['email' => $user->email])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('message', 'An account with this email already exists. Please contact support for help accessing it.')
            ->assertJsonPath('email_verification_required', false)
            ->assertJsonMissingPath('token');

        $this->assertSame($snapshot, User::withTrashed()->findOrFail($user->id)->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
    }

    public static function unavailableAccountStates(): array
    {
        return [['inactive'], ['locked'], ['deleted']];
    }

    public function test_invalid_email_and_new_account_validation_still_return_json(): void
    {
        $this->withHeader('Accept', 'text/html')->post('/api/register', ['email' => ['invalid']])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/register', ['email' => 'new@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_account_responses_keep_the_registration_rate_limit(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'limited@example.com']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/register', ['email' => strtoupper($user->email)])
                ->assertUnprocessable()->assertJsonPath('code', 'account_exists');
        }

        $this->postJson('/api/register', ['email' => $user->email])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'auth_rate_limited');
    }

    public function test_a_duplicate_insert_race_returns_the_existing_account_response(): void
    {
        $this->partialMock(EmailHealthService::class, function ($mock) {
            $mock->shouldReceive('analyzeForSave')->once()->andReturnUsing(function ($email) {
                // Simulate another request creating the address after our lookup.
                User::factory()->unverified()->create(['email' => $email]);
                return ['valid' => true, 'status' => 'unverified'];
            });
        });

        $this->postJson('/api/register', $this->registrationPayload('race@example.com'))
            ->assertUnprocessable()->assertJsonPath('code', 'account_exists')
            ->assertJsonPath('email_verification_required', true)->assertJsonMissingPath('token');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_new_registration_still_succeeds_with_a_normalized_email(): void
    {
        $this->partialMock(EmailHealthService::class, function ($mock) {
            $mock->shouldReceive('analyzeForSave')->once()->with('new@example.com')
                ->andReturn(['valid' => true, 'status' => 'unverified']);
        });
        $this->mock(AccountCreatedNotificationService::class, function ($mock) {
            $channel = ['attempted' => false, 'sent' => false];
            $mock->shouldReceive('dispatch')->once()->andReturn([
                'email' => ['account_created' => $channel, 'verification' => $channel],
                'sms' => $channel,
            ]);
        });

        $this->postJson('/api/register', $this->registrationPayload('  NEW@EXAMPLE.COM  '))
            ->assertCreated()->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('user.role', 'client')->assertJsonStructure(['token']);

        $this->assertSame('new@example.com', DB::table('users')->sole()->email);
    }

    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'New Client',
            'email' => $email,
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ];
    }
}
