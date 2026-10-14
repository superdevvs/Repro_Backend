<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserCall;
use App\Models\VoiceBrowserLeg;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceSipDevice;
use App\Services\Voice\VoiceBrowserCallService;
use App\Services\Voice\VoiceIncomingOfferService;
use App\Services\Voice\VoiceSipDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceSipDeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private array $connections = [];

    private bool $registered = true;

    private int $dials = 0;

    private bool $ambiguous = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(10, 0)->setTimezone('UTC'));
        config(['services.telnyx.api_key' => 'test', 'services.voice.canary_mode' => false, 'services.telnyx.from_number' => '+12025550100',
            'services.telnyx.voice.browser_enabled' => true, 'services.telnyx.voice.credential_connection_id' => 'browser',
            'services.telnyx.voice.connection_id' => 'server', 'services.telnyx.voice.webhook_url' => 'https://example.test/voice',
            'services.telnyx.voice.outbound_mode' => 'all', 'voice_calls.realtime_connection' => 'database']);
        Queue::fake();
        $this->staff = User::factory()->create(['role' => 'admin']);
        $this->policy([$this->staff->id]);
        $this->actingAs($this->staff, 'sanctum');
        Http::fake(function ($r) {
            $p = parse_url($r->url(), PHP_URL_PATH);
            if ($p === '/v2/credential_connections' && $r->method() === 'GET') {
                return Http::response(['data' => array_values($this->connections)]);
            }
            if ($p === '/v2/credential_connections' && $r->method() === 'POST') {
                $id = 'sip-'.(count($this->connections) + 1);
                $this->connections[$id] = array_merge($r->data(), ['id' => $id]);
                if ($this->ambiguous) {
                    return Http::response([], 503);
                }

                return Http::response(['data' => $this->connections[$id]]);
            }
            if (preg_match('#/credential_connections/([^/]+)$#', $p, $m)) {
                if ($r->method() === 'PATCH') {
                    $this->connections[$m[1]] = array_merge($this->connections[$m[1]] ?? [], $r->data());
                }

                return Http::response(['data' => $this->connections[$m[1]] ?? []]);
            }
            if (preg_match('#/credential_connections/([^/]+)/actions/check_registration_status$#', $p, $m)) {
                return Http::response(['data' => ['status' => $this->registered ? 'Registered' : 'Unregistered', 'sip_username' => $this->connections[$m[1]]['user_name'] ?? '']]);
            }
            if ($p === '/v2/calls') {
                return Http::response(['data' => ['call_control_id' => 'offer-leg-'.++$this->dials, 'call_session_id' => 'session-'.$this->dials]]);
            }
            if ($p === '/v2/conferences') {
                return Http::response(['data' => ['id' => 'conference-one']]);
            }

            return Http::response(['data' => ['result' => 'ok']]);
        });
    }

    private function policy(array $ids): VoiceLinePolicy
    {
        return VoiceLinePolicy::updateOrCreate(['phone_number' => '+12025550100'], ['mode' => 'staff_voicemail', 'recipient_ids' => $ids,
            'recipient_roles' => [], 'timezone' => 'UTC', 'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['start' => '09:00', 'end' => '18:00']])]);
    }

    private function createDevice(?User $user = null): VoiceSipDevice
    {
        $user ??= $this->staff;
        $result = app(VoiceSipDeviceService::class)->create($user, ['label' => 'Groundwire '.$user->id, 'caller_id' => '+12025550100', 'creation_key' => (string) Str::uuid()]);

        return VoiceSipDevice::findOrFail($result['id']);
    }

    private function incoming(): VoiceCall
    {
        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100', 'call_control_id' => 'caller-'.Str::uuid()]);
    }

    private function event(string $type, string $control, array $payload = []): ?array
    {
        return app(VoiceBrowserCallService::class)->handleWebhook(['data' => ['id' => (string) Str::uuid(), 'event_type' => $type,
            'payload' => array_merge(['call_control_id' => $control, 'connection_id' => 'server'], $payload)]]);
    }

    public function test_enrolment_retries_once_and_never_returns_a_secret_in_list_or_model_json(): void
    {
        $key = (string) Str::uuid();
        $data = ['label' => 'My phone', 'caller_id' => '+12025550100', 'creation_key' => $key];
        $r = $this->postJson('/api/voice/sip/devices', $data)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $id = $r->json('id');
        $this->postJson('/api/voice/sip/devices', $data)->assertOk()->assertJsonPath('id', $id);
        $this->assertCount(1, $this->connections);
        $d = VoiceSipDevice::findOrFail($id);
        $cipher = DB::table('voice_sip_devices')->where('id', $id)->value('sip_password');
        $this->assertNotSame($d->sip_password, $cipher);
        $this->assertStringNotContainsString($d->sip_password, $d->toJson());
        $list = $this->getJson('/api/voice/sip/devices')->assertOk();
        $this->assertStringNotContainsString($d->sip_password, $list->getContent());
        $this->assertStringNotContainsString($d->sip_username, $list->getContent());
        $this->postJson('/api/voice/sip/devices/'.$id.'/credentials')->assertOk()->assertJsonPath('domain', 'sip.telnyx.com')->assertJsonPath('password', $d->sip_password);
        $c = $this->connections[$d->connection_id];
        $this->assertTrue($c['outbound']['call_parking_enabled']);
        $this->assertNull($c['outbound']['outbound_voice_profile_id'] ?? null);
        $this->assertSame('internal', $c['sip_uri_calling_preference']);
        $this->assertSame('SRTP', $c['encrypted_media']);
    }

    public function test_foreign_users_cannot_read_change_or_revoke_a_device_or_select_an_unassigned_caller_id(): void
    {
        $d = $this->createDevice();
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other, 'sanctum');
        $this->postJson('/api/voice/sip/devices/'.$d->id.'/credentials')->assertForbidden();
        $this->patchJson('/api/voice/sip/devices/'.$d->id, ['enabled' => false])->assertForbidden();
        $this->deleteJson('/api/voice/sip/devices/'.$d->id)->assertForbidden();
        $this->postJson('/api/voice/sip/devices', ['label' => 'Bad', 'caller_id' => '+12025550100', 'creation_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertTrue($d->fresh()->enabled);
    }

    public function test_ambiguous_provision_is_reconciled_without_creating_another_connection(): void
    {
        $this->ambiguous = true;
        $data = ['label' => 'Retry phone', 'caller_id' => '+12025550100', 'creation_key' => (string) Str::uuid()];
        $this->postJson('/api/voice/sip/devices', $data)->assertStatus(503);
        $this->postJson('/api/voice/sip/devices', $data)->assertOk();
        $this->assertCount(1, $this->connections);
        $this->assertDatabaseCount('voice_sip_devices', 1);
    }

    public function test_unregistered_native_device_does_not_create_a_ring_offer_and_closed_hours_skip_it(): void
    {
        $d = $this->createDevice();
        $this->registered = false;
        $this->assertFalse(app(VoiceIncomingOfferService::class)->create($this->incoming()));
        $this->registered = true;
        Cache::flush();
        $this->travelTo(now()->setTime(22, 0));
        $this->assertFalse(app(VoiceIncomingOfferService::class)->create($this->incoming()));
        $this->assertDatabaseCount('voice_incoming_offers', 0);
        $this->assertSame(0, $this->dials);
    }

    public function test_native_answer_claims_once_and_busy_user_is_skipped_for_the_next_call(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $this->policy([$this->staff->id, $other->id]);
        $this->createDevice();
        $this->createDevice($other);
        $call = $this->incoming();
        $service = app(VoiceIncomingOfferService::class);
        $this->assertTrue($service->create($call));
        $offer = VoiceIncomingOffer::where('voice_call_id', $call->id)->firstOrFail();
        $phones = $offer->phoneOffers()->orderBy('user_id')->get();
        $this->assertCount(2, $phones);
        foreach ($phones as $phone) {
            $service->dialPhone($phone->id);
        }
        $winner = $phones->first()->fresh();
        $this->event('call.answered', $winner->call_control_id);
        $this->event('call.answered', $winner->call_control_id);
        $this->assertSame('claimed', $offer->fresh()->status);
        $this->assertSame($this->staff->id, $offer->fresh()->claimed_by_id);
        $this->assertDatabaseCount('voice_browser_calls', 1);
        $agent = VoiceBrowserLeg::where('role', 'agent')->firstOrFail();
        $this->assertSame('sip', $agent->mode);
        $this->assertNotNull($agent->sip_device_id);
        $service->closeLosingPhones($offer->fresh());
        $this->assertSame('cancelled', $phones->last()->fresh()->state);
        $second = $this->incoming();
        $this->assertTrue($service->create($second));
        $this->assertSame([$other->id], VoiceIncomingOffer::where('voice_call_id', $second->id)->firstOrFail()->eligible_user_ids);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'gather_using_speak') || str_contains($r->url(), 'ai_assistant') || str_contains($r->url(), 'transfer'));
    }

    public function test_browser_winner_survives_a_late_native_answer(): void
    {
        $this->createDevice();
        $browser = VoiceBrowserSession::create(['user_id' => $this->staff->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'cred', 'sip_username' => 'gencredOne', 'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
        $call = $this->incoming();
        $service = app(VoiceIncomingOfferService::class);
        $service->create($call);
        $offer = VoiceIncomingOffer::where('voice_call_id', $call->id)->firstOrFail();
        $phone = $offer->phoneOffers()->firstOrFail();
        $service->dialPhone($phone->id);
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'browser', 'session_id' => $browser->id, 'idempotency_key' => 'browser-win'])->assertOk();
        $this->event('call.answered', $phone->fresh()->call_control_id);
        $this->assertSame($browser->id, $offer->fresh()->claimed_session_id);
        $this->assertDatabaseCount('voice_browser_calls', 1);
        $agent = VoiceBrowserLeg::where('role', 'agent')->firstOrFail();
        $this->assertNull($agent->ended_at);
        $loser = $phone->fresh()->call_control_id;
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/calls/'.$loser.'/actions/hangup'));
    }

    public function test_delayed_native_peer_events_never_create_extra_customer_calls(): void
    {
        $d = $this->createDevice();
        $call = $this->incoming();
        $service = app(VoiceIncomingOfferService::class);
        $service->create($call);
        $offer = VoiceIncomingOffer::where('voice_call_id', $call->id)->firstOrFail();
        $phone = $offer->phoneOffers()->firstOrFail();
        $service->dialPhone($phone->id);
        $p = ['connection_id' => $d->connection_id, 'direction' => 'incoming', 'call_session_id' => $phone->fresh()->call_session_id];
        $this->assertSame('processed_sip_peer', $this->event('call.initiated', 'peer-id', $p)['status']);
        $this->assertSame('processed_sip_peer', $this->event('call.answered', 'peer-id', $p)['status']);
        $this->assertSame('waiting', $offer->fresh()->status);
        $this->assertDatabaseCount('voice_calls', 1);
        $this->event('call.answered', $phone->fresh()->call_control_id);
        $this->assertSame('claimed', $offer->fresh()->status);
        $this->assertDatabaseCount('voice_browser_calls', 1);
    }

    public function test_expired_or_revoked_native_offer_is_hung_up_without_claiming(): void
    {
        $d = $this->createDevice();
        $call = $this->incoming();
        $s = app(VoiceIncomingOfferService::class);
        $s->create($call);
        $offer = VoiceIncomingOffer::where('voice_call_id', $call->id)->firstOrFail();
        $phone = $offer->phoneOffers()->firstOrFail();
        $s->dialPhone($phone->id);
        $this->travel(46)->seconds();
        $this->event('call.answered', $phone->fresh()->call_control_id);
        $this->assertDatabaseCount('voice_browser_calls', 0);
        $this->assertSame('cancelled', $phone->fresh()->state);
        $this->deleteJson('/api/voice/sip/devices/'.$d->id)->assertOk();
        $this->postJson('/api/voice/sip/devices/'.$d->id.'/credentials')->assertForbidden();
        $this->assertFalse($this->connections[$d->connection_id]['active']);
    }

    public function test_native_outbound_ignores_spoofed_caller_id_and_waits_for_the_shared_conference(): void
    {
        $d = $this->createDevice();
        $p = ['connection_id' => $d->connection_id, 'direction' => 'outgoing', 'to' => '+12025550222', 'from' => '+12025559999'];
        $r = $this->event('call.initiated', 'native-outbound', $p);
        $this->assertSame('processed_sip_outbound', $r['status']);
        $this->event('call.initiated', 'native-outbound', $p);
        $call = VoiceCall::findOrFail($r['voice_call_id']);
        $this->assertSame('+12025550100', $call->from_phone);
        $this->assertSame('groundwire_staff', $call->metadata['source']);
        $this->assertDatabaseCount('voice_calls', 1);
        $this->assertSame(0, $this->dials);
        $this->event('call.answered', 'native-outbound', ['connection_id' => $d->connection_id]);
        $this->event('conference.participant.joined', 'native-outbound', ['connection_id' => $d->connection_id, 'conference_id' => 'conference-one']);
        $this->assertSame(1, $this->dials);
        $this->assertSame('offer-leg-1', $call->fresh()->call_control_id);
        $this->event('call.hangup', 'native-outbound', ['connection_id' => $d->connection_id]);
        $this->event('call.answered', 'native-outbound', ['connection_id' => $d->connection_id]);
        $this->assertContains(VoiceBrowserCall::first()->state, ['ended', 'failed']);
        $this->assertSame(1, $this->dials);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'record_start') || str_contains($r->url(), 'ai_assistant'));
    }

    public function test_disabled_outbound_or_lost_line_assignment_is_rejected_before_dialing(): void
    {
        $d = $this->createDevice();
        app(\App\Services\TelnyxAi\VoiceSettingsService::class)->update(['outbound_mode' => 'none']);
        $p = ['connection_id' => $d->connection_id, 'direction' => 'outgoing', 'to' => '+12025550222'];
        $this->assertSame('rejected_sip_device', $this->event('call.initiated', 'blocked-out', $p)['status']);
        app(\App\Services\TelnyxAi\VoiceSettingsService::class)->update(['outbound_mode' => 'all']);
        $this->policy([]);
        $this->assertSame('rejected_sip_device', $this->event('call.initiated', 'lost-assignment', $p)['status']);
        $this->assertDatabaseCount('voice_calls', 0);
        $this->assertSame(0, $this->dials);
    }
}
