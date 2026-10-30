<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceMobileOffer;
use App\Models\VoiceMobileSession;
use App\Services\Voice\VoiceBrowserCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoiceMobileTranscriptionControlTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private PersonalAccessToken $token;

    private VoiceMobileSession $session;

    private VoiceCall $voiceCall;

    private VoiceCall $aiCall;

    private VoiceBrowserCall $bridge;

    private VoiceBrowserLeg $agent;

    private VoiceBrowserLeg $customer;

    private VoiceLinePolicy $policy;

    private string $sdk;

    private int $failStarts = 0;

    private int $failStops = 0;

    private bool $hangupDuringStart = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_voice.enabled' => true,
            'mobile_voice.push_verified' => true, 'mobile_voice.bundle_id' => 'com.example.repro',
            'mobile_voice.push_credentials.debug' => 'test-push', 'services.telnyx.api_key' => 'fake',
            'services.telnyx.api_base' => 'https://example.test/v2', 'services.telnyx.voice.enabled' => true,
            'services.telnyx.from_number' => '+12025550100', 'services.telnyx.voice.browser_enabled' => true,
            'services.telnyx.voice.connection_id' => 'server', 'services.telnyx.voice.credential_connection_id' => 'browser',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice', 'services.voice.canary_mode' => false,
            'services.telnyx.voice.recording_enabled' => true, 'sanctum.expiration' => null]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() !== 'POST' || ! preg_match('#^/v2/calls/[^/]+/actions/(transcription_start|transcription_stop|record_start|record_stop|hangup)$#', $path)) {
                throw new \RuntimeException('Unexpected provider operation in transcription control test.');
            }
            if ((str_ends_with($path, '/transcription_start') && $this->failStarts-- > 0)
                || (str_ends_with($path, '/transcription_stop') && $this->failStops-- > 0)) {
                return Http::response(['errors' => [['detail' => 'Unconfirmed test command']]], 503);
            }
            if ($this->hangupDuringStart && str_ends_with($path, '/transcription_start')) {
                $this->voiceCall->update(['ended_at' => now(), 'status' => 'completed']);
                $this->bridge->update(['state' => 'ended']);
                $this->agent->update(['state' => 'ended', 'ended_at' => now()]);
                $this->customer->update(['state' => 'ended', 'ended_at' => now()]);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
        $this->staff = User::factory()->create(['role' => 'admin']);
        $this->token = $this->signIn($this->staff);
        $this->session = VoiceMobileSession::create(['user_id' => $this->staff->id, 'personal_access_token_id' => $this->token->id,
            'device_id' => (string) Str::uuid(), 'apns_environment' => 'debug', 'bundle_id' => 'com.example.repro',
            'status' => 'ready', 'expires_at' => now()->addHour(), 'registration_expires_at' => now()->addDay(),
            'connection_username' => 'native-transcript', 'connection_id' => 'native-connection',
            'credential_id' => 'native-credential', 'sip_username' => 'gencredTranscript']);
        $this->policy = VoiceLinePolicy::create(['phone_number' => '+12025550100', 'mode' => 'staff_voicemail',
            'recipient_role' => null, 'recipient_roles' => [], 'recipient_ids' => [$this->staff->id], 'timezone' => 'UTC', 'weekly_hours' => []]);
        // The AI-originating call is a different canonical call, like live calls 97 and 98.
        $this->aiCall = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'active',
            'handled_by' => 'ai', 'from_phone' => '+12025550101', 'to_phone' => '+12025550100',
            'call_control_id' => 'separate-ai-control', 'transcript' => 'Existing separate AI transcript.']);
        $this->voiceCall = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'active',
            'handled_by' => 'human', 'from_phone' => '+12025550199', 'to_phone' => '+12025550100',
            'call_control_id' => 'received-customer', 'answered_at' => now()->subMinute(), 'recording_consent_given' => false]);
        $this->bridge = VoiceBrowserCall::create(['voice_call_id' => $this->voiceCall->id, 'owner_id' => $this->staff->id,
            'session_id' => null, 'mode' => 'human_inbound', 'state' => 'active', 'conference_id' => 'received-conference',
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'received-call')]);
        $this->agent = $this->leg('agent', 'native-agent', ['mode' => 'mobile', 'mobile_session_id' => $this->session->id, 'user_id' => $this->staff->id]);
        $this->customer = $this->leg('customer', 'received-customer');
        $incoming = VoiceIncomingOffer::create(['voice_call_id' => $this->voiceCall->id, 'status' => 'claimed',
            'eligible_user_ids' => [$this->staff->id], 'claimed_by_id' => $this->staff->id,
            'browser_call_id' => $this->bridge->id, 'expires_at' => now()->addMinute(), 'claimed_at' => now()]);
        $this->sdk = (string) Str::uuid();
        $offer = VoiceMobileOffer::create(['session_id' => $this->session->id, 'incoming_offer_id' => $incoming->id,
            'sdk_call_id' => $this->sdk, 'state' => 'accepted', 'call_control_id' => 'native-agent',
            'client_state' => (string) Str::uuid(), 'expires_at' => now()->addMinute(), 'accepted_at' => now()]);
        $incoming->update(['claim_key' => 'mobile:'.$offer->id]);
    }

    public function test_native_start_exposes_canonical_state_without_starting_recording_and_is_idempotent(): void
    {
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('voice_call_id', $this->voiceCall->id)
            ->assertJsonPath('transcription.state', 'off')->assertJsonPath('transcription.available', true)
            ->assertJsonPath('transcription.enabled', false)->assertJsonPath('transcription.consent_given', false);
        $key = (string) Str::uuid();
        $this->control('start', $key)->assertOk()->assertJsonPath('voice_call_id', $this->voiceCall->id)
            ->assertJsonPath('state', 'active')->assertJsonPath('transcription.state', 'active')
            ->assertJsonPath('transcription.enabled', true)->assertJsonPath('transcription.consent_given', true)
            ->assertJsonPath('transcription.error', null);
        $this->control('start', $key)->assertOk();
        $this->control('start')->assertOk();
        $this->control('stop', $key)->assertStatus(409);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'active');

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/received-customer/actions/transcription_start')
            && $r['transcription_tracks'] === 'both');
        $this->assertFalse($this->voiceCall->fresh()->recording_consent_given);
        $this->assertNull($this->voiceCall->fresh()->recording_url);
        $this->assertNull(data_get($this->voiceCall->fresh()->metadata, 'recording_started_at'));
    }

    public function test_start_requires_explicit_consent_even_when_recording_consent_already_exists(): void
    {
        $this->voiceCall->update(['recording_consent_given' => true]);
        foreach ([[], ['consented' => false], ['consented' => null]] as $consent) {
            $this->postJson($this->nativePath(), array_merge(['action' => 'start', 'idempotency_key' => (string) Str::uuid()], $consent))->assertStatus(422);
        }
        $this->postJson($this->nativePath(), ['action' => 'start', 'consented' => true, 'idempotency_key' => 'invalid'])->assertStatus(422);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'off');
        Http::assertNothingSent();
    }

    #[DataProvider('deniedNativeContexts')]
    public function test_native_authorization_rejects_before_provider(string $context, int $status): void
    {
        match ($context) {
            'other_user' => $this->signIn(User::factory()->create(['role' => 'admin'])),
            'different_login' => $this->signIn($this->staff),
            'expired_login' => $this->token->update(['expires_at' => now()->subMinute()]),
            'revoked' => $this->session->update(['revoked_at' => now()]),
            'wrong_sdk' => $this->sdk = (string) Str::uuid(),
            'line_unassigned' => $this->policy->update(['recipient_ids' => []]),
            'operate_removed' => $this->staff->update(['permission_overrides' => ['allow' => [], 'deny' => ['voice-calls-operate']]]),
            'agent_ended' => $this->agent->update(['ended_at' => now(), 'state' => 'ended']),
            'ended' => $this->voiceCall->update(['ended_at' => now(), 'status' => 'completed']),
        };
        $this->control('start')->assertStatus($status);
        Http::assertNothingSent();
        $this->assertFalse($this->voiceCall->fresh()->recording_consent_given);
    }

    public static function deniedNativeContexts(): array
    {
        return ['other user' => ['other_user', 404], 'different login' => ['different_login', 403],
            'expired login' => ['expired_login', 401], 'revoked session' => ['revoked', 403], 'unknown SDK call' => ['wrong_sdk', 404],
            'line reassigned' => ['line_unassigned', 403], 'operate removed' => ['operate_removed', 403],
            'agent disconnected' => ['agent_ended', 409], 'ended call' => ['ended', 409]];
    }

    public function test_failed_start_is_reported_and_same_operation_retries_the_stable_provider_command(): void
    {
        $this->failStarts = 1;
        $key = (string) Str::uuid();
        $this->control('start', $key)->assertStatus(503);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'error')
            ->assertJsonPath('transcription.enabled', false)->assertJsonPath('transcription.error', 'start_unconfirmed');
        $this->control('start', $key)->assertOk()->assertJsonPath('transcription.state', 'active');
        $ids = $this->commandIds('transcription_start');
        $this->assertCount(2, $ids);
        $this->assertCount(1, array_unique($ids));
        $this->assertTrue(Str::isUuid($ids[0]));
        $this->assertFalse($this->voiceCall->fresh()->recording_consent_given);
    }

    public function test_uncertain_start_requires_confirmed_stop_before_a_new_start_generation(): void
    {
        $this->failStarts = 1;
        $originalStart = (string) Str::uuid();
        $this->control('start', $originalStart)->assertStatus(503);
        $this->control('start')->assertStatus(409);
        $this->failStops = 1;
        $stop = (string) Str::uuid();
        $this->control('stop', $stop)->assertStatus(503);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'error')
            ->assertJsonPath('transcription.error', 'stop_unconfirmed');
        $this->control('start')->assertStatus(409);
        $this->control('stop', $stop)->assertOk()->assertJsonPath('transcription.state', 'off')
            ->assertJsonPath('transcription.enabled', false);
        $this->control('start', $originalStart)->assertStatus(409);
        $this->control('start')->assertOk()->assertJsonPath('transcription.state', 'active');
        $this->assertCount(2, $this->commandIds('transcription_stop'));
        $this->assertCount(1, array_unique($this->commandIds('transcription_stop')));
        $this->assertCount(2, array_unique($this->commandIds('transcription_start')));
        Http::assertSentCount(4);
    }

    public function test_historical_session_cannot_control_replacement_agent_but_current_session_can(): void
    {
        $replacement = $this->session->replicate();
        $replacement->fill(['device_id' => (string) Str::uuid(), 'connection_username' => 'replacement-native',
            'connection_id' => 'replacement-connection', 'credential_id' => 'replacement-credential',
            'sip_username' => 'gencredReplacement'])->save();
        $this->agent->update(['state' => 'ended', 'ended_at' => now()]);
        $this->leg('agent', 'replacement-agent', ['mode' => 'mobile', 'mobile_session_id' => $replacement->id, 'user_id' => $this->staff->id]);
        $incoming = VoiceIncomingOffer::where('voice_call_id', $this->voiceCall->id)->firstOrFail();
        $newSdk = (string) Str::uuid();
        $offer = VoiceMobileOffer::create(['session_id' => $replacement->id, 'incoming_offer_id' => $incoming->id,
            'sdk_call_id' => $newSdk, 'state' => 'accepted', 'call_control_id' => 'replacement-agent',
            'client_state' => (string) Str::uuid(), 'expires_at' => now()->addMinute(), 'accepted_at' => now()]);
        $incoming->update(['claim_key' => 'mobile:'.$offer->id]);

        // The old login/session is still valid and retains its historical leg.
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('voice_call_id', $this->voiceCall->id)
            ->assertJsonPath('transcription.available', false);
        $this->control('start')->assertForbidden();
        $this->control('stop')->assertForbidden();
        Http::assertNothingSent();

        $this->session = $replacement;
        $this->sdk = $newSdk;
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.available', true);
        $this->control('start')->assertOk()->assertJsonPath('transcription.state', 'active');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/received-customer/actions/transcription_start'));
    }

    #[DataProvider('mismatchedOfferBindings')]
    public function test_native_transcription_requires_offer_control_and_winning_claim_binding(string $binding): void
    {
        $offer = VoiceMobileOffer::where('session_id', $this->session->id)->where('sdk_call_id', $this->sdk)->firstOrFail();
        if ($binding === 'control') {
            $offer->update(['call_control_id' => 'unrelated-server-control']);
        } else {
            $offer->incomingOffer->update(['claim_key' => 'mobile:'.Str::uuid()]);
        }

        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.available', false);
        $this->control('start')->assertForbidden();
        $this->control('stop')->assertForbidden();
        Http::assertNothingSent();
    }

    public static function mismatchedOfferBindings(): array
    {
        return ['server control mismatch' => ['control'], 'winning claim mismatch' => ['claim']];
    }

    public function test_hangup_during_provider_start_response_returns_ended_without_reviving_capture(): void
    {
        $this->hangupDuringStart = true;
        $this->control('start')->assertOk()->assertJsonPath('voice_call_id', $this->voiceCall->id)
            ->assertJsonPath('transcription.state', 'ended')->assertJsonPath('transcription.enabled', false)
            ->assertJsonPath('transcription.available', false);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'ended')
            ->assertJsonPath('transcription.enabled', false)->assertJsonPath('transcription.available', false);
        $this->assertSame('completed', $this->voiceCall->fresh()->status);
        $this->assertNotNull($this->voiceCall->fresh()->ended_at);
        $this->assertSame('ended', $this->bridge->fresh()->state);
        $this->assertNotNull($this->agent->fresh()->ended_at);
        $this->assertFalse($this->voiceCall->fresh()->recording_consent_given);
        $this->control('start')->assertStatus(409);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/received-customer/actions/transcription_start'));
    }

    public function test_customer_leg_transcript_uses_dedicated_consent_and_never_mixes_ai_or_private_agent_audio(): void
    {
        $this->speech('received-customer', 'Before consent');
        $this->assertDatabaseCount('voice_call_transcripts', 0);
        $this->control('start')->assertOk();
        $this->speech('received-customer', 'Customer question', 'inbound');
        $this->speech('received-customer', 'Staff answer', 'outbound');
        $this->speech('native-agent', 'Private staff audio', 'inbound');
        $this->speech('separate-ai-control', 'Other call text');
        $this->speech('received-customer', 'Interim text', 'inbound', false);

        $this->assertDatabaseCount('voice_call_transcripts', 2);
        $this->assertDatabaseHas('voice_call_transcripts', ['voice_call_id' => $this->voiceCall->id, 'speaker' => 'customer', 'text' => 'Customer question']);
        $this->assertDatabaseHas('voice_call_transcripts', ['voice_call_id' => $this->voiceCall->id, 'speaker' => 'agent', 'text' => 'Staff answer']);
        $this->assertSame('Existing separate AI transcript.', $this->aiCall->fresh()->transcript);
        $this->assertFalse($this->voiceCall->fresh()->recording_consent_given);
        $this->getJson('/api/voice/calls/'.$this->voiceCall->id.'/transcript')->assertOk()
            ->assertJsonPath('transcript', "customer: Customer question\nagent: Staff answer")->assertJsonPath('segment_count', 2);
        Http::assertSentCount(1);
    }

    public function test_final_segment_after_normal_hangup_is_saved_without_reopening_the_call(): void
    {
        $this->control('start')->assertOk();
        $endedAt = now()->subSecond();
        $this->voiceCall->update(['ended_at' => $endedAt, 'status' => 'completed']);
        $this->bridge->update(['state' => 'ended']);
        $this->customer->update(['state' => 'ended', 'ended_at' => $endedAt]);
        $this->agent->update(['state' => 'ended', 'ended_at' => $endedAt]);
        $this->speech('received-customer', 'Final goodbye');
        $this->assertDatabaseHas('voice_call_transcripts', ['voice_call_id' => $this->voiceCall->id, 'text' => 'Final goodbye']);
        $this->assertSame('completed', $this->voiceCall->fresh()->status);
        $this->assertSame('ended', $this->bridge->fresh()->state);
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'ended')
            ->assertJsonPath('transcription.available', false);
        Http::assertSentCount(1);
    }

    public function test_explicit_stop_drops_late_segments_even_if_recording_consent_is_true(): void
    {
        $this->control('start')->assertOk();
        $this->speech('received-customer', 'Before stop');
        $this->voiceCall->update(['recording_consent_given' => true]);
        $stop = (string) Str::uuid();
        $this->control('stop', $stop)->assertOk()->assertJsonPath('transcription.state', 'off');
        $this->control('stop', $stop)->assertOk();
        $this->speech('received-customer', 'After explicit stop');
        $this->assertDatabaseCount('voice_call_transcripts', 1);
        $this->assertSame('customer: Before stop', $this->voiceCall->fresh()->transcript);
        $this->assertTrue($this->voiceCall->fresh()->recording_consent_given);
        Http::assertSentCount(2);
    }

    public function test_shared_browser_endpoint_has_same_transcription_contract_and_requires_call_ownership(): void
    {
        $browser = VoiceBrowserSession::create(['user_id' => $this->staff->id, 'device_id' => (string) Str::uuid(),
            'credential_id' => 'browser-credential', 'sip_username' => 'gencredBrowser', 'status' => 'ready',
            'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
        $this->bridge->update(['session_id' => $browser->id]);
        $this->agent->update(['session_id' => $browser->id, 'mobile_session_id' => null, 'mode' => 'browser']);
        $path = '/api/voice/calls/'.$this->voiceCall->id.'/transcription';
        $this->postJson($path, ['action' => 'start', 'consented' => true, 'idempotency_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('voice_call_id', $this->voiceCall->id)->assertJsonPath('transcription.state', 'active');
        $this->signIn(User::factory()->create(['role' => 'superadmin']));
        $this->postJson($path, ['action' => 'stop', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_legacy_recording_stop_preserves_independent_transcription_and_start_respects_explicit_stop(): void
    {
        $this->control('start')->assertOk();
        $this->voiceCall->refresh()->update(['recording_consent_given' => true,
            'metadata' => array_merge($this->voiceCall->metadata ?? [], ['recording_started_at' => now()->toIso8601String()])]);
        $path = '/api/voice/calls/'.$this->voiceCall->id.'/browser-actions';
        $this->postJson($path, ['action' => 'recording_stop', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'active');
        $this->assertCount(0, $this->commandIds('transcription_stop'));
        $this->assertCount(1, $this->commandIds('record_stop'));

        $this->control('stop')->assertOk();
        $this->postJson($path, ['action' => 'recording_start', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->getJson($this->nativePath('state'))->assertOk()->assertJsonPath('transcription.state', 'off');
        $this->assertCount(1, $this->commandIds('transcription_start'));
        $this->assertCount(1, $this->commandIds('record_start'));
    }

    private function signIn(User $user): PersonalAccessToken
    {
        $token = $user->createToken('transcription-test', ['*'], now()->addDays(2))->accessToken;
        $this->actingAs($user->withAccessToken($token), 'sanctum');

        return $token;
    }

    private function leg(string $role, string $control, array $extra = []): VoiceBrowserLeg
    {
        return VoiceBrowserLeg::create(array_merge(['browser_call_id' => $this->bridge->id, 'role' => $role,
            'call_control_id' => $control, 'client_state' => (string) Str::uuid(), 'destination' => '+12025550199',
            'state' => 'joined', 'answered_at' => now()->subMinute(), 'joined_at' => now()->subMinute()], $extra));
    }

    private function nativePath(string $suffix = 'transcription'): string
    {
        return '/api/voice/mobile/sessions/'.$this->session->id.'/calls/'.$this->sdk.'/'.$suffix;
    }

    private function control(string $action, ?string $key = null)
    {
        return $this->postJson($this->nativePath(), array_merge(['action' => $action, 'idempotency_key' => $key ?? (string) Str::uuid()],
            $action === 'start' ? ['consented' => true] : []));
    }

    private function commandIds(string $action): array
    {
        return Http::recorded(fn ($r) => str_ends_with($r->url(), '/actions/'.$action))->map(fn ($pair) => $pair[0]['command_id'])->all();
    }

    private function speech(string $control, string $text, string $track = 'inbound', bool $final = true): ?array
    {
        return app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => 'call.transcription',
            'payload' => ['call_control_id' => $control, 'connection_id' => 'server',
                'transcription_data' => ['transcript' => $text, 'is_final' => $final, 'transcription_track' => $track]]]]);
    }
}
