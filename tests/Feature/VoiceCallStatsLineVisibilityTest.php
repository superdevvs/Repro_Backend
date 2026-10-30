<?php

namespace Tests\Feature;

use App\Models\ScheduledVoiceCall;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceLinePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VoiceCallStatsLineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const LINE_A = '+12025550101';

    private const LINE_B = '+12025550102';

    private User $staff;

    private VoiceCall $visibleOutbound;

    private VoiceCall $visibleMissed;

    private VoiceCall $hiddenAnswered;

    private VoiceCall $hiddenOutbound;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->staff = User::factory()->create(['role' => 'editing_manager',
            'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []]]);
        $other = User::factory()->create(['role' => 'editing_manager']);
        foreach ([self::LINE_A => $this->staff, self::LINE_B => $other] as $number => $recipient) {
            VoiceLinePolicy::create(['phone_number' => $number, 'mode' => 'staff_voicemail',
                'recipient_role' => null, 'recipient_roles' => [], 'recipient_ids' => [$recipient->id],
                'timezone' => 'UTC', 'weekly_hours' => []]);
        }

        $visibleAnswered = $this->createCall(self::LINE_A, ['intent' => 'visible_inquiry', 'disposition' => 'transferred']);
        $this->booking($visibleAnswered);
        $visibleAnswered->events()->create(['provider' => 'telnyx', 'event_type' => 'call.transferred',
            'received_at' => now(), 'processed_at' => now(), 'idempotency_key' => 'visible-transfer']);
        $this->visibleOutbound = $this->createCall(self::LINE_A, ['direction' => 'OUTBOUND', 'handled_by' => 'ai',
            'duration_seconds' => 120, 'intent' => 'visible_inquiry', 'needs_follow_up' => true]);
        $this->visibleMissed = $this->createCall(self::LINE_A, ['answered_at' => null, 'duration_seconds' => 0]);
        ScheduledVoiceCall::create(['status' => 'completed', 'target_phone' => $this->visibleMissed->from_phone,
            'scheduled_at' => now(), 'original_voice_call_id' => $this->visibleMissed->id,
            'result_voice_call_id' => $this->visibleOutbound->id]);

        $hidden = ['handled_by' => 'ai', 'duration_seconds' => 600, 'intent' => 'hidden_acquisition',
            'answered_at' => now()->subHour()->addSeconds(100)];
        $this->hiddenAnswered = $this->createCall(self::LINE_B, array_merge($hidden, ['disposition' => 'handoff_to_staff']));
        $this->booking($this->hiddenAnswered);
        ScheduledVoiceCall::create(['status' => 'scheduled', 'target_phone' => $this->hiddenAnswered->from_phone,
            'scheduled_at' => now()->addHour(), 'original_voice_call_id' => $this->hiddenAnswered->id]);
        $this->createCall(self::LINE_B, $hidden);
        $this->hiddenOutbound = $this->createCall(self::LINE_B, array_merge($hidden, ['direction' => 'OUTBOUND']));
        $this->createCall(self::LINE_B, ['answered_at' => null, 'duration_seconds' => 0, 'intent' => 'hidden_acquisition']);

        // Only the inaccessible line has comparison-period traffic.
        $previous = ['created_at' => now()->subDays(8), 'answered_at' => now()->subDays(8)->addSeconds(50),
            'duration_seconds' => 300, 'intent' => 'previous_hidden'];
        $this->booking($this->createCall(self::LINE_B, $previous));
        $this->createCall(self::LINE_B, $previous);
    }

    public function test_all_stats_cards_and_sparklines_match_the_call_list_visibility(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $list = $this->getJson('/api/voice/calls')->assertOk()->assertJsonPath('total', 3)->json('data');
        $this->assertContains($this->visibleOutbound->id, array_column($list, 'id'));
        $this->assertNotContains($this->hiddenOutbound->id, array_column($list, 'id'));
        $this->getJson('/api/voice/calls/'.$this->visibleOutbound->id)->assertOk();
        $this->getJson('/api/voice/calls/'.$this->hiddenOutbound->id)->assertForbidden();
        $this->getJson('/api/voice/calls/'.$this->hiddenOutbound->id.'/transcript')->assertForbidden();

        $this->assertStats(['total_calls' => 3, 'answered' => 2, 'missed' => 1,
            'avg_duration' => 60, 'ai_handled' => 1, 'needs_follow_up' => 1]);
        Http::assertNothingSent();
    }

    public function test_insights_exclude_hidden_current_and_previous_windows_and_opportunity_text(): void
    {
        $this->actingAs($this->staff, 'sanctum');
        $insights = $this->getJson('/api/voice/calls/insights?range=7d')->assertOk()
            ->assertJsonPath('inbound_total', 2)->assertJsonPath('answered_rate', 50)
            ->assertJsonPath('median_answer_seconds', 10)->assertJsonPath('bookings_from_calls', 1)
            ->assertJsonPath('missed_recovered_rate', 100)->assertJsonPath('handoff_connected_rate', 100)
            ->assertJsonPath('handoffs_needing_callback', 0)->assertJsonPath('opportunity', null)
            ->assertJsonPath('answered_rate_delta', null)->assertJsonPath('median_answer_delta', null)
            ->assertJsonPath('bookings_delta', null)->assertJsonPath('missed_recovered_delta', null)
            ->assertDontSee('hidden_acquisition')->assertDontSee('previous_hidden')->json();
        $this->assertSame([['key' => 'visible_inquiry', 'label' => 'visible inquiry', 'count' => 2, 'pct' => 100]], $insights['intents']);
        $this->assertVolume($insights['volume_by_day'], ['team' => 1, 'ai' => 1, 'missed' => 1, 'total' => 3]);
        Http::assertNothingSent();
    }

    public function test_primary_and_secondary_superadmins_retain_all_lines_and_comparison_metrics(): void
    {
        foreach ([['role' => 'superadmin'], ['role' => 'admin', 'secondary_roles' => ['superadmin']]] as $roles) {
            $this->actingAs(User::factory()->create($roles), 'sanctum');
            $this->getJson('/api/voice/calls')->assertOk()->assertJsonPath('total', 9);
            $this->getJson('/api/voice/calls/'.$this->hiddenOutbound->id)->assertOk();
            $this->assertStats(['total_calls' => 7, 'answered' => 5, 'missed' => 2,
                'avg_duration' => 283, 'ai_handled' => 4, 'needs_follow_up' => 2]);
            $insights = $this->getJson('/api/voice/calls/insights?range=7d')->assertOk()
                ->assertJsonPath('inbound_total', 5)->assertJsonPath('answered_rate', 60)
                ->assertJsonPath('answered_rate_delta', -40)->assertJsonPath('median_answer_seconds', 100)
                ->assertJsonPath('median_answer_delta', 50)->assertJsonPath('bookings_from_calls', 2)
                ->assertJsonPath('bookings_delta', 1)->assertJsonPath('missed_recovered_rate', 50)
                ->assertJsonPath('handoff_connected_rate', 50)->assertJsonPath('handoffs_needing_callback', 1)
                ->assertJsonPath('intents.0.key', 'hidden_acquisition')->assertJsonPath('intents.0.count', 4)->json();
            $this->assertStringContainsString('4 callers', $insights['opportunity']['detail']);
            $this->assertStringContainsString('hidden acquisition', $insights['opportunity']['detail']);
            $this->assertVolume($insights['volume_by_day'], ['team' => 1, 'ai' => 4, 'missed' => 2, 'total' => 7]);
        }
        Http::assertNothingSent();
    }

    public function test_hidden_callback_result_does_not_mark_a_visible_missed_call_recovered(): void
    {
        ScheduledVoiceCall::where('original_voice_call_id', $this->visibleMissed->id)->firstOrFail()
            ->update(['result_voice_call_id' => $this->hiddenAnswered->id]);

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/voice/calls/insights?range=7d')
            ->assertOk()->assertJsonPath('missed_recovered_rate', 0);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']), 'sanctum')
            ->getJson('/api/voice/calls/insights?range=7d')->assertOk()->assertJsonPath('missed_recovered_rate', 50);
        Http::assertNothingSent();
    }

    private function assertStats(array $expected): void
    {
        $stats = $this->getJson('/api/voice/calls/stats?range=7d')->assertOk()->assertJsonPath('range', '7d')->json();
        $cards = collect($stats['cards'])->keyBy('key');
        $this->assertSame($expected, $cards->map(fn ($card) => $card['value'])->all());
        foreach (['total_calls', 'answered', 'missed'] as $key) {
            $points = [];
            for ($days = 6; $days >= 0; $days--) {
                $points[] = ['date' => now()->subDays($days)->toDateString(), 'value' => $days === 0 ? $expected[$key] : 0];
            }
            $this->assertSame($points, $cards[$key]['sparkline']);
        }
        foreach (['avg_duration', 'ai_handled', 'needs_follow_up'] as $key) {
            $this->assertSame([], $cards[$key]['sparkline']);
        }
    }

    private function assertVolume(array $actual, array $today): void
    {
        $expected = [];
        for ($days = 6; $days >= 0; $days--) {
            $date = now()->subDays($days);
            $expected[] = array_merge(['date' => $date->toDateString(), 'label' => $date->format('D')],
                $days === 0 ? $today : ['team' => 0, 'ai' => 0, 'missed' => 0, 'total' => 0]);
        }
        $this->assertSame($expected, $actual);
    }

    private function createCall(string $line, array $attributes = []): VoiceCall
    {
        $created = $attributes['created_at'] ?? now()->subHour();
        unset($attributes['created_at']);
        $outbound = ($attributes['direction'] ?? 'INBOUND') === 'OUTBOUND';
        $answered = array_key_exists('answered_at', $attributes) ? $attributes['answered_at'] : $created->copy()->addSeconds(10);
        $call = new VoiceCall(array_merge(['provider' => 'telnyx', 'direction' => 'INBOUND',
            'status' => $answered ? 'completed' : 'missed', 'handled_by' => 'human',
            'from_phone' => $outbound ? $line : '+12025550999', 'to_phone' => $outbound ? '+12025550999' : $line,
            'started_at' => $created, 'answered_at' => $answered,
            'ended_at' => $answered ? $answered->copy()->addSeconds($attributes['duration_seconds'] ?? 60) : $created->copy()->addSeconds(30),
            'duration_seconds' => 60, 'disposition' => $answered ? 'caller_hangup' : 'missed'], $attributes));
        $call->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        return $call;
    }

    private function booking(VoiceCall $call): void
    {
        $call->toolInvocations()->create(['tool_name' => 'book_shoot', 'status' => 'executed',
            'output_payload' => ['success' => true, 'result' => ['success' => true, 'shoot_id' => 123]]]);
    }
}
