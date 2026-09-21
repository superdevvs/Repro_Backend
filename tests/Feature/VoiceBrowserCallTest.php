<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Services\Voice\VoiceBrowserCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceBrowserCallTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private VoiceBrowserSession $session;

    private int $dialCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.voice.canary_mode' => false,
            'services.telnyx.api_key' => 'test-key', 'services.telnyx.from_number' => '+12025550100',
            'services.telnyx.voice.browser_enabled' => true, 'services.telnyx.voice.credential_connection_id' => 'browser-connection',
            'services.telnyx.voice.connection_id' => 'server-app', 'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
            'services.telnyx.voice.outbound_mode' => 'all', 'services.telnyx.voice.enabled' => true,
            'services.telnyx.voice.support_handoff_number' => '+12025550999',
        ]);
        $this->operator = User::factory()->create(['role' => 'admin']);
        $this->session = $this->sessionFor($this->operator);
        $this->actingAs($this->operator, 'sanctum');
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/calls') {
                $n = ++$this->dialCount;

                return Http::response(['data' => ['call_control_id' => 'origin-'.$n, 'call_session_id' => 'session-'.$n]]);
            }
            if ($path === '/v2/conferences') {
                return Http::response(['data' => ['id' => 'conference-one']]);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
    }

    public function test_human_dial_waits_for_staff_conference_join_and_correlates_signed_browser_peer(): void
    {
        $call = $this->outbound();
        $this->assertSame(1, $this->dialCount);
        $this->assertNull($call->call_control_id);
        $agent = VoiceBrowserLeg::where('role', 'agent')->firstOrFail();
        $this->event('call.initiated', 'peer-one', ['connection_id' => 'browser-connection', 'call_session_id' => 'session-1', 'to' => 'sip:gencredTest@sip.telnyx.com']);
        $this->getJson('/api/voice/browser/sessions/'.$this->session->id)->assertOk()
            ->assertJsonPath('offers.0.agent_call_control_id', 'origin-1')->assertJsonPath('offers.0.browser_call_control_id', 'peer-one');
        $this->event('call.answered', 'peer-one', ['connection_id' => 'browser-connection']);
        $this->assertSame(1, $this->dialCount);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conferences') && $r['call_control_id'] === 'origin-1');
        $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
        $this->assertSame(2, $this->dialCount);
        $this->assertSame('origin-2', $call->fresh()->call_control_id);
        $this->event('call.answered', 'origin-2');
        $this->assertNotSame('active', $call->fresh()->status);
        $this->event('conference.participant.joined', 'origin-2', ['conference_id' => 'conference-one']);
        $this->assertSame('active', $call->fresh()->status);
        $this->assertSame('human', $call->fresh()->handled_by);
        $this->assertSame('Booking help', $call->metadata['reason']);
        $this->event('call.answered', 'origin-1');
        $this->assertSame(2, $this->dialCount);
        $this->assertSame('origin-1', $agent->fresh()->call_control_id);
    }

    public function test_unsolicited_or_wrong_device_browser_legs_are_hung_up_without_ai_or_inbox_rows(): void
    {
        $call = $this->outbound();
        $result = $this->event('call.initiated', 'intruder', ['connection_id' => 'browser-connection', 'call_session_id' => 'session-1', 'to' => 'sip:other@sip.telnyx.com']);
        $this->assertSame('rejected_browser_origin', $result['status']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/intruder/actions/hangup'));
        $this->assertDatabaseCount('voice_calls', 1);
        $this->assertNull($call->fresh()->call_control_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ai_assistant'));
    }

    public function test_early_peer_header_correlates_only_expected_device_and_preserves_origin_id(): void
    {
        $this->outbound();
        $agent = VoiceBrowserLeg::where('role', 'agent')->firstOrFail();
        $agent->update(['call_session_id' => null]);
        $this->event('call.initiated', 'early-peer', ['connection_id' => 'browser-connection', 'call_session_id' => 'session-1',
            'to' => 'sip:gencredTest@sip.telnyx.com', 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $agent->client_state]]]);
        $this->assertSame('early-peer', $agent->fresh()->browser_call_control_id);
        $this->assertSame('origin-1', $agent->fresh()->call_control_id);
    }

    public function test_early_staff_answer_never_marks_customer_answered_before_origin_response(): void
    {
        $call = $this->outbound();
        $agent = VoiceBrowserLeg::where('role', 'agent')->firstOrFail();
        $agent->update(['call_control_id' => null, 'call_session_id' => null]);
        $this->event('call.answered', 'early-peer', ['connection_id' => 'browser-connection', 'call_session_id' => 'session-1',
            'to' => 'sip:gencredTest@sip.telnyx.com', 'custom_headers' => [['name' => 'X-Repro-Offer', 'value' => $agent->client_state]]]);
        $this->assertNotNull($agent->fresh()->answered_at);
        $this->assertNull($call->fresh()->answered_at);
        $this->assertNull($call->fresh()->call_control_id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/conferences'));
        // A retry receives the original stable /calls response and proceeds.
        $this->outbound();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conferences') && $r['call_control_id'] === 'origin-1');
    }

    public function test_declined_takeover_preserves_ai_and_customer_and_late_answer_cannot_revive_offer(): void
    {
        $call = $this->aiCall();
        $this->postJson('/api/voice/calls/'.$call->id.'/takeover', ['session_id' => $this->session->id, 'idempotency_key' => 'takeover-one'])->assertOk();
        $this->event('call.hangup', 'origin-1');
        $this->assertNull($call->fresh()->ended_at);
        $this->assertSame('ai', $call->fresh()->handled_by);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/calls/customer-ai/actions/'));
        $this->event('call.answered', 'origin-1');
        $this->assertSame('failed', VoiceBrowserCall::first()->state);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/conferences'));
    }

    public function test_takeover_stops_ai_only_after_staff_join_and_ai_segment_end_does_not_end_customer(): void
    {
        $call = $this->aiCall();
        $this->postJson('/api/voice/calls/'.$call->id.'/takeover', ['session_id' => $this->session->id, 'idempotency_key' => 'takeover-one'])->assertOk();
        $this->event('call.answered', 'origin-1');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/ai_assistant_stop'));
        $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/customer-ai/actions/ai_assistant_stop'));
        $this->event('call.conversation.ended', 'customer-ai');
        $this->assertNull($call->fresh()->ended_at);
        $this->event('conference.participant.joined', 'customer-ai', ['conference_id' => 'conference-one']);
        $this->assertSame('mixed', $call->fresh()->handled_by);
        $this->assertSame('active', VoiceBrowserCall::first()->state);
    }

    public function test_supervisor_whisper_targets_only_staff_and_leaving_does_not_end_customer(): void
    {
        $call = $this->activeHuman();
        $supervisor = User::factory()->create(['role' => 'admin']);
        $session = $this->sessionFor($supervisor, 'gencredSupervisor');
        $this->actingAs($supervisor, 'sanctum')->postJson('/api/voice/calls/'.$call->id.'/supervise', [
            'session_id' => $session->id, 'mode' => 'whisper', 'idempotency_key' => 'supervision-one',
        ])->assertOk();
        $this->event('call.answered', 'origin-3');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/actions/join') && $r['call_control_id'] === 'origin-3'
            && $r['supervisor_role'] === 'whisper' && $r['whisper_call_control_ids'] === ['origin-1']);
        $this->event('conference.participant.joined', 'origin-3', ['conference_id' => 'conference-one']);
        $this->deleteJson('/api/voice/calls/'.$call->id.'/supervise')->assertOk();
        $this->assertNull($call->fresh()->ended_at);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/origin-2/actions/hangup'));
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'end'])->assertForbidden();
    }

    public function test_idempotent_outbound_replay_does_not_redial_and_changed_payload_conflicts(): void
    {
        $call = $this->outbound();
        $again = $this->outbound();
        $this->assertSame($call->id, $again->id);
        $this->assertSame(1, $this->dialCount);
        $this->postJson('/api/voice/calls/human', ['session_id' => $this->session->id, 'to' => '+12025550888', 'reason' => 'Booking help', 'idempotency_key' => 'outbound-one'])->assertConflict();
    }

    public function test_inbound_staff_timeout_falls_back_once_and_never_dials_customer(): void
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'incoming', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        $this->assertTrue(app(VoiceBrowserCallService::class)->offerInbound($call));
        $this->assertTrue(app(VoiceBrowserCallService::class)->offerInbound($call));
        $this->assertSame(1, $this->dialCount);
        $this->event('call.hangup', 'origin-1');
        $this->event('call.hangup', 'origin-1');
        $this->assertNull($call->fresh()->ended_at);
        $this->assertSame('human_handoff', $call->fresh()->status);
        Http::assertSentCount(3); // Staff dial, answer original caller, configured staff transfer.
    }

    public function test_transcript_requires_consent_and_only_customer_leg_audio_is_stored(): void
    {
        $call = $this->activeHuman();
        $call->update(['metadata' => ['browser_transcription_enabled' => true]]);
        $this->event('call.transcription', 'origin-2', ['transcription_data' => ['transcript' => 'Private text', 'is_final' => true]]);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        $call->update(['recording_consent_given' => true]);
        $this->event('call.transcription', 'origin-1', ['transcription_data' => ['transcript' => 'Do not store coach', 'is_final' => true]]);
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        $this->event('call.transcription', 'origin-2', ['transcription_track' => 'outbound', 'transcription_data' => ['transcript' => 'Staff reply', 'is_final' => true]]);
        $this->assertDatabaseHas('voice_call_transcripts', ['voice_call_id' => $call->id, 'speaker' => 'agent', 'text' => 'Staff reply']);
    }

    public function test_customer_hangup_ends_staff_and_late_join_does_not_revive_call(): void
    {
        $call = $this->activeHuman();
        $this->event('call.hangup', 'origin-2');
        $this->assertNotNull($call->fresh()->ended_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/origin-1/actions/hangup'));
        $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame(2, $this->dialCount);
    }

    public function test_ambiguous_staff_dial_retry_reuses_provider_command_and_never_dials_customer_early(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        $attempt = 0;
        Http::fake(function ($r) use (&$attempt) {
            if (++$attempt === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('network timeout');
            }

            return Http::response(['data' => ['call_control_id' => 'retry-agent', 'call_session_id' => 'retry-session']]);
        });
        $payload = ['session_id' => $this->session->id, 'to' => '+12025550200', 'idempotency_key' => 'uncertain-dial'];
        $this->postJson('/api/voice/calls/human', $payload)->assertStatus(502)->assertJsonPath('code', 'provider_unconfirmed');
        $command = \App\Models\VoiceBrowserCommand::firstOrFail();
        $this->assertSame('uncertain', $command->state);
        $this->postJson('/api/voice/calls/human', $payload)->assertOk();
        $this->assertDatabaseCount('voice_calls', 1);
        $this->assertDatabaseCount('voice_browser_commands', 1);
        Http::assertSent(fn ($r) => $r['command_id'] === $command->id && str_starts_with($r['to'], 'sip:'));
        $this->assertNull(VoiceCall::first()->call_control_id);
    }

    public function test_missing_staff_timeout_webhook_recovers_original_inbound_once(): void
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'incoming', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        app(VoiceBrowserCallService::class)->offerInbound($call);
        $this->travel(46)->seconds();
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertSame('human_handoff', $call->fresh()->status);
        $this->assertNull($call->fresh()->ended_at);
        $count = Http::recorded()->count();
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertCount($count, Http::recorded());
    }

    public function test_outer_webhook_preserves_customer_id_and_deduplicates_transcript_event(): void
    {
        $call = $this->activeHuman();
        $call->update(['recording_consent_given' => true, 'metadata' => ['browser_transcription_enabled' => true]]);
        $handler = app(\App\Services\Voice\TelnyxWebhookHandler::class);
        $staffEvent = ['data' => ['id' => 'staff-answer-event', 'event_type' => 'call.answered', 'payload' => ['call_control_id' => 'origin-1', 'connection_id' => 'server-app']]];
        $handler->process($staffEvent, json_encode($staffEvent));
        $this->assertSame('origin-2', $call->fresh()->call_control_id);
        $event = ['data' => ['id' => 'transcript-one', 'event_type' => 'call.transcription', 'payload' => ['call_control_id' => 'origin-2', 'transcription_data' => ['is_final' => true, 'transcript' => 'Only once']]]];
        $handler->process($event, json_encode($event));
        $handler->process($event, json_encode($event));
        $this->assertDatabaseCount('voice_call_transcripts', 1);
    }

    public function test_recording_and_transcription_retry_are_independent_and_stop_remains_available_after_revocation(): void
    {
        $call = $this->activeHuman();
        config(['services.telnyx.voice.recording_enabled' => true]);
        $this->patchJson('/api/voice/calls/'.$call->id.'/browser-consent', ['consented' => true])->assertOk();
        Http::swap(new \Illuminate\Http\Client\Factory);
        $transcriptionAttempts = 0;
        Http::fake(function ($r) use (&$transcriptionAttempts) {
            if (str_ends_with($r->url(), '/transcription_start') && ++$transcriptionAttempts === 1) {
                return Http::response(['errors' => [['detail' => 'temporary failure']]], 503);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'recording_start', 'idempotency_key' => 'start-one'])->assertStatus(502);
        $this->getJson('/api/voice/calls/'.$call->id.'/browser')->assertOk()->assertJsonPath('recording.active', true)
            ->assertJsonPath('recording.transcription_pending', true)->assertJsonPath('recording.transcription_active', false);
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'recording_start', 'idempotency_key' => 'retry-one'])->assertOk()->assertJsonPath('recording.transcription_active', true);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/record_start')));
        $ids = Http::recorded(fn ($r) => str_ends_with($r->url(), '/transcription_start'))->map(fn ($pair) => $pair[0]['command_id'])->all();
        $this->assertCount(1, array_unique($ids));
        config(['services.telnyx.voice.browser_enabled' => false, 'services.telnyx.voice.recording_enabled' => false]);
        $call->refresh()->update(['recording_consent_given' => false, 'metadata' => array_merge($call->metadata, ['recording_stop_pending' => true])]);
        $this->getJson('/api/voice/calls/'.$call->id.'/browser')->assertOk()->assertJsonPath('capabilities.can_end', true)->assertJsonPath('recording.active', true);
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'recording_stop'])->assertOk()->assertJsonPath('recording.stop_pending', false)->assertJsonPath('recording.active', false);
    }

    public function test_takeover_join_failure_is_retryable_without_hanging_up_customer(): void
    {
        $call = $this->aiCall();
        $this->postJson('/api/voice/calls/'.$call->id.'/takeover', ['session_id' => $this->session->id, 'idempotency_key' => 'takeover-one'])->assertOk();
        $this->event('call.answered', 'origin-1');
        Http::swap(new \Illuminate\Http\Client\Factory);
        $joins = 0;
        Http::fake(function ($r) use (&$joins) {
            if (str_ends_with($r->url(), '/actions/join') && ++$joins === 1) {
                return Http::response([], 503);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
        try {
            $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
            $this->fail('The provider failure must remain visible.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('phone provider', $e->getMessage());
        }
        $this->assertNull($call->fresh()->ended_at);
        $this->assertTrue(VoiceBrowserCall::first()->metadata['ai_stopped']);
        $this->travel(31)->seconds();
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertSame(2, $joins);
        $ids = Http::recorded(fn ($r) => str_ends_with($r->url(), '/actions/join'))->map(fn ($pair) => $pair[0]['command_id'])->all();
        $this->assertCount(1, array_unique($ids));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/customer-ai/actions/hangup'));
        $this->event('conference.participant.joined', 'customer-ai', ['conference_id' => 'conference-one']);
        $this->assertSame('active', VoiceBrowserCall::first()->state);
    }

    public function test_operator_without_supervise_permission_cannot_monitor_or_use_another_owners_controls(): void
    {
        $call = $this->activeHuman();
        $operator = User::factory()->photographer()->create(['permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => ['voice-calls-supervise']]]);
        $session = $this->sessionFor($operator, 'gencredOther');
        $this->actingAs($operator, 'sanctum')->postJson('/api/voice/calls/'.$call->id.'/supervise', ['session_id' => $session->id, 'mode' => 'monitor', 'idempotency_key' => 'denied'])->assertForbidden();
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'end'])->assertForbidden();
        $this->assertNull($call->fresh()->ended_at);
        $this->assertSame(2, $this->dialCount);
    }

    public function test_takeover_recovery_does_not_resume_ai_after_number_policy_is_disabled(): void
    {
        $call = $this->aiCall();
        $this->postJson('/api/voice/calls/'.$call->id.'/takeover', ['session_id' => $this->session->id, 'idempotency_key' => 'takeover-one'])->assertOk();
        $this->event('call.answered', 'origin-1');
        $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
        config(['services.telnyx.voice.enabled' => false, 'services.telnyx.voice.support_handoff_number' => null]);
        $this->event('call.hangup', 'origin-1');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/ai_assistant_start'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/customer-ai/actions/gather_using_speak'));
        $this->assertNull($call->fresh()->ended_at);
    }

    public function test_conference_end_cleans_up_customer_but_waits_for_real_hangup_confirmation(): void
    {
        $call = $this->activeHuman();
        $this->event('conference.ended', 'origin-1', ['conference_id' => 'conference-one']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/origin-2/actions/hangup'));
        $this->assertSame('ending', VoiceBrowserCall::first()->state);
        $this->assertNull($call->fresh()->ended_at);
        $this->event('call.hangup', 'origin-2');
        $this->assertNotNull($call->fresh()->ended_at);
    }

    public function test_customer_leaving_conference_for_transfer_is_not_a_hangup(): void
    {
        $call = $this->activeHuman();
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'transfer', 'to' => '+12025550999', 'idempotency_key' => 'transfer-one'])->assertOk();
        $this->event('conference.participant.left', 'origin-2', ['conference_id' => 'conference-one']);
        $this->event('conference.ended', 'origin-1', ['conference_id' => 'conference-one']);
        $this->assertNull($call->fresh()->ended_at);
        $this->assertSame('transferring', VoiceBrowserCall::first()->state);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/origin-2/actions/hangup'));
        $this->event('call.transferred', 'origin-2');
        $this->assertSame('transferred', $call->fresh()->status);
        $this->assertNull($call->fresh()->ended_at);
    }

    public function test_failed_transfer_rejoins_departed_customer_with_a_new_command_generation(): void
    {
        $call = $this->activeHuman();
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'transfer', 'to' => '+12025550999', 'idempotency_key' => 'transfer-one'])->assertOk();
        $this->event('conference.participant.left', 'origin-2', ['conference_id' => 'conference-one']);
        $this->event('call.transfer.failed', 'origin-2');
        $this->assertSame('connecting_customer', VoiceBrowserCall::first()->state);
        $joins = Http::recorded(fn ($r) => str_ends_with($r->url(), '/actions/join') && $r['call_control_id'] === 'origin-2');
        $this->assertCount(2, $joins);
        $this->assertCount(2, array_unique($joins->map(fn ($pair) => $pair[0]['command_id'])->all()));
        $this->assertNull($call->fresh()->ended_at);
        $this->event('conference.participant.joined', 'origin-2', ['conference_id' => 'conference-one']);
        $this->assertSame('active', VoiceBrowserCall::first()->state);
    }

    private function sessionFor(User $user, string $sip = 'gencredTest'): VoiceBrowserSession
    {
        return VoiceBrowserSession::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'credential-'.$user->id, 'sip_username' => $sip,
            'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
    }

    private function outbound(): VoiceCall
    {
        $response = $this->postJson('/api/voice/calls/human', ['session_id' => $this->session->id, 'to' => '+12025550200', 'reason' => 'Booking help', 'idempotency_key' => 'outbound-one']);
        $this->assertSame(200, $response->status(), $response->getContent());

        return VoiceCall::findOrFail($response->json('id'));
    }

    private function activeHuman(): VoiceCall
    {
        $call = $this->outbound();
        $this->event('call.answered', 'origin-1');
        $this->event('conference.participant.joined', 'origin-1', ['conference_id' => 'conference-one']);
        $this->event('call.answered', 'origin-2');
        $this->event('conference.participant.joined', 'origin-2', ['conference_id' => 'conference-one']);

        return $call->fresh();
    }

    private function aiCall(): VoiceCall
    {
        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active', 'handled_by' => 'ai', 'assistant_id' => 'assistant-one',
            'call_control_id' => 'customer-ai', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100', 'answered_at' => now()->subMinute()]);
    }

    private function event(string $type, string $control, array $payload = []): ?array
    {
        return app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => $type,
            'payload' => array_merge(['call_control_id' => $control, 'connection_id' => 'server-app'], $payload)]]);
    }
}
