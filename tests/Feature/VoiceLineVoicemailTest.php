<?php

namespace Tests\Feature;

use App\Jobs\StoreVoiceVoicemail;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceStaffPhone;
use App\Models\VoiceVoicemail;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Services\Voice\VoiceBrowserGateway;
use App\Services\Voice\VoiceIncomingOfferService;
use App\Services\Voice\VoiceLinePolicyService;
use App\Services\Voice\VoiceVoicemailService;
use App\Services\Voice\VoiceVoicemailStorage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceLineVoicemailTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private VoiceLinePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Asia/Kolkata'));
        config(['services.telnyx.api_key' => 'test', 'services.telnyx.voice.browser_enabled' => true,
            'services.telnyx.voice.credential_connection_id' => 'browser', 'services.telnyx.voice.connection_id' => 'server',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice', 'services.telnyx.voice.enabled' => true,
            'services.telnyx.voice.support_handoff_number' => '+12025550999', 'services.voice.canary_mode' => false]);
        Queue::fake();
        Storage::fake('local');
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'staff-leg']])]);
        $this->staff = User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []]]);
        Storage::disk('local')->put('voice/greetings/office.mp3', 'office-audio');
        Storage::disk('local')->put('voice/greetings/closed.mp3', 'closed-audio');
        $this->policy = VoiceLinePolicy::create(['phone_number' => '+12027803332', 'mode' => 'staff_voicemail',
            'recipient_role' => 'editing_manager', 'recipient_ids' => [], 'timezone' => 'Asia/Kolkata',
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['start' => '09:00', 'end' => '18:00']]),
            'office_greeting' => 'voice/greetings/office.mp3', 'closed_greeting' => 'voice/greetings/closed.mp3']);
    }

    public function test_timezone_windows_weekends_and_dst_boundaries(): void
    {
        $service = app(VoiceLinePolicyService::class);
        foreach ([['America/New_York', '2026-03-06T13:59:00Z', false], ['America/New_York', '2026-03-06T14:00:00Z', true],
            ['America/New_York', '2026-03-09T13:00:00Z', true], ['America/New_York', '2026-11-02T13:59:00Z', false],
            ['America/New_York', '2026-11-02T14:00:00Z', true], ['Europe/London', '2026-03-27T09:00:00Z', true],
            ['Europe/London', '2026-03-30T08:00:00Z', true], ['Europe/London', '2026-10-26T08:59:00Z', false],
            ['Europe/London', '2026-10-26T09:00:00Z', true], ['Asia/Kolkata', '2026-10-14T12:30:00Z', false],
            ['Asia/Kolkata', '2026-10-17T05:00:00Z', false]] as [$zone, $instant, $expected]) {
            $this->policy->timezone = $zone;
            $this->assertSame($expected, $service->open($this->policy, CarbonImmutable::parse($instant)), $zone.' '.$instant);
        }
    }

    public function test_only_assigned_registered_dashboard_staff_ring_and_personal_claim_is_denied(): void
    {
        $device = $this->device($this->staff);
        $other = User::factory()->create(['role' => 'admin']);
        $this->device($other);
        $call = $this->incomingCall();
        $this->route($call);
        $offer = VoiceIncomingOffer::sole();
        $this->assertSame([$this->staff->id], $offer->eligible_user_ids);
        $this->assertEquals(45, $offer->created_at->diffInSeconds($offer->expires_at));
        $this->assertNull($call->fresh()->voicemail);
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'phone', 'idempotency_key' => 'phone-attempt', 'session_id' => $device->id,
        ])->assertStatus(422);
        $this->travel(9)->seconds();
        app(VoiceIncomingOfferService::class)->reconcile($offer->id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v2/calls'));
    }

    public function test_unregistered_staff_personal_number_does_not_prevent_voicemail(): void
    {
        VoiceStaffPhone::create(['user_id' => $this->staff->id, 'phone' => '+12025550222', 'verified_at' => now(), 'available' => true, 'phone_enabled' => true]);
        $call = $this->incomingCall();
        $this->route($call);
        $this->assertSame('office', $call->fresh()->voicemail->greeting_kind);
        $this->assertDatabaseCount('voice_incoming_offers', 0);
        $this->assertNoAiOrForward();
    }

    public function test_closed_line_skips_reachable_staff_and_uses_closed_greeting(): void
    {
        $this->device($this->staff);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00:00', 'Asia/Kolkata'));
        $call = $this->incomingCall();
        $this->route($call);
        $this->event($call, 'call.answered');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/actions/playback_start') && $r['playback_content'] === base64_encode('closed-audio'));
        $this->assertDatabaseCount('voice_incoming_offers', 0);
        $this->assertNoAiOrForward();
    }

    public function test_unanswered_offer_expires_to_office_voicemail(): void
    {
        $this->device($this->staff);
        $call = $this->incomingCall();
        $this->route($call);
        $offer = VoiceIncomingOffer::sole();
        $this->travel(46)->seconds();
        app(VoiceIncomingOfferService::class)->reconcile($offer->id);
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('office', $call->fresh()->voicemail->greeting_kind);
        $this->assertNoAiOrForward();
    }

    public function test_greeting_completion_starts_one_bounded_recording_and_hangup_does_not_lose_late_saved_event(): void
    {
        $call = $this->incomingCall();
        $this->route($call);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/actions/playback_start'));
        $this->event($call, 'call.answered');
        $vm = $call->fresh()->voicemail;
        $this->event($call, 'call.playback.ended', 'greeting');
        $this->event($call, 'call.playback.ended', 'greeting');
        $this->event($call, 'call.answered');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/actions/record_start') && $r['max_length'] === 180 && $r['timeout_secs'] === 5 && $r['play_beep'] && $r['recording_track'] === 'inbound');
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/actions/record_start')));
        $this->assertFalse($call->fresh()->recording_consent_given);
        $this->event($call, 'call.hangup', 'recording');
        $this->assertSame('finalizing', $vm->fresh()->state);
        $this->event($call, 'call.recording.saved', 'recording', ['recording_id' => 'provider-recording', 'duration_seconds' => 12]);
        $this->assertSame('storing', $vm->fresh()->state);
        Queue::assertPushed(StoreVoiceVoicemail::class, 1);
        $this->event($call, 'call.recording.error', 'recording');
        $this->event($call, 'call.recording.saved', 'recording', ['recording_id' => 'second-recording']);
        $this->assertSame('provider-recording', $vm->fresh()->recording_id);
        $this->assertNoAiOrForward();
    }

    public function test_playback_failure_uses_spoken_fallback_before_beep_and_recording_error_creates_manual_task(): void
    {
        $call = $this->incomingCall();
        $this->route($call);
        $this->event($call, 'call.answered');
        $this->event($call, 'call.playback.ended', 'greeting', ['status' => 'failed']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/actions/speak'));
        $this->event($call, 'call.playback.ended', 'greeting'); // delayed completion cannot start recording
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/actions/record_start'));
        $this->event($call, 'call.speak.ended', 'fallback');
        $this->event($call, 'call.recording.error', 'recording');
        $this->event($call, 'call.recording.error', 'recording');
        $this->assertSame('failed', $call->fresh()->voicemail->state);
        $this->assertDatabaseCount('voice_follow_up_tasks', 1);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
    }

    public function test_private_audio_and_call_details_require_line_assignment_and_retention_is_enforced(): void
    {
        $call = $this->incomingCall();
        $this->route($call);
        $vm = $call->fresh()->voicemail;
        Storage::disk('local')->put('voice/voicemails/message.mp3', 'message-audio');
        $vm->update(['state' => 'saved', 'saved_at' => now(), 'recording_path' => 'voice/voicemails/message.mp3', 'recording_id' => 'recording-private']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other, 'sanctum')->getJson('/api/voice/calls/'.$call->id)->assertForbidden();
        $this->get('/api/voice/calls/'.$call->id.'/voicemail/audio')->assertForbidden();
        $this->getJson('/api/voice/calls?filter=voicemail')->assertJsonCount(0, 'data');
        $this->assertCount(0, app(VoiceVoicemailService::class)->notifications($other, 30));
        $this->actingAs($this->staff, 'sanctum')->get('/api/voice/calls/'.$call->id.'/voicemail/audio')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/voice/calls/'.$call->id)->assertOk()->assertJsonMissingPath('voicemail.recording_path');
        $this->assertCount(1, app(VoiceVoicemailService::class)->notifications($this->staff, 30));
        $this->patchJson('/api/voice/calls/'.$call->id.'/voicemail', ['read' => true, 'resolved' => true])->assertOk();
        $this->assertFalse($call->fresh()->needs_follow_up);
        $super = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($super, 'sanctum')->get('/api/voice/calls/'.$call->id.'/voicemail/audio')->assertOk();
        $this->travel(91)->days();
        $this->get('/api/voice/calls/'.$call->id.'/voicemail/audio')->assertStatus(410);
        app(VoiceVoicemailService::class)->maintain();
        Storage::disk('local')->assertMissing('voice/voicemails/message.mp3');
        $this->assertSame('recording-private', $vm->fresh()->recording_id);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/recordings/recording-private'));
    }

    public function test_number_policy_is_shared_by_duplicates_and_robbie_cannot_be_given_voice_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $number = SmsNumber::create(['phone_number' => '2027803332', 'provider' => 'TELNYX', 'owner_type' => 'SYSTEM', 'is_active' => true]);
        SmsNumber::create(['phone_number' => '+1 (202) 780-3332', 'provider' => 'TELNYX', 'owner_type' => 'SYSTEM', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/voice/numbers/'.$number->id, ['line_policy' => [
            'mode' => 'staff_voicemail', 'timezone' => 'Europe/London', 'recipient_role' => null, 'recipient_ids' => [$this->staff->id], 'weekly_hours' => $this->policy->weekly_hours,
        ]])->assertOk();
        $this->assertDatabaseCount('voice_line_policies', 1);
        $this->assertSame('Europe/London', app(VoiceLinePolicyService::class)->forNumber('+1 (202) 780-3332')->timezone);
        $robbie = SmsNumber::create(['phone_number' => '+18888041663', 'provider' => 'TELNYX', 'owner_type' => 'SYSTEM']);
        $this->patchJson('/api/voice/numbers/'.$robbie->id, ['line_policy' => [
            'mode' => 'staff_voicemail', 'timezone' => 'UTC', 'recipient_ids' => [], 'weekly_hours' => [],
        ]])->assertStatus(422);
    }

    public function test_recording_origin_rejects_redirect_targets_arbitrary_hosts_and_credentials(): void
    {
        $storage = app(VoiceVoicemailStorage::class);
        $this->assertTrue($storage->allowedUrl('https://s3.amazonaws.com/telephony-recorder-prod/test.mp3?signature=x'));
        foreach (['https://127.0.0.1/a', 'https://s3.amazonaws.com/another-bucket/test', 'https://user@s3.amazonaws.com/telephony-recorder-prod/a',
            'http://s3.amazonaws.com/telephony-recorder-prod/a', 'https://s3.amazonaws.com/telephony-recorder-prod/%2e%2e/a'] as $url) {
            $this->assertFalse($storage->allowedUrl($url), $url);
        }
    }

    public function test_saved_provider_recording_is_downloaded_privately_once_and_not_overwritten_by_delayed_errors(): void
    {
        $call = $this->incomingCall();
        $this->route($call);
        $this->event($call, 'call.answered');
        $this->event($call, 'call.playback.ended', 'greeting');
        $this->event($call, 'call.recording.saved', 'recording', ['recording_id' => 'private-provider-id', 'duration_seconds' => 15]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['data' => ['download_urls' => ['mp3' => 'https://s3.amazonaws.com/telephony-recorder-prod/message.mp3?signature=temporary']]])]);
        $storage = new class(app(VoiceBrowserGateway::class)) extends VoiceVoicemailStorage
        {
            protected function download(string $url, $temporary): void { fwrite($temporary, 'private-message'); }
        };
        $vm = $call->fresh()->voicemail;
        $storage->store($vm);
        $storage->store($vm);
        Storage::disk('local')->assertExists('voice/voicemails/'.$vm->id.'/private-provider-id.mp3');
        $this->assertSame('saved', $vm->fresh()->state);
        $this->assertNull($call->fresh()->recording_url);
        $this->assertCount(1, Http::recorded());
        $this->event($call, 'call.recording.error', 'recording');
        app(VoiceVoicemailService::class)->fail($vm, 'Delayed queue failure.');
        $this->assertSame('saved', $vm->fresh()->state);
        $this->assertCount(1, app(VoiceVoicemailService::class)->notifications($this->staff, 30));
    }

    public function test_explicit_preparation_imports_shared_greetings_and_preserves_robbie_sms_configuration(): void
    {
        $support = User::factory()->create(['role' => 'admin', 'email' => 'cherisea@reprophotos.test']);
        $this->staff->update(['permission_overrides' => ['allow' => [], 'deny' => ['voice-calls-view', 'voice-calls-operate']]]);
        $robbie = SmsNumber::create(['phone_number' => '+18888041663', 'provider' => 'TELNYX', 'owner_type' => 'SYSTEM', 'is_default' => true, 'sms_ai_enabled' => true]);
        $source = tempnam(sys_get_temp_dir(), 'greeting');
        // A structurally valid layer-3 frame sequence; supplied real audio is decoded in rollout QA.
        file_put_contents($source, str_repeat(pack('N', 0xfffb9000).str_repeat("\0", 413), 50));
        try {
            $this->artisan('voice:configure-business-lines', ['office' => $source, 'closed' => $source,
                '--support-email' => $support->email, '--canary' => '+13392169491'])->assertSuccessful();
            $this->assertDatabaseCount('voice_line_policies', 5);
            $this->assertTrue($robbie->fresh()->sms_ai_enabled);
            $this->assertTrue($robbie->fresh()->is_default);
            $this->assertFalse($robbie->fresh()->voice_ai_enabled);
            $this->assertTrue(app(\App\Services\Voice\VoiceBrowserSessionService::class)->canOperate($this->staff->fresh()));
            $this->assertSame([$support->id], app(VoiceLinePolicyService::class)->forNumber('+18886567627')->recipient_ids);
            $this->assertSame('sms_only', app(VoiceLinePolicyService::class)->forNumber('+18888041663')->mode);
            $this->assertSame(hash('sha256', file_get_contents($source)), basename($this->policy->fresh()->office_greeting, '.mp3'));
        } finally {
            unlink($source);
        }
    }

    public function test_staff_line_cannot_schedule_a_global_automatic_callback(): void
    {
        $call = $this->incomingCall();
        app(VoiceRoutingService::class)->createCallback($call, 'missed_call');
        $this->assertTrue($call->fresh()->needs_follow_up);
        $this->assertDatabaseCount('scheduled_voice_calls', 0);
        Http::assertNothingSent();
    }

    private function incomingCall(): VoiceCall
    {
        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'customer-'.Str::uuid(),
            'from_phone' => '+12025550200', 'to_phone' => '+12027803332', 'assistant_id' => 'must-not-start']);
    }
    private function route(VoiceCall $call): void { app(VoiceRoutingService::class)->beginInboundCall($call, []); }
    private function device(User $user): VoiceBrowserSession
    {
        return VoiceBrowserSession::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'credential-'.Str::uuid(),
            'sip_username' => 'gencred'.Str::random(10), 'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
    }
    private function event(VoiceCall $call, string $type, ?string $stage = null, array $extra = []): void
    {
        app(VoiceVoicemailService::class)->handleWebhook(['event_type' => $type, 'payload' => array_merge([
            'call_control_id' => $call->call_control_id, 'client_state' => $stage ? base64_encode('voicemail:'.$call->fresh()->voicemail->id.':'.$stage) : null,
        ], $extra)]);
    }
    private function assertNoAiOrForward(): void
    {
        Http::assertNotSent(fn ($r) => preg_match('~/actions/(ai_assistant_start|transfer|gather_using_speak)$~', $r->url()) === 1);
    }
}
