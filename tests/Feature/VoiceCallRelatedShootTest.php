<?php

namespace Tests\Feature;

use App\Models\ScheduledVoiceCall;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceCallRelatedShootTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_related_shoot_address_without_bogus_property_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['address' => '123 Main Street']);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
            'related_shoot_id' => $shoot->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/calls')
            ->assertOk()
            ->assertJsonPath('data.0.related_shoot.id', $shoot->id)
            ->assertJsonPath('data.0.related_shoot.address', '123 Main Street');

        // The related shoot payload must only expose real columns. On SQLite,
        // selecting a non-existent column ("property_address") silently returns
        // the literal string instead of erroring, leaking a bogus key.
        $shootPayload = $response->json('data.0.related_shoot');
        $this->assertSame(['id', 'address', 'status'], array_keys($shootPayload));
    }

    public function test_scheduled_calls_index_returns_related_shoot_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['address' => '456 Oak Avenue']);

        ScheduledVoiceCall::query()->create([
            'status' => 'scheduled',
            'target_phone' => '+12025550123',
            'reason' => 'manual_callback',
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
            'related_shoot_id' => $shoot->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/scheduled-calls')
            ->assertOk()
            ->assertJsonPath('data.0.related_shoot.id', $shoot->id)
            ->assertJsonPath('data.0.related_shoot.address', '456 Oak Avenue');

        $shootPayload = $response->json('data.0.related_shoot');
        $this->assertSame(['id', 'address', 'status'], array_keys($shootPayload));
    }
}
