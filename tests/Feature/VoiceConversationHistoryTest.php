<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Voice\VoiceConversationHistoryService;
use App\Services\Voice\VoiceTranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VoiceConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telnyx.public_key' => null]);
    }

    private function makeCall(): VoiceCall
    {
        $user = User::factory()->create(['role' => 'admin']);
        $session = AiChatSession::create(['user_id' => $user->id, 'title' => 'Voice test', 'status' => 'active', 'channel' => 'VOICE']);

        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'active',
            'handled_by' => 'ai', 'call_control_id' => 'history-customer', 'from_phone' => '+12025550100',
            'to_phone' => '+12025550101', 'started_at' => now()->subMinute(), 'ai_chat_session_id' => $session->id]);
    }

    private function event(array $messages, int $seconds, bool $final = false): array
    {
        return ['id' => 'history-'.$seconds, 'event_type' => $final ? 'call.conversation.ended' : 'call.ai_gather.message_history_updated',
            'occurred_at' => now()->addSeconds($seconds)->toIso8601String(),
            'payload' => ['call_control_id' => 'history-customer', $final ? 'messages' : 'message_history' => $messages]];
    }

    public function test_real_gather_event_saves_speech_and_final_history_replaces_partial_text_without_duplicates(): void
    {
        $call = $this->makeCall();
        $first = [['role' => 'assistant', 'content' => 'Hello'], ['role' => 'tool', 'content' => 'secret tool output'],
            ['role' => 'user', 'content' => 'Yes'], ['role' => 'user', 'content' => 'Yes']];
        $this->postJson('/api/webhooks/telnyx/voice', ['data' => $this->event($first, 0)])->assertOk();
        $this->assertSame(3, $call->transcriptRows()->count());
        $this->assertSame("Hello\nYes\nYes", $call->fresh()->transcript);
        $first[0]['content'] = 'Hello, how can I help?';
        $this->postJson('/api/webhooks/telnyx/voice', ['data' => $this->event($first, 1)])->assertOk();
        $this->assertSame(3, $call->transcriptRows()->count());
        $this->assertSame(3, $call->aiChatSession->messages()->count());
        $final = [['role' => 'assistant', 'content' => 'Hello, how can I help?', 'timestamp' => now()->subSeconds(10)->toIso8601String()],
            ['role' => 'user', 'content' => 'Yes, download my photos.', 'timestamp' => now()->subSeconds(5)->toIso8601String()]];
        $this->postJson('/api/webhooks/telnyx/voice', ['data' => $this->event($final, 2, true)])->assertOk();
        $this->assertSame(2, $call->transcriptRows()->count());
        $this->assertSame(2, $call->aiChatSession->messages()->count());
        $this->assertSame("Hello, how can I help?\nYes, download my photos.", $call->fresh()->transcript);
        $this->assertTrue(data_get($call->fresh()->metadata, 'provider_history.final'));
        $this->assertSame('history-2', data_get($call->fresh()->metadata, 'provider_history.source_event_id'));
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame(3, $call->events()->count());
        Http::assertNothingSent();
    }

    public function test_late_live_snapshots_cannot_replace_newer_or_final_speech(): void
    {
        $call = $this->makeCall();
        $service = app(VoiceConversationHistoryService::class);
        $service->ingest($call, $this->event([['role' => 'user', 'content' => 'Newer words']], 2));
        $service->ingest($call, $this->event([['role' => 'user', 'content' => 'Older words']], 1));
        $this->assertSame('Newer words', $call->fresh()->transcript);
        $service->ingest($call, $this->event([['role' => 'user', 'content' => 'Final words']], 3, true), true);
        $service->ingest($call, $this->event([['role' => 'user', 'content' => 'Delayed live words']], 4));
        $this->assertSame('Final words', $call->fresh()->transcript);
    }

    public function test_refresh_repairs_saved_provider_history_without_replaying_business_actions(): void
    {
        $call = $this->makeCall();
        $call->update(['status' => 'completed', 'ended_at' => now()->subMinutes(2), 'summary' => 'Old summary']);
        $data = $this->event([['role' => 'user', 'content' => 'Please call me back tomorrow.']], 0, true);
        $call->events()->create(['provider' => 'telnyx', 'event_type' => $data['event_type'], 'normalized_type' => 'carrier_event',
            'idempotency_key' => 'telnyx:saved-history', 'raw_payload' => ['data' => $data], 'received_at' => now(), 'processed_at' => now()]);
        $service = app(VoiceTranscriptService::class);
        $service->rebuild($call);
        $updatedAt = $call->transcriptRows()->first()->updated_at;
        $this->travel(5)->seconds();
        $service->rebuild($call->fresh());
        $this->assertTrue($updatedAt->equalTo($call->transcriptRows()->first()->updated_at));
        $this->assertSame('Please call me back tomorrow.', $call->fresh()->transcript);
        $this->assertSame(1, $call->transcriptRows()->count());
        $this->assertSame(1, $call->aiChatSession->messages()->count());
        $this->assertSame(0, $call->scheduledCalls()->count());
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame('Old summary', $call->fresh()->summary);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_wrong_call_leg_and_non_speech_payloads_are_not_imported(): void
    {
        $call = $this->makeCall();
        $data = $this->event([['role' => 'user', 'content' => 'Wrong caller']], 0);
        $data['payload']['call_control_id'] = 'staff-private-leg';
        app(VoiceConversationHistoryService::class)->ingest($call, $data);
        app(VoiceConversationHistoryService::class)->ingest($call, $this->event([
            ['role' => 'tool', 'content' => 'Private tool response'], ['role' => 'system', 'content' => 'System prompt'],
            ['role' => 'assistant', 'content' => ''],
        ], 1));
        $this->assertNull($call->fresh()->transcript);
        $this->assertSame(0, $call->transcriptRows()->count());
    }

    public function test_same_second_out_of_order_snapshots_keep_the_newer_microsecond_version(): void
    {
        $call = $this->makeCall();
        $newer = $this->event([['role' => 'user', 'content' => 'Complete sentence']], 0);
        $older = $this->event([['role' => 'user', 'content' => 'Complete']], 0);
        $newer['occurred_at'] = '2026-10-01T02:29:41.904Z';
        $older['occurred_at'] = '2026-10-01T02:29:41.102Z';
        $service = app(VoiceConversationHistoryService::class);
        $service->ingest($call, $newer);
        $service->ingest($call, $older);
        $this->assertSame('Complete sentence', $call->fresh()->transcript);
    }

    public function test_transcript_get_repairs_empty_history_by_provider_time_instead_of_arrival_id(): void
    {
        foreach ([true, false] as $final) {
            $call = $this->makeCall();
            $call->update(['status' => 'completed', 'ended_at' => now()->subMinutes(2)]);
            foreach (['2026-10-01T02:29:41.904Z' => 'Complete latest words', '2026-10-01T02:29:41.102Z' => 'Older words'] as $at => $text) {
                $data = $this->event([['role' => 'user', 'content' => $text]], 0, $final);
                $data['id'] = $call->id.'-'.$at;
                $data['occurred_at'] = $at;
                $call->events()->create(['provider' => 'telnyx', 'event_type' => $data['event_type'], 'normalized_type' => 'carrier_event',
                    'idempotency_key' => 'telnyx:'.$data['id'], 'raw_payload' => ['data' => $data], 'received_at' => now(), 'processed_at' => now()]);
            }
            $this->assertSame(0, $call->transcriptRows()->count());
            $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
                ->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()
                ->assertJsonPath('transcript', 'Complete latest words')->assertJsonPath('can_rebuild', true);
            $this->assertSame(1, $call->transcriptRows()->count());
            $this->assertSame(0, $call->scheduledCalls()->count());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
