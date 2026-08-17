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
            ->assertJsonPath('message', 'Verification email sent. Check your inbox to verify your address.');

        $this->assertDatabaseHas('client_email_verification_tokens', [
            'user_id' => $user->id,
            'issued_context' => 'dashboard_resend',
        ]);
        $this->assertDatabaseHas('system_email_dispatches', [
            'email_alias' => 'CLIENT_EMAIL_VERIFICATION',
            'related_account_id' => $user->id,
        ]);
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
