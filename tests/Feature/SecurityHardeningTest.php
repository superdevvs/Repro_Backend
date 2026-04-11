<?php

namespace Tests\Feature;

use App\Models\PublicPaymentAccessToken;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Models\PhotographerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_forces_client_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Public Signup',
            'email' => 'public-signup@test.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'superadmin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'client');

        $this->assertDatabaseHas('users', [
            'email' => 'public-signup@test.com',
            'role' => 'client',
        ]);
    }

    public function test_public_users_cannot_mutate_photographer_availability(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);

        $this->postJson('/api/photographer/availability', [
            'photographer_id' => $photographer->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertStatus(401);
    }

    public function test_photographer_can_only_manage_their_own_availability(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $otherPhotographer = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($photographer);

        $this->postJson('/api/photographer/availability', [
            'photographer_id' => $photographer->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertCreated();

        $this->postJson('/api/photographer/availability', [
            'photographer_id' => $otherPhotographer->id,
            'day_of_week' => 'monday',
            'start_time' => '12:00',
            'end_time' => '14:00',
        ])->assertForbidden();
    }

    public function test_public_booking_lookup_returns_sanitized_payload(): void
    {
        $service = Service::factory()->create();
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Booking Photographer',
            'address' => '1 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        PhotographerAvailability::create([
            'photographer_id' => $photographer->id,
            'date' => now()->addDay()->toDateString(),
            'day_of_week' => strtolower(now()->addDay()->format('l')),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'status' => 'available',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'scheduled_date' => now()->addDay()->toDateString(),
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1]);

        $response = $this->postJson('/api/photographer/availability/for-booking', [
            'date' => now()->addDay()->toDateString(),
            'time' => '11:00 AM',
            'shoot_address' => '22 Listing Ave',
            'shoot_city' => 'Baltimore',
            'shoot_state' => 'MD',
            'shoot_zip' => '21202',
            'photographer_ids' => [$photographer->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $photographer->id)
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.home_address')
            ->assertJsonMissingPath('data.0.origin_address')
            ->assertJsonMissingPath('data.0.booked_slots.0.shoot_id')
            ->assertJsonMissingPath('data.0.booked_slots.0.address')
            ->assertJsonMissingPath('data.0.booked_slots.0.title');
    }

    public function test_old_public_payment_route_is_not_public_anymore(): void
    {
        $service = Service::factory()->create();
        $shoot = Shoot::factory()->create(['service_id' => $service->id]);

        $this->getJson("/api/shoots/{$shoot->id}/payment-details")
            ->assertStatus(401);
    }

    public function test_public_payment_token_returns_sanitized_details_and_expired_token_fails(): void
    {
        $service = Service::factory()->create();
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'total_quote' => 200,
            'base_quote' => 180,
            'tax_amount' => 20,
            'payment_status' => 'unpaid',
        ]);
        $shoot->services()->attach($service->id, ['price' => 180, 'quantity' => 1]);

        $token = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id,
            'created_by' => $admin->id,
        ]);

        $this->getJson("/api/public/payments/{$token->token}")
            ->assertOk()
            ->assertJsonPath('data.id', $shoot->id)
            ->assertJsonPath('data.client', null);

        $expired = PublicPaymentAccessToken::create([
            'shoot_id' => $shoot->id,
            'created_by' => $admin->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson("/api/public/payments/{$expired->token}")
            ->assertStatus(410);
    }

    public function test_public_share_link_uses_opaque_token_and_legacy_numeric_path_no_longer_resolves(): void
    {
        $service = Service::factory()->create();
        $shoot = Shoot::factory()->create(['service_id' => $service->id]);
        $shareLink = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => User::factory()->create(['role' => 'editor'])->id,
            'share_url' => 'https://example.test/share/opaque-download',
        ]);

        $this->getJson("/api/public/share-links/{$shareLink->public_token}")
            ->assertOk()
            ->assertJsonPath('redirect_url', 'https://example.test/share/opaque-download');

        $this->getJson("/api/public/share-links/{$shoot->id}/{$shareLink->id}")
            ->assertNotFound();
    }
}
