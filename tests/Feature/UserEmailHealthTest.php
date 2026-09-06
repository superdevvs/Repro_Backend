<?php

namespace Tests\Feature;

use App\Models\ClientEmailVerificationToken;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use App\Services\Users\ClientEmailVerificationLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class UserEmailHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creating_client_with_common_typo_domain_requires_confirmation(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'John Smith',
            'email' => 'john@test.con',
            'role' => 'client',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('email_health.status', 'risky');
        $response->assertJsonPath('email_health.warning_code', 'common_typo');
        $response->assertJsonPath('email_health.requires_confirmation', true);
        $response->assertJsonPath('email_health.suggested_correction', 'john@test.com');
        $response->assertJsonPath('email_health.entered_email', 'john@test.con');
    }

    public function test_public_registration_with_common_typo_domain_requires_confirmation(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Public Client',
            'email' => 'name@gail.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('email_health.status', 'risky');
        $response->assertJsonPath('email_health.warning_code', 'common_typo');
        $response->assertJsonPath('email_health.requires_confirmation', true);
        $response->assertJsonPath('email_health.suggested_correction', 'name@gmail.com');
    }

    public function test_admin_creating_client_with_non_mail_domain_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@missing-domain.invalid',
            'role' => 'client',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('email_health.status', 'invalid');
        $response->assertJsonPath('email_health.warning_code', 'domain_no_mail');
        $response->assertJsonPath('email_health.requires_confirmation', false);
    }

    public function test_bounce_webhook_marks_client_and_creates_role_scoped_notifications(): void
    {
        $admin = User::factory()->admin()->create();
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $otherSalesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'metadata' => [
                'accountRepId' => $salesRep->id,
            ],
        ]);

        Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'cakemail',
            'provider_message_id' => 'cm-123',
            'to_address' => $client->email,
            'subject' => 'Verify your email',
            'status' => 'SENT',
            'related_account_id' => $client->id,
        ]);

        $this->postJson('/api/webhooks/cakemail', [
            'event' => 'email.bounced',
            'data' => [
                'email_id' => 'cm-123',
                'email' => $client->email,
                'reason' => 'Mailbox unavailable',
                'bounce_type' => 'hard',
            ],
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'bounced',
            'email_bounce_reason' => 'Mailbox unavailable',
        ]);

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'cm-123',
            'status' => 'BOUNCED',
            'error_message' => 'Mailbox unavailable',
        ]);

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $client->id,
            'event_type' => 'email_bounced',
        ]);

        Cache::flush();

        Sanctum::actingAs($admin);
        $adminActions = collect($this->getJson('/api/notifications')->json('data.activity_log'))
            ->pluck('action');
        $this->assertTrue($adminActions->contains('email_bounced'));

        Cache::flush();

        Sanctum::actingAs($salesRep);
        $salesRepActions = collect($this->getJson('/api/notifications')->json('data.activity_log'))
            ->pluck('action');
        $this->assertTrue($salesRepActions->contains('email_bounced'));

        Cache::flush();

        Sanctum::actingAs($otherSalesRep);
        $otherSalesRepActions = collect($this->getJson('/api/notifications')->json('data.activity_log'))
            ->pluck('action');
        $this->assertFalse($otherSalesRepActions->contains('email_bounced'));
    }

    public function test_admin_notifications_include_new_client_account_registrations(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'new-client@example.com',
        ]);

        UserActivityLog::record(
            $client,
            'account_created',
            'Account created',
            'A new client registered through the public signup form.'
        );

        Cache::flush();
        Sanctum::actingAs($admin);

        $actions = collect($this->getJson('/api/notifications')->json('data.activity_log'))
            ->pluck('action');

        $this->assertTrue($actions->contains('account_created'));
    }

    public function test_client_notifications_include_their_own_email_issue_activity(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);

        UserActivityLog::record(
            $client,
            'email_verification_requested',
            'Email verification sent',
            'A verification email was sent to the client address after registration.'
        );

        Cache::flush();
        Sanctum::actingAs($client);

        $actions = collect($this->getJson('/api/notifications')->json('data.activity_log'))
            ->pluck('action');

        $this->assertTrue($actions->contains('email_verification_requested'));
    }

    public function test_admin_can_resend_client_email_verification_from_admin_tools(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->unverified()->create([
            'role' => 'client',
            'email' => 'needs-verification@example.com',
            'email_status' => 'unverified',
            'verification_sent_at' => null,
        ]);

        $this->partialMock(MailService::class, function (MockInterface $mock) use ($client) {
            $mock->shouldReceive('sendClientEmailVerificationEmail')
                ->once()
                ->withArgs(function (User $user, array $context) use ($client): bool {
                    return (int) $user->id === (int) $client->id
                        && ($context['issued_context'] ?? null) === 'admin_profile_resend'
                        && array_key_exists('issued_by', $context);
                })
                ->andReturnTrue();
        });

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/users/{$client->id}/resend-verification");

        $response->assertOk();
        $response->assertJsonPath('message', 'Verification email sent successfully.');
        $response->assertJsonPath('user.id', $client->id);
        $response->assertJsonPath('user.email_health.status', 'unverified');
        $this->assertNotNull($response->json('user.email_health.verification_sent_at'));

        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $client->id,
            'event_type' => 'email_verification_requested',
            'title' => 'Email verification resent',
        ]);
    }

    public function test_admin_created_accounts_require_email_verification_except_admin_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $roles = [
            'superadmin' => 'aj@reprophotos.com',
            'admin' => 'created.admin@gmail.com',
            'editing_manager' => 'created.editing.manager@gmail.com',
            'client' => 'created.client@gmail.com',
            'photographer' => 'created.photographer@gmail.com',
            'editor' => 'created.editor@gmail.com',
            'salesRep' => 'created.sales.rep@gmail.com',
        ];
        $verificationRoles = array_diff_key($roles, array_flip(['superadmin', 'admin']));

        $this->partialMock(MailService::class, function (MockInterface $mock) use ($roles, $verificationRoles) {
            $mock->shouldReceive('sendAccountCreatedEmail')
                ->times(count($roles))
                ->andReturnTrue();

            $mock->shouldReceive('sendClientEmailVerificationEmail')
                ->times(count($verificationRoles))
                ->withArgs(function (User $user, array $context) use ($verificationRoles): bool {
                    return array_key_exists($user->role, $verificationRoles)
                        && ($context['issued_context'] ?? null) === 'admin_account_created'
                        && ($context['verification_token'] ?? null) instanceof ClientEmailVerificationToken
                        && is_string($context['verification_link'] ?? null)
                        && str_contains($context['verification_link'], '/email/verify/');
                })
                ->andReturnTrue();
        });

        Sanctum::actingAs($admin);

        foreach ($roles as $role => $email) {
            $response = $this->postJson('/api/admin/users', [
                'name' => "Created {$role}",
                'email' => $email,
                'role' => $role,
            ]);

            $response->assertCreated();
            $response->assertJsonPath('user.role', $role);
            $response->assertJsonPath('notification_delivery.email.account_created.attempted', true);
            $response->assertJsonPath('notification_delivery.email.account_created.sent', true);
            $response->assertJsonPath('notification_delivery.sms.attempted', false);

            $userId = $response->json('user.id');
            $this->assertDatabaseHas('users', [
                'id' => $userId,
                'role' => $role,
                'email' => $email,
                'email_verified_at' => null,
            ]);

            if (array_key_exists($role, $verificationRoles)) {
                $this->assertContains($response->json('user.email_health.status'), ['unverified', 'risky']);
                $this->assertNotNull($response->json('user.email_health.verification_sent_at'));
                $this->assertDatabaseHas('client_email_verification_tokens', [
                    'user_id' => $userId,
                    'issued_context' => 'admin_account_created',
                ]);
                $this->assertDatabaseHas('user_activity_logs', [
                    'user_id' => $userId,
                    'event_type' => 'email_verification_requested',
                    'title' => 'Email verification sent',
                ]);
            } else {
                $response->assertJsonPath('user.email_health.status', null);
                $response->assertJsonPath('user.email_health.verification_sent_at', null);
                $this->assertDatabaseMissing('client_email_verification_tokens', [
                    'user_id' => $userId,
                    'issued_context' => 'admin_account_created',
                ]);
                $this->assertDatabaseMissing('user_activity_logs', [
                    'user_id' => $userId,
                    'event_type' => 'email_verification_requested',
                    'title' => 'Email verification sent',
                ]);
            }
        }
    }

    public function test_admin_created_account_dispatches_welcome_sms_and_reports_delivery(): void
    {
        $admin = User::factory()->admin()->create();

        $this->partialMock(MailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendAccountCreatedEmail')->once()->andReturnTrue();
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnTrue();
        });

        $this->mock(MessagingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendSms')
                ->once()
                ->withArgs(function (array $payload): bool {
                    return ($payload['to'] ?? null) === '+12075737634'
                        && ($payload['send_source'] ?? null) === 'ACCOUNT_CREATED'
                        && str_contains(strtolower((string) ($payload['body_text'] ?? '')), 'photographer account has been created')
                        && str_contains((string) ($payload['body_text'] ?? ''), 'sms.account@example.com');
                })
                ->andReturn(new Message());
        });

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'SMS Account',
            'email' => 'sms.account@example.com',
            'phone_number' => '(207) 573-7634',
            'role' => 'photographer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification_delivery.email.account_created.sent', true)
            ->assertJsonPath('notification_delivery.email.verification.sent', true)
            ->assertJsonPath('notification_delivery.sms.attempted', true)
            ->assertJsonPath('notification_delivery.sms.sent', true)
            ->assertJsonPath('notification_delivery.sms.error', null);
    }

    public function test_admin_cannot_resend_verification_for_already_verified_client(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'verified@example.com',
            'email_status' => 'verified',
            'email_verified_at' => now(),
        ]);

        $mailService = $this->mock(MailService::class);
        $mailService->shouldNotReceive('sendClientEmailVerificationEmail');

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$client->id}/resend-verification")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email address is already verified.');
    }

    public function test_client_profile_email_update_with_common_typo_requires_confirmation(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'password' => Hash::make('secret123'),
        ]);

        Sanctum::actingAs($client);

        $response = $this->putJson('/api/profile', [
            'email' => 'name@gail.com',
            'current_password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('email_health.status', 'risky');
        $response->assertJsonPath('email_health.warning_code', 'common_typo');
        $response->assertJsonPath('email_health.suggested_correction', 'name@gmail.com');
    }

    public function test_client_email_verification_link_validates_successfully_even_if_the_app_url_changes(): void
    {
        MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'email_status' => 'unverified',
        ]);

        $link = app(MailService::class)->generateClientEmailVerificationLink($client);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        config(['app.url' => 'https://different-public-host.example']);

        $uri = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);

        $this->withServerVariables([
            'HTTP_HOST' => 'proxy.example',
            'HTTP_X_FORWARDED_HOST' => 'api.reprodashboard.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PREFIX' => '/api',
        ])->get($uri)
            ->assertOk()
            ->assertSee('Email verified')
            ->assertSee('Open dashboard');

        $this->assertArrayHasKey('token', $query);
        $this->assertArrayNotHasKey('signature', $query);
        $this->assertArrayNotHasKey('signature_v', $query);
        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
        ]);
        $this->assertDatabaseHas('messages', [
            'related_account_id' => $client->id,
            'send_source' => 'CLIENT_EMAIL_VERIFIED',
        ]);
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $client->id,
            'event_type' => 'email_verified',
        ]);
    }

    public function test_admin_created_client_verification_redirects_to_create_password_with_fresh_reset_token(): void
    {
        config(['app.frontend_url' => 'https://dashboard.example.test']);

        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'admin-created-client@example.com',
            'email_status' => 'unverified',
            'email_verified_at' => null,
        ]);

        $verificationService = app(ClientEmailVerificationLinkService::class);
        $verificationToken = $verificationService->issueVerificationToken($client, [
            'issued_context' => 'admin_account_created',
            'issued_by' => $admin->id,
        ]);
        $link = $verificationService->buildUrlForIssuedToken($client, $verificationToken);

        $response = $this->get($this->pathWithQuery($link));

        $response->assertStatus(302);

        $redirectUrl = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://dashboard.example.test/reset-password?', $redirectUrl);

        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $this->assertSame('create', $query['mode'] ?? null);
        $this->assertSame($client->email, $query['email'] ?? null);
        $this->assertNotEmpty($query['token'] ?? null);

        $resetRecord = DB::table('password_reset_tokens')->where('email', $client->email)->first();
        $this->assertNotNull($resetRecord);
        $this->assertTrue(Hash::check((string) $query['token'], $resetRecord->token));

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
        ]);
        $this->assertDatabaseHas('user_activity_logs', [
            'user_id' => $client->id,
            'event_type' => 'email_verified',
        ]);
    }

    public function test_registration_flow_generates_a_v2_verification_link_that_can_be_opened(): void
    {
        $capturedLink = null;

        $verificationLinkService = app(ClientEmailVerificationLinkService::class);

        $this->partialMock(MailService::class, function (MockInterface $mock) use (&$capturedLink, $verificationLinkService) {
            $mock->shouldReceive('sendAccountCreatedEmail')->once()->andReturnTrue();
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnUsing(function (User $user) use (&$capturedLink, $verificationLinkService) {
                $capturedLink = $verificationLinkService->buildUrl($user);

                return true;
            });
        });

        $response = $this->postJson('/api/register', [
            'name' => 'Public Client',
            'email' => 'fresh-client@gmail.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();
        $userId = (int) $response->json('user.id');

        $this->assertNotNull($capturedLink);

        parse_str((string) parse_url((string) $capturedLink, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('token', $query);
        $this->assertArrayNotHasKey('signature', $query);
        $this->assertArrayNotHasKey('signature_v', $query);

        $this->get($this->pathWithQuery((string) $capturedLink))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email_status' => 'verified',
        ]);
    }

    public function test_public_registration_dispatches_welcome_sms_and_reports_each_channel(): void
    {
        $this->partialMock(MailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendAccountCreatedEmail')->once()->andReturnTrue();
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnTrue();
        });

        $this->mock(MessagingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendSms')->once()->withArgs(function (array $payload): bool {
                return ($payload['to'] ?? null) === '+12025550123'
                    && ($payload['send_source'] ?? null) === 'ACCOUNT_CREATED'
                    && ($payload['contact_type'] ?? null) === 'client'
                    && str_contains((string) ($payload['body_text'] ?? ''), 'registered.sms@example.com');
            })->andReturn(new Message());
        });

        $response = $this->postJson('/api/register', [
            'name' => 'Registered SMS',
            'email' => 'registered.sms@example.com',
            'phonenumber' => '(202) 555-0123',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification_delivery.email.account_created.sent', true)
            ->assertJsonPath('notification_delivery.email.verification.sent', true)
            ->assertJsonPath('notification_delivery.sms.attempted', true)
            ->assertJsonPath('notification_delivery.sms.sent', true)
            ->assertJsonPath('notification_delivery.sms.error', null);
    }

    public function test_public_registration_reports_sms_failure_without_rolling_back_account(): void
    {
        $this->partialMock(MailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendAccountCreatedEmail')->once()->andReturnTrue();
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnTrue();
        });
        $this->mock(MessagingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendSms')->once()->andThrow(new \RuntimeException('provider unavailable'));
        });

        $response = $this->postJson('/api/register', [
            'name' => 'Registered SMS Failure',
            'email' => 'registered.sms.failure@example.com',
            'phonenumber' => '202-555-0199',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('notification_delivery.email.account_created.sent', true)
            ->assertJsonPath('notification_delivery.sms.attempted', true)
            ->assertJsonPath('notification_delivery.sms.sent', false)
            ->assertJsonPath('notification_delivery.sms.error', 'provider unavailable');
        $this->assertDatabaseHas('users', ['email' => 'registered.sms.failure@example.com']);
    }

    public function test_resend_flow_generates_a_v2_verification_link_that_can_be_opened(): void
    {
        $client = User::factory()->unverified()->create([
            'role' => 'client',
            'email' => 'resend-client@gmail.com',
            'email_status' => 'unverified',
        ]);
        $capturedLink = null;

        $verificationLinkService = app(ClientEmailVerificationLinkService::class);

        $this->partialMock(MailService::class, function (MockInterface $mock) use (&$capturedLink, $verificationLinkService) {
            $mock->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturnUsing(function (User $user) use (&$capturedLink, $verificationLinkService) {
                $capturedLink = $verificationLinkService->buildUrl($user);

                return true;
            });
        });

        Sanctum::actingAs($client);

        $this->postJson('/api/profile/email-verification/resend')
            ->assertOk()
            ->assertJsonPath('message', 'Verification email sent. Check your inbox to verify your address.');

        $this->assertNotNull($capturedLink);

        parse_str((string) parse_url((string) $capturedLink, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('token', $query);
        $this->assertArrayNotHasKey('signature', $query);
        $this->assertArrayNotHasKey('signature_v', $query);

        $this->get($this->pathWithQuery((string) $capturedLink))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
        ]);
    }

    public function test_legacy_client_email_verification_links_still_validate_after_the_hmac_rollout(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'legacy-client@example.com',
            'email_status' => 'unverified',
        ]);

        $this->get($this->pathWithQuery($this->buildLegacyVerificationLink($client)))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
        ]);
    }

    public function test_invalid_client_email_verification_token_renders_a_branded_html_page(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'email_status' => 'unverified',
        ]);

        $link = app(MailService::class)->generateClientEmailVerificationLink($client);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $query['token'] = 'tampered-token';

        $this->get($this->pathWithQuery((string) parse_url($link, PHP_URL_PATH) . '?' . http_build_query($query)))
            ->assertStatus(403)
            ->assertSee('Verification link invalid')
            ->assertSee('Open dashboard')
            ->assertDontSee('"message":"Invalid signature."', false);
    }

    public function test_expired_client_email_verification_signature_renders_a_branded_html_page(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'expired-client@example.com',
            'email_status' => 'unverified',
        ]);

        $link = app(ClientEmailVerificationLinkService::class)->buildUrl($client, now()->subMinute());

        $this->get($this->pathWithQuery($link))
            ->assertStatus(403)
            ->assertSee('Verification link invalid')
            ->assertSee('Open dashboard');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'unverified',
        ]);
    }

    public function test_client_email_verification_token_links_validate_even_if_the_app_key_changes(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'rotated-key-client@example.com',
            'email_status' => 'unverified',
        ]);

        $originalKey = 'base64:' . base64_encode(random_bytes(32));
        $newKey = 'base64:' . base64_encode(random_bytes(32));

        config([
            'app.key' => $originalKey,
            'app.previous_keys' => [],
        ]);

        $link = app(ClientEmailVerificationLinkService::class)->buildUrl($client);

        config([
            'app.key' => $newKey,
            'app.previous_keys' => [$originalKey],
        ]);

        $this->get($this->pathWithQuery($link))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
        ]);
    }

    public function test_resend_supersedes_the_previous_active_verification_token(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'superseded-client@example.com',
            'email_status' => 'unverified',
        ]);

        $service = app(ClientEmailVerificationLinkService::class);
        $firstLink = $service->buildUrl($client);
        $secondLink = $service->buildUrl($client);

        $this->get($this->pathWithQuery($firstLink))
            ->assertStatus(403)
            ->assertSee('Verification link invalid');

        $this->get($this->pathWithQuery($secondLink))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseCount('client_email_verification_tokens', 2);

        $supersededToken = ClientEmailVerificationToken::query()->oldest('id')->first();
        $activeToken = ClientEmailVerificationToken::query()->latest('id')->first();

        $this->assertNotNull($supersededToken);
        $this->assertNotNull($activeToken);
        $this->assertNotNull($supersededToken->superseded_at);
        $this->assertNotNull($activeToken->used_at);
    }

    public function test_used_verification_token_cannot_be_reused(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'used-token-client@example.com',
            'email_status' => 'unverified',
        ]);

        $link = app(ClientEmailVerificationLinkService::class)->buildUrl($client);

        $this->get($this->pathWithQuery($link))
            ->assertOk()
            ->assertSee('Email verified');

        $client->forceFill([
            'email_status' => 'unverified',
            'email_verified_at' => null,
        ])->save();

        $this->get($this->pathWithQuery($link))
            ->assertStatus(403)
            ->assertSee('Verification link invalid');
    }

    public function test_sales_rep_can_list_all_client_accounts_company_wide(): void
    {
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $firstClient = User::factory()->create([
            'role' => 'client',
            'name' => 'Alpha Client',
        ]);
        $secondClient = User::factory()->create([
            'role' => 'client',
            'name' => 'Beta Client',
            'metadata' => [
                'accountRepId' => 999999,
            ],
        ]);
        User::factory()->photographer()->create([
            'name' => 'Hidden Photographer',
        ]);

        Sanctum::actingAs($salesRep);

        $response = $this->getJson('/api/admin/clients');

        $response->assertOk();

        $clientIds = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($clientIds->contains($firstClient->id));
        $this->assertTrue($clientIds->contains($secondClient->id));
        $this->assertCount(2, $clientIds);
    }

    protected function buildLegacyVerificationLink(User $user): string
    {
        $relativeSignedUrl = URL::temporarySignedRoute(
            'api.email-verification.verify',
            now()->addDays(7),
            [
                'user' => $user->id,
                'hash' => sha1(strtolower((string) $user->email)),
            ],
            absolute: false,
        );

        return 'https://api.reprodashboard.com/' . ltrim($relativeSignedUrl, '/');
    }

    protected function pathWithQuery(string $link): string
    {
        $path = (string) parse_url($link, PHP_URL_PATH);
        $query = parse_url($link, PHP_URL_QUERY);

        return $query ? $path . '?' . $query : $path;
    }
}
