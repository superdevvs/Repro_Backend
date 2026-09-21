<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceOutboundModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_expose_and_persist_outbound_mode(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/voice/settings', [
                'outbound_mode' => 'all',
                'canary_numbers' => ['+1 (202) 555-0123'],
            ])
            ->assertOk()
            ->assertJsonPath('outbound_mode', 'all')
            ->assertJsonPath('canary_mode', false)
            ->assertJsonPath('canary_numbers.0', '+12025550123');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/settings')
            ->assertOk()
            ->assertJsonPath('outbound_mode', 'all');
    }

    public function test_none_mode_blocks_outbound_health(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/voice/settings', ['outbound_mode' => 'none'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/health')
            ->assertOk()
            ->assertJsonPath('outbound_mode', 'none')
            ->assertJsonPath('can_place_calls', false)
            ->assertJsonFragment(['Outbound calling is turned off.']);
    }

    public function test_all_mode_does_not_require_canary_numbers(): void
    {
        config(['services.voice.canary_mode' => true, 'services.voice.canary_numbers' => []]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/voice/settings', ['outbound_mode' => 'all'])
            ->assertOk();

        $blockers = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/health')
            ->assertOk()
            ->assertJsonPath('outbound_mode', 'all')
            ->assertJsonPath('canary_mode', false)
            ->json('readiness_blockers');

        $this->assertIsArray($blockers);
        $this->assertFalse(collect($blockers)->contains(fn ($item) => str_contains((string) $item, 'canary')));
    }

    public function test_canary_mode_requires_allowlisted_destination(): void
    {
        config([
            'services.voice.provider' => 'telnyx', 'services.telnyx.voice.enabled' => true,
            'services.telnyx.api_key' => 'test-key', 'services.telnyx.voice.connection_id' => 'test-connection',
            'services.telnyx.voice.assistant_id' => 'test-assistant', 'services.telnyx.from_number' => '+12025550100',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
        ]);
        \Illuminate\Support\Facades\Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/voice/settings', [
                'outbound_mode' => 'canary',
                'canary_numbers' => ['+12025550123'],
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/voice/calls/outbound', ['to' => '+12025550999'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The destination is not allowlisted for canary outbound.');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
