<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoicePhoneOffer;
use App\Models\VoiceStaffPhone;
use App\Services\Voice\VoiceBrowserCallService;
use App\Services\Voice\VoiceIncomingOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceIncomingOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private VoiceBrowserSession $session;

    private int $dials = 0;

    private mixed $duringPhoneDial = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test', 'services.telnyx.from_number' => '+12025550100', 'services.voice.canary_mode' => false,
            'services.telnyx.voice.browser_enabled' => true, 'services.telnyx.voice.credential_connection_id' => 'browser',
            'services.telnyx.voice.connection_id' => 'server', 'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
            'services.telnyx.voice.outbound_mode' => 'all', 'services.telnyx.voice.support_handoff_number' => '+12025550999']);
        Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->session = $this->device($this->admin);
        $this->actingAs($this->admin, 'sanctum');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/calls')) {
                $id = 'leg-'.++$this->dials;
                if ($this->duringPhoneDial && $request['to'] === '+12025550333') {
                    ($this->duringPhoneDial)();
                }

                return Http::response(['data' => ['call_control_id' => $id]]);
            }
            if (str_ends_with($request->url(), '/v2/conferences')) {
                return Http::response(['data' => ['id' => 'conference']]);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
    }

    public function test_shared_offer_has_one_winner_across_admins_and_devices_and_retries_do_not_redial(): void
    {
        $second = User::factory()->create(['role' => 'admin']);
        $other = $this->device($second);
        $offer = $this->offer();
        $this->assertSame(0, $this->dials);
        $this->getJson('/api/voice/incoming-offers')->assertOk()->assertJsonPath('data.0.can_claim', true);
        $this->actingAs($second, 'sanctum')->getJson('/api/voice/incoming-offers')->assertOk()->assertJsonPath('data.0.id', $offer->id);
        $this->actingAs($this->admin, 'sanctum');
        $this->claim($offer)->assertOk()->assertJsonPath('incoming_offer.claimed_by.id', $this->admin->id);
        $this->claim($offer)->assertOk();
        $this->assertSame(1, $this->dials);
        $this->actingAs($second, 'sanctum')->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'browser', 'session_id' => $other->id, 'idempotency_key' => 'other-answer',
        ])->assertConflict()->assertJsonPath('incoming_offer.claimed_by.id', $this->admin->id);
        $this->assertDatabaseCount('voice_browser_calls', 1);
        $this->assertSame('incoming', $offer->voiceCall->call_control_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/calls/incoming/actions/answer'));
    }

    public function test_signed_webhook_and_http_claim_complete_the_customer_lifecycle_and_keep_final_words(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $otherSession = $this->device($other);
        config(['services.telnyx.voice.recording_enabled' => true]);
        $this->webhook('call.initiated', 'http-incoming', ['direction' => 'incoming', 'from' => '+12025550200', 'to' => '+12025550100'])->assertOk();
        $offer = VoiceIncomingOffer::firstOrFail();
        $this->claim($offer)->assertOk();
        $this->actingAs($other, 'sanctum')->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'browser', 'session_id' => $otherSession->id, 'idempotency_key' => 'loser',
        ])->assertConflict();
        $this->assertSame(0, DB::table('voice_incoming_offers')->where('id', $offer->id)->where('status', 'waiting')->update(['claimed_by_id' => $other->id]));
        $this->actingAs($this->admin, 'sanctum');
        $this->webhook('call.answered', 'leg-1')->assertOk();
        $this->webhook('conference.participant.joined', 'leg-1', ['conference_id' => 'conference'])->assertOk();
        $this->webhook('call.answered', 'http-incoming')->assertOk();
        $this->webhook('conference.participant.joined', 'http-incoming', ['conference_id' => 'conference'])->assertOk();
        $call = $offer->voiceCall->fresh();
        $this->assertSame('active', $call->status);
        $this->patchJson('/api/voice/calls/'.$call->id.'/browser-consent', ['consented' => true])->assertOk();
        $this->postJson('/api/voice/calls/'.$call->id.'/browser-actions', ['action' => 'recording_start', 'idempotency_key' => 'record'])->assertOk();
        $this->webhook('call.hangup', 'http-incoming')->assertOk();
        $this->webhook('call.transcription', 'http-incoming', ['transcription_data' => ['is_final' => true, 'transcript' => 'Final words saved.']], 'last-words')->assertOk();
        $this->webhook('call.transcription', 'http-incoming', ['transcription_data' => ['is_final' => true, 'transcript' => 'Final words saved.']], 'last-words')->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame('completed', $call->fresh()->status);
        $call->fresh()->update(['transcript' => null]);
        $this->postJson('/api/voice/calls/'.$call->id.'/transcript/reconcile')->assertOk()->assertJsonPath('segment_count', 1)
            ->assertJsonPath('state', 'finalizing')->assertJsonPath('transcript', 'customer: Final words saved.');
        $this->assertDatabaseCount('voice_calls', 1);
        $this->assertDatabaseCount('voice_browser_calls', 1);
        $this->assertSame(1, $this->dials);
    }

    public function test_claim_rechecks_permission_presence_expiry_availability_and_busy_account(): void
    {
        $offer = $this->offer();
        $this->session->update(['registered' => false]);
        $this->claim($offer)->assertConflict();
        $this->session->update(['registered' => true]);
        $this->patchJson('/api/voice/phone/settings', ['available' => false])->assertOk();
        $this->claim($offer)->assertConflict();
        $this->patchJson('/api/voice/phone/settings', ['available' => true])->assertOk();
        $this->admin->update(['permission_overrides' => ['deny' => ['voice-calls-operate'], 'allow' => []]]);
        $this->claim($offer)->assertForbidden();
        $this->admin->update(['permission_overrides' => []]);
        $offer->update(['expires_at' => now()->subSecond()]);
        $this->claim($offer)->assertConflict();
        $this->assertSame(0, $this->dials);
    }

    public function test_unclaimed_caller_hangup_cancels_every_offer_without_dialing(): void
    {
        $offer = $this->offer();
        $this->event('call.hangup', 'incoming');
        $this->assertSame('cancelled', $offer->fresh()->status);
        $this->claim($offer)->assertConflict();
        $this->assertSame(0, $this->dials);
    }

    public function test_phone_carrier_answer_and_voicemail_never_claim_without_press_one(): void
    {
        $this->verifiedPhone();
        $offer = $this->offer();
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])
            ->assertOk()->assertJsonPath('phone_pending', true)->assertJsonPath('incoming_offer.status', 'waiting');
        $this->webhook('call.answered', 'leg-1')->assertOk();
        $this->assertSame('waiting', $offer->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/gather_using_speak') && $r['valid_digits'] === '1');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/conferences'));
        $this->webhook('call.gather.ended', 'leg-1', ['digits' => ''])->assertOk();
        $this->assertSame('waiting', $offer->fresh()->status);
        $this->assertSame('cancelled', VoicePhoneOffer::first()->state);
    }

    public function test_press_one_claims_phone_and_customer_only_joins_after_staff_conference_join(): void
    {
        $this->verifiedPhone();
        $offer = $this->offer();
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertOk();
        $this->webhook('call.answered', 'leg-1')->assertOk();
        $this->webhook('call.gather.ended', 'leg-1', ['digits' => '1'])->assertOk();
        $this->assertSame('claimed', $offer->fresh()->status);
        $this->assertNull(VoiceBrowserCall::first()->session_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conferences') && $r['call_control_id'] === 'leg-1');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/calls/incoming/actions/answer'));
        $this->webhook('conference.participant.joined', 'leg-1', ['conference_id' => 'conference'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/calls/incoming/actions/answer'));
        $this->assertSame(1, $this->dials);
    }

    public function test_browser_winner_cancels_ringing_phone_and_late_acceptance_cannot_steal_call(): void
    {
        $this->verifiedPhone();
        $offer = $this->offer();
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertOk();
        $this->event('call.answered', 'leg-1');
        $this->claim($offer)->assertOk();
        // Winner media starts before any slow losing carrier hangup.
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
        $this->assertSame(2, $this->dials);
        app(VoiceIncomingOfferService::class)->reconcile($offer->id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
        $this->event('call.gather.ended', 'leg-1', ['digits' => '1']);
        $this->assertDatabaseCount('voice_browser_calls', 1);
        $this->assertSame($this->session->id, $offer->fresh()->claimed_session_id);
    }

    public function test_expired_offer_routes_once_and_busy_user_cannot_start_on_second_device(): void
    {
        $offer = $this->offer();
        $this->travel(46)->seconds();
        app(VoiceIncomingOfferService::class)->reconcile();
        $count = Http::recorded()->count();
        app(VoiceIncomingOfferService::class)->reconcile();
        $this->assertSame($count, Http::recorded()->count());
        $this->assertSame('expired', $offer->fresh()->status);
        $this->session->update(['heartbeat_at' => now()]);
        $payload = ['to' => '+12025550200', 'session_id' => $this->session->id, 'idempotency_key' => 'out'];
        $this->postJson('/api/voice/calls/human', $payload)->assertOk();
        $payload['session_id'] = $this->device($this->admin)->id;
        $payload['idempotency_key'] = 'out-2';
        $this->postJson('/api/voice/calls/human', $payload)->assertConflict();
    }

    public function test_delayed_phone_jobs_ring_once_and_expiry_fallback_does_not_wait_for_cleanup(): void
    {
        $this->verifiedPhone();
        $offer = $this->offer();
        Queue::assertPushed(\App\Jobs\ReconcileVoiceIncomingOffer::class, 2);
        $this->travel(9)->seconds();
        $service = app(VoiceIncomingOfferService::class);
        (new \App\Jobs\ReconcileVoiceIncomingOffer($offer->id))->handle($service);
        Queue::assertPushed(\App\Jobs\DialVoiceStaffPhone::class, fn ($job) => $job->queue === 'voice-realtime');
        $phone = VoicePhoneOffer::firstOrFail();
        (new \App\Jobs\DialVoiceStaffPhone($phone->id))->handle($service);
        (new \App\Jobs\DialVoiceStaffPhone($phone->id))->handle($service);
        $this->assertSame(1, $this->dials);
        $this->travel(37)->seconds();
        (new \App\Jobs\ReconcileVoiceIncomingOffer($offer->id))->handle($service);
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('human_handoff', $offer->voiceCall->fresh()->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
        (new \App\Jobs\CloseVoiceOfferDevices($offer->id))->handle($service);
        $this->assertSame('cancelled', $phone->fresh()->state);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
    }

    public function test_browser_claim_can_win_during_slow_phone_dial_and_late_phone_is_cancelled(): void
    {
        $this->verifiedPhone();
        $other = User::factory()->create(['role' => 'admin']);
        $session = $this->device($other);
        $offer = $this->offer();
        $this->duringPhoneDial = function () use ($offer, $other, $session): void {
            app(VoiceIncomingOfferService::class)->claim($offer->fresh(), $other, ['device' => 'browser', 'session_id' => $session->id, 'idempotency_key' => 'parallel-winner']);
        };
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertConflict();
        $this->assertSame($other->id, $offer->fresh()->claimed_by_id);
        $this->assertSame('cancelled', VoicePhoneOffer::firstOrFail()->state);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
        $this->assertSame(2, $this->dials);
        $this->assertDatabaseCount('voice_browser_calls', 1);
    }

    public function test_cancel_during_phone_dial_hangs_up_late_leg_and_keeps_offer_available_for_other_staff(): void
    {
        $this->verifiedPhone();
        $other = User::factory()->create(['role' => 'admin']);
        $otherSession = $this->device($other);
        $offer = $this->offer();
        $this->duringPhoneDial = function () use ($offer): void {
            app(VoiceIncomingOfferService::class)->cancel($offer->fresh(), $this->admin, 'phone');
            $this->assertSame('cancelled', VoicePhoneOffer::firstOrFail()->state);
            $this->assertNull(VoicePhoneOffer::firstOrFail()->call_control_id);
        };

        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertOk();
        $this->assertSame('waiting', $offer->fresh()->status);
        $phone = VoicePhoneOffer::firstOrFail();
        $this->assertSame('cancelled', $phone->state);
        $this->assertSame('leg-1', $phone->call_control_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/leg-1/actions/hangup'));
        $this->assertDatabaseCount('voice_browser_calls', 0);
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertConflict();
        $this->assertSame(1, $this->dials);

        $this->actingAs($other, 'sanctum')->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'browser', 'session_id' => $otherSession->id, 'idempotency_key' => 'other-answer',
        ])->assertOk();
        $this->assertSame($other->id, $offer->fresh()->claimed_by_id);
    }

    public function test_cancel_before_start_is_durable_and_cannot_dial_customer(): void
    {
        $this->postJson('/api/voice/calls/human/cancel', ['idempotency_key' => 'cancel-before'])->assertOk();
        $this->postJson('/api/voice/calls/human', ['to' => '+12025550200', 'session_id' => $this->session->id, 'idempotency_key' => 'cancel-before'])->assertConflict();
        $this->assertSame(0, $this->dials);
        $this->assertDatabaseCount('voice_calls', 0);
    }

    public function test_unreachable_staff_do_not_delay_caller_and_verified_phone_is_a_reachable_channel(): void
    {
        $this->session->update(['heartbeat_at' => now()->subMinutes(2)]);
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'unreachable', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        $this->assertFalse(app(VoiceIncomingOfferService::class)->create($call));
        $this->assertDatabaseCount('voice_incoming_offers', 0);
        $this->verifiedPhone();
        $this->assertTrue(app(VoiceIncomingOfferService::class)->create($call));
        $this->assertSame([$this->admin->id], VoiceIncomingOffer::first()->eligible_user_ids);
    }

    public function test_cancellation_before_claim_is_durable_but_does_not_remove_offer_for_other_staff(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $otherSession = $this->device($other);
        $offer = $this->offer();
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/cancel', ['idempotency_key' => 'answer'])->assertOk();
        $this->claim($offer)->assertConflict();
        $this->assertSame('waiting', $offer->fresh()->status);
        $this->actingAs($other, 'sanctum')->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'browser', 'session_id' => $otherSession->id, 'idempotency_key' => 'other-answer',
        ])->assertOk();
        $this->assertSame(1, $this->dials);
    }

    public function test_cancel_claim_before_join_resumes_fallback_only_once(): void
    {
        $offer = $this->offer();
        $this->claim($offer)->assertOk();
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/cancel', ['idempotency_key' => 'answer'])->assertOk();
        $this->assertSame('cancelled', $offer->fresh()->status);
        $before = Http::recorded()->count();
        $this->travel(46)->seconds();
        app(VoiceIncomingOfferService::class)->reconcile($offer->id);
        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_cancel_after_customer_joins_requires_explicit_end_and_takeover_cannot_bypass_shared_claim(): void
    {
        $offer = $this->offer();
        $this->postJson('/api/voice/calls/'.$offer->voice_call_id.'/takeover', ['session_id' => $this->session->id, 'idempotency_key' => 'bypass'])->assertConflict();
        $this->claim($offer)->assertOk();
        $this->event('call.answered', 'leg-1');
        $this->event('conference.participant.joined', 'leg-1', ['conference_id' => 'conference']);
        $this->event('call.answered', 'incoming');
        $this->event('conference.participant.joined', 'incoming', ['conference_id' => 'conference']);
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/cancel', ['idempotency_key' => 'answer'])->assertConflict();
        $this->assertSame('active', $offer->voiceCall->fresh()->status);
    }

    public function test_incoming_candidate_queries_do_not_expand_with_unrelated_clients(): void
    {
        $service = app(VoiceIncomingOfferService::class);
        $first = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'size-one', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        DB::enableQueryLog();
        $this->assertTrue($service->create($first));
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        User::factory()->count(25)->create(['role' => 'client']);
        $second = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'size-many', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($service->create($second));
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($before, $after);
        $this->assertSame([$this->admin->id], VoiceIncomingOffer::where('voice_call_id', $second->id)->firstOrFail()->eligible_user_ids);
    }

    public function test_directory_paginates_and_filters_roles_without_sms_permission_or_private_fields(): void
    {
        $this->admin->update(['permission_overrides' => ['deny' => ['messaging-sms-view'], 'allow' => []]]);
        User::factory()->create(['name' => 'Directory Photographer', 'role' => 'photographer', 'phonenumber' => '+12025550777']);
        User::factory()->create(['name' => 'Directory Missing', 'role' => 'photographer', 'phonenumber' => null, 'phone' => null]);
        Contact::create(['name' => 'Outside Contact', 'phone' => '+12025550888']);
        $page = $this->getJson('/api/voice/directory?role=photographer&per_page=1')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('email', $page->json('data.0'));
        $this->getJson('/api/voice/directory?role=photographer&q=2025550777')->assertOk()->assertJsonPath('data.0.phone', '+12025550777');
        $this->getJson('/api/voice/directory?role=photographer&q=Missing')->assertOk()->assertJsonPath('data.0.callable', false);
        $this->actingAs(User::factory()->create(['role' => 'client']), 'sanctum')->getJson('/api/voice/directory')->assertForbidden();
    }

    public function test_phone_verification_is_account_scoped_bounded_and_does_not_accept_business_line(): void
    {
        $this->patchJson('/api/voice/phone/settings', ['phone_enabled' => true])->assertUnprocessable();
        $this->postJson('/api/voice/phone/verification', ['phone' => '+12025550100'])->assertUnprocessable();
        VoiceStaffPhone::updateOrCreate(['user_id' => $this->admin->id], ['pending_phone' => '+12025550333', 'verification_hash' => Hash::make('123456'), 'verification_expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/voice/phone/verify', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/voice/phone/verify', ['code' => '123456'])->assertOk()->assertJsonPath('phone_number', '+12025550333');
        $this->postJson('/api/voice/phone/verify', ['code' => '123456'])->assertUnprocessable();
        $this->deleteJson('/api/voice/phone/settings')->assertOk()->assertJsonPath('phone_enabled', false)->assertJsonPath('phone_number', null);
    }

    public function test_phone_verification_sends_a_bounded_code_then_enables_only_the_verified_destination(): void
    {
        $code = null;
        $this->mock(\App\Services\Messaging\MessagingService::class, function ($mock) use (&$code): void {
            $mock->shouldReceive('sendSms')->once()->withArgs(function (array $payload) use (&$code): bool {
                preg_match('/code: (\d{6})/', $payload['body_text'], $match);
                $code = $match[1] ?? null;

                return $payload['to'] === '+12025550333' && $payload['hidden_from_inbox'] === true && $payload['send_source'] === 'VOICE_PHONE_VERIFICATION';
            })->andReturn(new \App\Models\Message(['status' => 'SENT']));
        });
        $this->postJson('/api/voice/phone/verification', ['phone' => '+12025550333'])->assertOk()->assertJsonPath('phone_enabled', false);
        $this->postJson('/api/voice/phone/verification', ['phone' => '+12025550333'])->assertStatus(429);
        $this->postJson('/api/voice/phone/verify', ['code' => $code])->assertOk()->assertJsonPath('phone_enabled', true)->assertJsonPath('phone_number', '+12025550333');
        Http::assertNothingSent();
    }

    private function device(User $user): VoiceBrowserSession
    {
        return VoiceBrowserSession::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'credential-'.Str::uuid(),
            'sip_username' => 'gencred'.Str::random(10), 'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
    }

    private function offer(): VoiceIncomingOffer
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'incoming', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100']);
        $this->assertTrue(app(VoiceBrowserCallService::class)->offerInbound($call));

        return VoiceIncomingOffer::where('voice_call_id', $call->id)->firstOrFail();
    }

    private function claim(VoiceIncomingOffer $offer)
    {
        return $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'browser', 'session_id' => $this->session->id, 'idempotency_key' => 'answer']);
    }

    private function verifiedPhone(): void
    {
        VoiceStaffPhone::create(['user_id' => $this->admin->id, 'phone' => '+12025550333', 'verified_at' => now(), 'phone_enabled' => true]);
    }

    private function event(string $type, string $control, array $payload = []): ?array
    {
        return app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => $type,
            'payload' => array_merge(['call_control_id' => $control, 'connection_id' => 'server'], $payload)]]);
    }

    private function webhook(string $type, string $control, array $payload = [], ?string $id = null)
    {
        $keys = sodium_crypto_sign_keypair();
        config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $raw = json_encode(['data' => ['id' => $id ?? (string) Str::uuid(), 'event_type' => $type,
            'payload' => array_merge(['call_control_id' => $control, 'connection_id' => 'server'], $payload)]]);
        $timestamp = (string) time();

        return $this->call('POST', '/api/webhooks/telnyx/voice', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_TELNYX_TIMESTAMP' => $timestamp, 'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$raw, sodium_crypto_sign_secretkey($keys)))], $raw);
    }
}
