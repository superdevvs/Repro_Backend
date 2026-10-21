<?php

namespace Tests\Feature;

use App\Models\ClientEmailVerificationToken;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingStudioTrialVerificationReturnTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/listing-studio/free-edits';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://dashboard.example.test', 'app.frontend_url' => 'https://dashboard.example.test']);
        Http::preventStrayRequests();
    }

    public function test_verification_returns_to_trial_only_from_token_bound_destination(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $url = app(ClientEmailVerificationLinkService::class)->buildUrl($user, context: ['return_to' => self::PATH]);
        $this->assertSame(self::PATH, ClientEmailVerificationToken::sole()->metadata['return_to']);
        $this->assertStringNotContainsString('return_to', $url);
        $this->mock(MailService::class)->shouldReceive('sendClientEmailVerifiedEmail')->once()->andReturn(true);
        $this->get($url.'&return_to=https://evil.example/')->assertRedirect('https://dashboard.example.test'.self::PATH);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get($url)->assertForbidden();
    }

    public function test_untrusted_contexts_query_parameters_and_staff_roles_keep_default_result_page(): void
    {
        $this->mock(MailService::class)->shouldReceive('sendClientEmailVerifiedEmail')->andReturn(true);
        foreach ([null, 'https://evil.example/', '//evil.example/', '/listing-studio/free-edits?next=evil', self::PATH.'/'] as $path) {
            $user = User::factory()->unverified()->create(['role' => 'client']);
            $url = app(ClientEmailVerificationLinkService::class)->buildUrl($user, context: [
                'return_to' => $path, 'metadata' => ['return_to' => self::PATH],
            ]);
            $this->get($url.'&return_to='.urlencode(self::PATH))->assertOk()->assertSee('Email verified');
        }
        $staff = User::factory()->unverified()->create(['role' => 'editor']);
        $url = app(ClientEmailVerificationLinkService::class)->buildUrl($staff, context: ['return_to' => self::PATH]);
        $this->get($url)->assertOk()->assertSee('Email verified');
    }

    public function test_expired_token_cannot_redirect_or_verify(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $url = app(ClientEmailVerificationLinkService::class)->buildUrl($user, now()->subMinute(), ['return_to' => self::PATH]);
        $this->mock(MailService::class)->shouldNotReceive('sendClientEmailVerifiedEmail');
        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_registration_preserves_trial_intent_in_its_existing_notification_flow(): void
    {
        $this->partialMock(EmailHealthService::class)->shouldReceive('analyzeForSave')->once()->andReturn(['valid' => true, 'status' => 'unverified']);
        $this->mock(AccountCreatedNotificationService::class)->shouldReceive('dispatch')->once()->andReturnUsing(function ($user, $options) {
            $this->assertSame(self::PATH, $options['return_to']);
            $this->assertSame('registration', $options['issued_context']);
            $channel = ['attempted' => false, 'sent' => false];
            return ['email' => ['account_created' => $channel, 'verification' => $channel], 'sms' => $channel];
        });
        $this->postJson('/api/register', [
            'name' => 'Trial client', 'email' => 'trial-registration@example.com', 'password' => 'GoodPassword123!',
            'password_confirmation' => 'GoodPassword123!', 'return_to' => self::PATH,
        ])->assertCreated();
    }

    public function test_account_created_notification_binds_trial_return_to_its_verification_token(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client', 'phone' => null, 'phonenumber' => null]);
        $mail = $this->createMock(MailService::class);
        $mail->method('generateStoredPasswordResetLink')->willReturn('https://dashboard.example.test/reset');
        $mail->method('sendAccountCreatedEmail')->willReturn(true);
        $mail->expects($this->once())->method('sendClientEmailVerificationEmail')->willReturnCallback(function ($recipient, $context) {
            $this->assertSame(self::PATH, $context['verification_token']->metadata['return_to']);
            return true;
        });
        $automation = $this->createMock(AutomationService::class);
        $automation->method('buildUserContext')->willReturn([]);
        $automation->method('handleEvent')->willReturn([]);
        $automation->method('shouldUseFallback')->willReturn(true);
        $messaging = $this->createMock(MessagingService::class);
        $messaging->expects($this->never())->method('sendSms');
        $service = new AccountCreatedNotificationService($messaging, $mail, $automation,
            app(ClientEmailVerificationLinkService::class), app(EmailHealthService::class));
        $result = $service->dispatch($user, ['issued_context' => 'registration', 'return_to' => self::PATH]);
        $this->assertTrue($result['email']['verification']['sent']);
    }

    public function test_resend_accepts_only_the_trial_literal_and_keeps_default_context(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $this->actingAs($user, 'sanctum');
        $contexts = [];
        $this->mock(MailService::class)->shouldReceive('sendClientEmailVerificationEmail')->twice()->andReturnUsing(function ($recipient, $context) use (&$contexts) {
            $contexts[] = $context;
            app(ClientEmailVerificationLinkService::class)->issueVerificationToken($recipient, $context);
            return true;
        });
        $this->postJson('/api/profile/email-verification/resend', ['return_to' => self::PATH])->assertOk();
        $this->assertSame(self::PATH, ClientEmailVerificationToken::latest('id')->first()->metadata['return_to']);
        $this->postJson('/api/profile/email-verification/resend')->assertOk();
        $this->assertArrayNotHasKey('return_to', ClientEmailVerificationToken::latest('id')->first()->metadata);
        $this->assertSame('dashboard_resend', $contexts[0]['issued_context']);
        $this->assertSame(self::PATH, $contexts[0]['return_to']);
        $this->assertNull($contexts[1]['return_to']);
        foreach (['https://evil.example/', '//evil.example/', self::PATH.'?next=evil', [self::PATH]] as $invalid) {
            $this->postJson('/api/profile/email-verification/resend', ['return_to' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('return_to');
        }
    }

    public function test_registration_rejects_untrusted_return_destination_before_creating_account(): void
    {
        $this->mock(AccountCreatedNotificationService::class)->shouldNotReceive('dispatch');
        $this->postJson('/api/register', [
            'name' => 'Trial client', 'email' => 'invalid-return@example.com', 'password' => 'GoodPassword123!',
            'password_confirmation' => 'GoodPassword123!', 'return_to' => 'https://evil.example/',
        ])->assertUnprocessable()->assertJsonValidationErrors('return_to');
        $this->assertDatabaseCount('users', 0);
    }
}
