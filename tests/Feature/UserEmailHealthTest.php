<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
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
}
