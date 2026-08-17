<?php

namespace Tests\Feature;

use App\Models\MessageChannel;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserEmailResendAndAddressPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    public function test_null_email_status_user_can_resend_verification_email(): void
    {
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'legacy-null-status@example.com',
            'email_status' => null,
            'email_verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/profile/email-verification/resend')
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('email', 'legacy-null-status@example.com')
            ->assertJsonPath('message', 'Verification email sent. Check your inbox to verify your address.')
            ->assertJsonPath('user.email_health.status', 'unverified');

        $this->assertDatabaseHas('client_email_verification_tokens', [
            'user_id' => $user->id,
            'issued_context' => 'dashboard_resend',
        ]);
        $this->assertSame('unverified', $user->fresh()->email_status);
    }

    public function test_unverified_user_can_resend_verification_email(): void
    {
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'needs-verify@example.com',
            'email_status' => 'unverified',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/profile/email-verification/resend')
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('email', 'needs-verify@example.com')
            ->assertJsonPath('message', 'Verification email sent. Check your inbox to verify your address.');

        $this->assertDatabaseHas('client_email_verification_tokens', [
            'user_id' => $user->id,
            'issued_context' => 'dashboard_resend',
        ]);
        $this->assertDatabaseHas('system_email_dispatches', [
            'email_alias' => 'CLIENT_EMAIL_VERIFICATION',
            'related_account_id' => $user->id,
        ]);

        $this->postJson('/api/profile/email-verification/resend')
            ->assertOk()
            ->assertJsonPath('sent', true);

        $this->assertSame(2, \App\Models\ClientEmailVerificationToken::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('system_email_dispatches')
            ->where('email_alias', 'CLIENT_EMAIL_VERIFICATION')
            ->where('related_account_id', $user->id)
            ->count());
    }

    public function test_admin_resend_returns_a_clear_error_when_already_verified(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'already@example.com',
            'email_status' => 'verified',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$client->id}/resend-verification")
            ->assertStatus(422)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('message', 'This email address is already verified.');
    }

    public function test_sales_rep_cannot_see_photographer_street_address(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->photographer()->create([
            'address' => '6424 Vale Street',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22312',
        ]);

        Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'rep_id' => $rep->id,
        ]);

        Sanctum::actingAs($rep);

        $response = $this->getJson('/api/admin/photographers')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $photographer->id);

        $this->assertNotNull($row);
        $this->assertSame('Alexandria', $row['city'] ?? null);
        $this->assertSame('VA', $row['state'] ?? null);
        $this->assertSame('22312', $row['zip'] ?? null);
        $this->assertNull($row['address'] ?? null);
        $this->assertSame('region', $row['address_visibility'] ?? null);
    }

    public function test_admin_can_see_and_approve_photographer_address_change(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create([
            'address' => '100 Old Street',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        Sanctum::actingAs($photographer);
        $this->putJson('/api/profile', [
            'address' => '200 New Street',
            'city' => 'Fairfax',
            'state' => 'VA',
            'zip' => '22030',
        ])->assertOk()->assertJsonPath('address_change_pending', true);

        $photographer->refresh();
        $this->assertSame('100 Old Street', $photographer->address);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$photographer->id}/address-change/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Photographer address change approved.')
            ->assertJsonPath('user.address', '200 New Street');

        $this->assertSame('200 New Street', $photographer->fresh()->address);
        $this->assertSame('Fairfax', $photographer->fresh()->city);
    }

    public function test_sales_rep_cannot_approve_photographer_address_change(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->photographer()->create();

        Sanctum::actingAs($photographer);
        $this->putJson('/api/profile', [
            'address' => '9 Hidden Lane',
            'city' => 'Reston',
            'state' => 'VA',
            'zip' => '20190',
        ])->assertOk();

        Sanctum::actingAs($rep);
        $this->postJson("/api/admin/users/{$photographer->id}/address-change/approve")
            ->assertStatus(403);
    }

    public function test_editing_manager_cannot_see_or_overwrite_photographer_street(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $photographer = User::factory()->photographer()->create([
            'address' => '6424 Vale Street',
            'city' => 'Alexandria',
            'state' => 'VA',
            'zip' => '22312',
        ]);

        Sanctum::actingAs($manager);

        $row = collect($this->getJson('/api/admin/photographers')->assertOk()->json('data'))
            ->firstWhere('id', $photographer->id);
        $this->assertNotNull($row);
        $this->assertSame('Alexandria', $row['city'] ?? null);
        $this->assertSame('VA', $row['state'] ?? null);
        $this->assertSame('22312', $row['zip'] ?? null);
        $this->assertNull($row['address'] ?? null);
        $this->assertSame('region', $row['address_visibility'] ?? null);

        $this->putJson("/api/admin/users/{$photographer->id}", [
            'address' => '1 Secret Lane',
            'city' => 'Reston',
            'state' => 'VA',
            'zip' => '20190',
        ])->assertOk();

        $photographer->refresh();
        $this->assertSame('6424 Vale Street', $photographer->address);
        $this->assertSame('Alexandria', $photographer->city);
    }

    public function test_admin_payload_includes_full_photographer_address_visibility(): void
    {
        $admin = User::factory()->admin()->create();
        $photographer = User::factory()->photographer()->create([
            'address' => '100 Old Street',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        Sanctum::actingAs($admin);
        $row = collect($this->getJson('/api/admin/photographers')->assertOk()->json('data'))
            ->firstWhere('id', $photographer->id);

        $this->assertSame('full', $row['address_visibility'] ?? null);
        $this->assertSame('100 Old Street', $row['address'] ?? null);
    }

    protected function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }
}
