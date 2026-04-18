<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MailService;
use App\Services\Users\ClientEmailVerificationLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->assertSame(ClientEmailVerificationLinkService::SIGNATURE_VERSION, $query['signature_v'] ?? null);
        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'email_status' => 'verified',
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

        $this->assertSame(ClientEmailVerificationLinkService::SIGNATURE_VERSION, $query['signature_v'] ?? null);

        $this->get($this->pathWithQuery((string) $capturedLink))
            ->assertOk()
            ->assertSee('Email verified');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email_status' => 'verified',
        ]);
    }

    public function test_resend_flow_generates_a_v2_verification_link_that_can_be_opened(): void
    {
        $client = User::factory()->create([
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
        $this->assertSame(ClientEmailVerificationLinkService::SIGNATURE_VERSION, $query['signature_v'] ?? null);

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

    public function test_invalid_client_email_verification_signature_renders_a_branded_html_page(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
            'email_status' => 'unverified',
        ]);

        $link = app(MailService::class)->generateClientEmailVerificationLink($client);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $query['signature'] = 'tampered-signature';

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
