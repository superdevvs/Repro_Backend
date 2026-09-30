<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityCheckValidationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_availability_returns_422_for_missing_required_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/photographer/availability/check', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photographer_id', 'date']);
    }
}
