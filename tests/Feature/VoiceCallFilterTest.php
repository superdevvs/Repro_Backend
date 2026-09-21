<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceCallFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_filter_keeps_ai_states_but_excludes_an_ended_leg_with_a_stale_status(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $active = VoiceCall::query()->create(['direction' => 'INBOUND', 'status' => 'ai_active']);
        VoiceCall::query()->create(['direction' => 'OUTBOUND', 'status' => 'active', 'ended_at' => now()]);
        $this->getJson('/api/voice/calls?filter=live')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $active->id);
    }

    public function test_missed_filter_uses_unanswered_inbound_evidence_not_whether_a_recap_was_written(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $missed = VoiceCall::query()->create(['direction' => 'INBOUND', 'status' => 'missed', 'disposition' => 'missed', 'summary' => 'Operator will call back', 'ended_at' => now()]);
        VoiceCall::query()->create(['direction' => 'INBOUND', 'status' => 'completed', 'disposition' => 'caller_hangup', 'answered_at' => now()->subMinute(), 'ended_at' => now()]);
        VoiceCall::query()->create(['direction' => 'OUTBOUND', 'status' => 'missed', 'disposition' => 'missed', 'ended_at' => now()]);
        $this->getJson('/api/voice/calls?filter=missed')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $missed->id);
    }
}
