<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class ShootBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Test Client',
            'email' => 'client@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Test Photographer',
            'email' => 'photographer@test.com',
        ]);

        // Create test service
        $this->service = Service::factory()->create([
            'name' => 'Test Service',
            'price' => 100.00,
        ]);
    }

    /** @test */
    public function admin_can_book_shoot_with_date_and_photographer()
    {
        Sanctum::actingAs($this->admin);
        $scheduledAt = now()->addDays(7)->format('Y-m-d H:i:s');

        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'photographer_id' => $this->photographer->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
                'scheduled_at' => $scheduledAt,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'client',
                    'photographer',
                    'location',
                    'services',
                    'scheduledAt',
                    'status',
                    'payment',
                ],
            ]);

        // Verify shoot was created with correct status
        $shoot = Shoot::where('client_id', $this->client->id)->first();
        $this->assertNotNull($shoot);
        $this->assertEquals('scheduled', $shoot->status);
        $this->assertNotNull($shoot->scheduled_at);
        $this->assertEquals($this->photographer->id, $shoot->photographer_id);

        // Verify services were attached
        $this->assertTrue($shoot->services->contains($this->service));

        // Verify activity log
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'shoot_created',
        ]);
    }

    /** @test */
    public function admin_can_book_hold_on_shoot_without_date()
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(201);

        $shoot = Shoot::where('client_id', $this->client->id)->first();
        $this->assertNotNull($shoot);
        $this->assertEquals('hold_on', $shoot->status);
        $this->assertNull($shoot->scheduled_at);
        $this->assertNull($shoot->photographer_id);
    }

    /** @test */
    public function client_can_book_shoot_with_bypass_paywall()
    {
        Sanctum::actingAs($this->client);
        $response = $this->postJson('/api/shoots', [
                'address' => '123 Main St',
                'city' => 'Washington',
                'state' => 'DC',
                'zip' => '20001',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
                'bypass_paywall' => true,
                'scheduled_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201);

        $shoot = Shoot::where('client_id', $this->client->id)->first();
        $this->assertNotNull($shoot);
        $this->assertTrue($shoot->bypass_paywall);
        $this->assertEquals('dc', $shoot->tax_region);
    }

    /** @test */
    public function tax_is_calculated_correctly_for_maryland()
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1], // $100
                ],
            ]);

        $response->assertStatus(201);

        $shoot = Shoot::where('client_id', $this->client->id)->first();
        $this->assertEquals(100.00, $shoot->base_quote);
        $this->assertEquals('md', $shoot->tax_region);
        $this->assertEquals(6.0, $shoot->tax_percent);
        $this->assertEquals(6.00, $shoot->tax_amount); // 6% of $100
        $this->assertEquals(106.00, $shoot->total_quote);
    }

    /** @test */
    public function notes_are_created_with_correct_visibility()
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
                'shoot_notes' => 'Client visible note',
                'company_notes' => 'Internal company note',
                'photographer_notes' => 'Photographer note',
            ]);

        $response->assertStatus(201);

        $shoot = Shoot::where('client_id', $this->client->id)->first();

        // Verify shoot note (client visible)
        $shootNote = $shoot->notes()->where('type', 'shoot')->first();
        $this->assertNotNull($shootNote);
        $this->assertEquals('client_visible', $shootNote->visibility);
        $this->assertEquals('Client visible note', $shootNote->content);

        // Verify company note (internal)
        $companyNote = $shoot->notes()->where('type', 'company')->first();
        $this->assertNotNull($companyNote);
        $this->assertEquals('internal', $companyNote->visibility);

        // Verify photographer note
        $photoNote = $shoot->notes()->where('type', 'photographer')->first();
        $this->assertNotNull($photoNote);
        $this->assertEquals('photographer_only', $photoNote->visibility);
    }

    /** @test */
    public function client_cannot_book_for_another_client()
    {
        Sanctum::actingAs($this->client);
        $otherClient = User::factory()->create(['role' => 'client']);

        $response = $this->postJson('/api/shoots', [
                'client_id' => $otherClient->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function booking_fails_if_photographer_has_conflict()
    {
        Sanctum::actingAs($this->admin);
        // Create existing shoot at same time
        $scheduledAt = now()->addDays(7)->setTime(10, 0, 0);
        
        Shoot::factory()->create([
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
        ]);

        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'photographer_id' => $this->photographer->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['photographer_id']);
    }

    /** @test */
    public function booking_creates_activity_log()
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/shoots', [
                'client_id' => $this->client->id,
                'address' => '123 Main St',
                'city' => 'Baltimore',
                'state' => 'MD',
                'zip' => '21201',
                'services' => [
                    ['id' => $this->service->id, 'quantity' => 1],
                ],
                'scheduled_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(201);

        $shoot = Shoot::where('client_id', $this->client->id)->first();
        
        $this->assertDatabaseHas('shoot_activity_logs', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->admin->id,
            'action' => 'shoot_created',
        ]);
    }
}

