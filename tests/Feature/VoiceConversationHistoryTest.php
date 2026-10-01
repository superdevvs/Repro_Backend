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
                ->assertJsonPath('transcript', 'Complete latest words')->assertJsonPath('can_rebuild', true)
                ->assertJsonPath('state', $final ? 'ready' : 'finalizing')
                ->assertJsonPath('completeness', 'provider_unverified');
            $this->assertSame(1, $call->transcriptRows()->count());
            $this->assertSame(0, $call->scheduledCalls()->count());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_final_history_is_saved_immediately_without_changing_raw_speech_or_capture_problem_state(): void
    {
        $call = $this->makeCall();
        $call->update(['status' => 'completed', 'ended_at' => now()]);
        $speech = 'Hello <break time="0.3s" /> there.';
        $customer = 'Please explain <break time="0.3s" />.';
        app(VoiceConversationHistoryService::class)->ingest($call, $this->event([
            ['role' => 'assistant', 'content' => $speech], ['role' => 'user', 'content' => $customer],
        ], 0, true), true);
        $call = $call->fresh();
        $metadata = $call->metadata;
        $state = app(VoiceTranscriptService::class)->state($call);
        $this->assertSame('ready', $state['state']);
        $this->assertSame($speech."\n".$customer, $state['transcript']);
        $this->assertSame("Hello there.\n".$customer, $state['display_transcript']);
        $this->assertSame($metadata, $call->fresh()->metadata);
        $this->assertSame($speech, $call->transcriptRows()->oldest('id')->first()->text);

        $call->update(['metadata' => array_merge($metadata, ['browser_transcription_pending' => true])]);
        $this->assertSame('partial', app(VoiceTranscriptService::class)->state($call->fresh())['state']);
        Http::assertNothingSent();
    }

    public function test_final_history_marker_does_not_skip_grace_for_missing_or_unrelated_saved_rows(): void
    {
        $call = $this->makeCall();
        $call->update(['status' => 'completed', 'ended_at' => now()->subHour()]);
        app(VoiceConversationHistoryService::class)->ingest($call, $this->event([
            ['role' => 'assistant', 'content' => 'Saved answer'], ['role' => 'user', 'content' => 'Question'],
        ], 0, true), true);
        $row = $call->transcriptRows()->latest('id')->first();
        $row->update(['provider_message_id' => 'telnyx:unrelated-segment']);
        $this->assertSame('finalizing', app(VoiceTranscriptService::class)->state($call->fresh())['state']);
        $row->delete();
        $this->assertSame('finalizing', app(VoiceTranscriptService::class)->state($call->fresh())['state']);
        Http::assertNothingSent();
    }

    public function test_transcript_api_only_hides_recognized_assistant_pauses_and_keeps_raw_events(): void
    {
        $call = $this->makeCall();
        $assistant = "Hello<break time='300ms'/>there. <break strength=\"strong\" /> <unknown /> <break time=\"bad\"/>";
        $customer = '<break time="300ms"/> is written here.';
        $data = $this->event([['role' => 'assistant', 'content' => $assistant], ['role' => 'user', 'content' => $customer]], 0, true);
        $this->postJson('/api/webhooks/telnyx/voice', ['data' => $data])->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')
            ->getJson('/api/voice/calls/'.$call->id.'/transcript')->assertOk()
            ->assertJsonPath('transcript', $assistant."\n".$customer)
            ->assertJsonPath('display_transcript', 'Hello there. <break strength="strong" /> <unknown /> <break time="bad"/>'."\n".$customer);
        $this->assertSame($assistant, data_get($call->events()->first()->raw_payload, 'data.payload.messages.0.content'));
        $this->assertSame($assistant, $call->transcriptRows()->oldest('id')->first()->text);
        Http::assertNothingSent();
    }

    public function test_display_transcript_preserves_speaker_prefixes_and_unattributed_or_unmatched_text(): void
    {
        $call = $this->makeCall();
        $assistant = 'First <break time="0.3s"/> second.';
        $customer = 'Literal <break time="0.3s"/>.';
        foreach (['assistant' => $assistant, 'customer' => $customer] as $speaker => $text) {
            $call->transcriptRows()->create(['provider_message_id' => 'telnyx:'.$speaker, 'speaker' => $speaker,
                'transcript_type' => 'final', 'text' => $text, 'occurred_at' => now()]);
        }
        $raw = 'assistant: '.$assistant."\ncustomer: ".$customer;
        $call->update(['transcript' => $raw]);
        $service = app(VoiceTranscriptService::class);
        $this->assertSame('assistant: First second.'."\ncustomer: ".$customer, $service->state($call->fresh())['display_transcript']);
        $call->update(['metadata' => ['transcript_projection' => ['source' => 'recording_recovery']]]);
        $this->assertSame($raw, $service->state($call->fresh())['display_transcript']);
        $call->update(['metadata' => [], 'transcript' => 'Legacy assistant: '.$assistant]);
        $this->assertSame('Legacy assistant: '.$assistant, $service->state($call->fresh())['display_transcript']);
        Http::assertNothingSent();
    }
}
