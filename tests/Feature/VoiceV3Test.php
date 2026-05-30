<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceLlmUsage;
use App\Models\VoiceScheduleOverride;
use App\Services\TelnyxAi\BusinessScheduleService;
use App\Services\TelnyxAi\VoiceIntelligenceService;
use App\Services\TelnyxAi\VoiceLiveStreamService;
use App\Services\TelnyxAi\VoiceMemoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceV3Test extends TestCase
{
    use RefreshDatabase;

    private function settings(array $overrides = []): void
    {
        $base = [
            'business_hours' => [
                'timezone' => 'America/New_York',
                'weekly' => [
                    'monday' => [['09:00', '18:00']],
                    'tuesday' => [['09:00', '18:00']],
                    'wednesday' => [['09:00', '18:00']],
                    'thursday' => [['09:00', '18:00']],
                    'friday' => [['09:00', '18:00']],
                    'saturday' => [['10:00', '14:00']],
                    'sunday' => [],
                ],
            ],
            'holidays' => [['date' => '2026-12-25', 'label' => 'Christmas']],
            'schedule_overrides' => [],
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '08:00', 'timezone' => 'America/New_York'],
            'holiday_message' => 'Closed today for {holiday_label}.',
            'out_of_hours_message' => 'We are offline, can I schedule a callback?',
            'intelligence' => [
                'enabled' => true,
                'monthly_llm_budget_usd' => 50,
                'triggers' => [
                    'low_confidence' => true,
                    'silence' => true,
                    'sentiment_shift' => true,
                    'keyword' => true,
                    'transfer_requested' => true,
                    'cockpit_opened' => true,
                    'call_ending' => true,
                ],
                'thresholds' => [
                    'low_confidence_pct' => 70,
                    'silence_seconds' => 8,
                    'sentiment_drop' => 0.4,
                    'keywords' => ['human', 'refund', 'complaint', 'escalate'],
                ],
            ],
        ];

        Setting::query()->updateOrCreate(
            ['key' => 'messaging.telnyx_voice'],
            ['value' => json_encode(array_replace_recursive($base, $overrides)), 'type' => 'json']
        );
    }

    private function makeCall(): VoiceCall
    {
        return VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'active',
            'from_phone' => '+12025550123',
            'to_phone' => '+12025550100',
            'call_control_id' => 'call-' . uniqid(),
            'started_at' => now(),
        ]);
    }

    // ---- schedule ----------------------------------------------------------

    public function test_business_hours_state_returns_team_open_inside_window(): void
    {
        $this->settings();
        $schedule = app(BusinessScheduleService::class);
        // Wednesday 11:00 ET
        $now = Carbon::parse('2026-05-20 11:00:00', 'America/New_York');
        $this->assertSame(BusinessScheduleService::STATE_TEAM_OPEN, $schedule->currentState($now)['state']);
    }

    public function test_holiday_state_uses_holiday_message(): void
    {
        $this->settings();
        $schedule = app(BusinessScheduleService::class);
        $now = Carbon::parse('2026-12-25 11:00:00', 'America/New_York');
        $state = $schedule->currentState($now);
        $this->assertSame(BusinessScheduleService::STATE_HOLIDAY_CLOSED, $state['state']);

        $guidance = $schedule->robbieScheduleGuidance($now);
        $this->assertStringContainsString('Christmas', $guidance['message']);
    }

    public function test_override_open_overrides_weekly_closed(): void
    {
        $this->settings();
        // Sunday is closed in weekly hours; add an open override covering it.
        VoiceScheduleOverride::query()->create([
            'starts_at' => Carbon::parse('2026-05-24 09:00:00', 'America/New_York'),
            'ends_at' => Carbon::parse('2026-05-24 17:00:00', 'America/New_York'),
            'mode' => 'open',
            'label' => 'Sunday pop-up',
        ]);

        $schedule = app(BusinessScheduleService::class);
        $now = Carbon::parse('2026-05-24 12:00:00', 'America/New_York');
        $this->assertSame(BusinessScheduleService::STATE_OVERRIDE_OPEN, $schedule->currentState($now)['state']);
    }

    public function test_caller_timezone_phrasing_uses_caller_tz(): void
    {
        $this->settings();
        $schedule = app(BusinessScheduleService::class);
        $now = Carbon::parse('2026-05-20 11:00:00', 'America/New_York');
        $state = $schedule->currentState($now, 'America/Los_Angeles');
        $this->assertSame('America/Los_Angeles', $state['caller_timezone']);
        $this->assertNotNull($state['caller_now']);
    }

    public function test_schedule_state_endpoint_and_overrides_crud(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/schedule/state')
            ->assertOk()
            ->assertJsonStructure(['state' => ['state'], 'guidance' => ['allow_live_transfer', 'message']]);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/voice/schedule/overrides', [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addHours(2)->toIso8601String(),
                'mode' => 'closed',
                'label' => 'Offsite',
            ])
            ->assertCreated()
            ->json();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/voice/schedule/overrides/{$created['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('voice_schedule_overrides', ['id' => $created['id']]);
    }

    // ---- intelligence ------------------------------------------------------

    public function test_intelligence_does_not_run_when_no_trigger_fires(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);

        $intel->onRealtimeUpdate($call, ['confidence' => 0.95, 'sentiment' => 'positive', 'silence_sec' => 1, 'text' => 'hello there']);

        $this->assertNull($call->fresh()->metadata['intel_live'] ?? null);
    }

    public function test_intelligence_runs_on_keyword_trigger(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);

        $intel->onRealtimeUpdate($call, ['confidence' => 0.95, 'sentiment' => 'neutral', 'silence_sec' => 1, 'text' => 'I want a refund now']);

        $this->assertNotNull($call->fresh()->metadata['intel_live'] ?? null);
    }

    public function test_intelligence_debounces_burst_triggers_to_one_run(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);

        for ($i = 0; $i < 5; $i++) {
            $intel->onRealtimeUpdate($call->fresh(), ['confidence' => 0.4, 'text' => 'refund please']);
        }

        $this->assertSame(1, VoiceLlmUsage::query()->where('purpose', 'realtime_enrichment')->count());
    }

    public function test_monthly_llm_budget_paused_state_blocks_enrichment(): void
    {
        $this->settings(['intelligence' => ['monthly_llm_budget_usd' => 0.0001]]);
        VoiceLlmUsage::query()->create([
            'purpose' => 'realtime_enrichment',
            'model' => 'gpt-4o',
            'input_tokens' => 1000,
            'output_tokens' => 1000,
            'cost_usd' => 1.0,
            'created_at' => now(),
        ]);

        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);
        $intel->onRealtimeUpdate($call, ['confidence' => 0.3, 'text' => 'refund']);

        $this->assertNull($call->fresh()->metadata['intel_live'] ?? null);
        $this->assertTrue($intel->budgetPaused());
    }

    public function test_dual_sentiment_persists_separate_customer_and_robbie_quality(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);

        $intel->onRealtimeUpdate($call, ['sentiment' => 'negative', 'text' => 'this is a complaint']);
        $live = $call->fresh()->metadata['intel_live'];

        $this->assertArrayHasKey('customer_mood', $live);
        $this->assertArrayHasKey('robbie_quality', $live);
    }

    public function test_suggested_replies_carry_why_field(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $intel = app(VoiceIntelligenceService::class);

        $intel->onRealtimeUpdate($call, ['text' => 'let me talk to a human']);
        $replies = $call->fresh()->metadata['intel_live']['suggested_replies'] ?? [];

        $this->assertNotEmpty($replies);
        foreach ($replies as $reply) {
            $this->assertArrayHasKey('why', $reply);
        }
    }

    public function test_looping_detection_triggers_human_takeover_flag(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $stream = app(VoiceLiveStreamService::class);

        // Three identical assistant lines = looping.
        for ($i = 0; $i < 3; $i++) {
            $call = $stream->recordTranscriptChunk($call, ['text' => 'Can you confirm your address?', 'speaker' => 'assistant', 'confidence' => 0.9]);
        }
        // Force an enrichment run.
        app(VoiceIntelligenceService::class)->enrich($call->fresh(), ['silence'], final: false);

        $this->assertTrue($call->fresh()->metadata['intel_live']['human_takeover_recommended'] ?? false);
    }

    public function test_llm_usage_endpoint_reports_spend_vs_cap(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'admin']);
        VoiceLlmUsage::query()->create([
            'purpose' => 'final_summary',
            'model' => 'gpt-4o',
            'input_tokens' => 100,
            'output_tokens' => 100,
            'cost_usd' => 2.5,
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/voice/llm-usage')
            ->assertOk()
            ->assertJsonPath('budget_usd', 50)
            ->assertJsonPath('exceeded', false);
    }

    // ---- Layer 1 stream ----------------------------------------------------

    public function test_sse_stream_emits_transcript_realtime_and_insights_events(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();
        $stream = app(VoiceLiveStreamService::class);
        $stream->recordTranscriptChunk($call, ['text' => 'I need a refund', 'speaker' => 'customer', 'confidence' => 0.5]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/voice/calls/{$call->id}/stream?once=1");

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('event: transcript', $content);
        $this->assertStringContainsString('event: realtime', $content);
        $this->assertStringContainsString('event: schedule_state', $content);
    }

    public function test_realtime_signals_persisted_to_metadata_live(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $stream = app(VoiceLiveStreamService::class);
        $call = $stream->recordTranscriptChunk($call, ['text' => 'hello', 'speaker' => 'customer', 'confidence' => 0.92]);

        $live = $call->fresh()->metadata['live'];
        $this->assertSame(1, $live['transcript_seq']);
        $this->assertCount(1, $live['transcript_chunks']);
        $this->assertEqualsWithDelta(0.92, $live['realtime']['confidence'], 0.001);
    }

    // ---- memory ------------------------------------------------------------

    public function test_tier1_memory_loaded_for_every_call(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $memory = app(VoiceMemoryService::class);
        $tier1 = $memory->loadTier1($call, ['user' => null, 'contact' => null, 'identified' => false, 'phone_e164' => '+12025550123']);

        $this->assertArrayHasKey('caller_first_name', $tier1);
        $this->assertNotNull($call->fresh()->metadata['memory']['tier1']);
    }

    public function test_tier2_loads_on_keyword_or_negative_sentiment(): void
    {
        $this->settings();
        $call = $this->makeCall();
        $memory = app(VoiceMemoryService::class);
        $memory->loadTier1($call, ['user' => null, 'contact' => null, 'identified' => false, 'phone_e164' => '+12025550123']);

        $this->assertTrue($memory->shouldAutoLoadTier2($call->fresh(), ['keyword_hit' => true]));
        $memory->loadTier2($call->fresh());
        $this->assertNotNull($call->fresh()->metadata['memory']['tier2']);
    }

    public function test_tier3_loads_only_on_operator_request(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();

        // Not present until explicitly requested.
        $this->assertNull($call->metadata['memory']['tier3'] ?? null);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/voice/calls/{$call->id}/memory/load-full")
            ->assertOk()
            ->assertJsonPath('tier', 3);

        $this->assertNotNull($call->fresh()->metadata['memory']['tier3']);
    }

    public function test_memory_endpoint_returns_requested_tier(): void
    {
        $this->settings();
        $admin = User::factory()->create(['role' => 'admin']);
        $call = $this->makeCall();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/voice/calls/{$call->id}/memory?tier=1")
            ->assertOk()
            ->assertJsonPath('tier', 1);
    }
}
