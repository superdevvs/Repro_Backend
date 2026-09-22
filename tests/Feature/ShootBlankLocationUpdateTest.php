<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootBlankLocationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_assignment_with_empty_location_fields_saves_without_null_constraint_failure(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $client = User::factory()->create(['role' => 'client']);
        $created = $this->postJson('/api/admin/test-shoots', [
            'kind' => 'area', 'value' => 'QA', 'scheduled_at' => '2026-09-23T10:00:00', 'timezone' => 'America/New_York',
        ])->assertCreated();
        $shoot = Shoot::findOrFail($created->json('shoot.id'));
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'client_id' => $client->id, 'city' => null, 'state' => '', 'zip' => null, 'notify_client' => false,
        ])->assertOk();
        $this->assertDatabaseHas('shoots', ['id' => $shoot->id, 'client_id' => $client->id, 'city' => '', 'state' => '', 'zip' => '']);
        $this->assertSame($shoot->address, $shoot->fresh()->address);
    }

    public function test_standard_shoot_explicit_blank_metadata_clears_to_empty_text_and_omitted_fields_are_preserved(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create(['photographer_id' => null, 'shoot_type' => Shoot::SHOOT_TYPE_STANDARD]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['city' => null, 'notify_client' => false])->assertOk();
        $this->assertDatabaseHas('shoots', ['id' => $shoot->id, 'city' => '', 'state' => $shoot->state, 'zip' => $shoot->zip, 'address' => $shoot->address]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['address' => null, 'state' => null, 'zip' => null])->assertOk();
        $this->assertDatabaseHas('shoots', ['id' => $shoot->id, 'address' => '', 'state' => '', 'zip' => '']);
    }
}
