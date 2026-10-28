<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserSession;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoiceLinePolicy;
use App\Models\VoicePushDelivery;
use App\Models\VoicePushSubscription;
use App\Models\VoiceStaffPhone;
use App\Services\TelnyxAi\VoiceRoutingService;
use App\Services\Voice\VoiceIncomingOfferService;
use App\Services\Voice\VoiceLinePolicyService;
use App\Services\Voice\VoicePushService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoiceStaffPushRoutingTest extends TestCase
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
        // CI's runner is not a member of the production service group. Exercise
        // shared modes on its own fake disk; live cutover verifies www-data ownership.
        config(['filesystems.disks.voice_private.shared_group' => null]);
        Storage::fake('voice_private', config('filesystems.disks.voice_private'));
        Http::fake(['*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'staff-leg']])]);
        $this->staff = User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => []]]);
        Storage::disk('voice_private')->put('voice/greetings/office.mp3', 'office-audio');
        Storage::disk('voice_private')->put('voice/greetings/closed.mp3', 'closed-audio');
        $this->policy = VoiceLinePolicy::create(['phone_number' => '+12027803332', 'mode' => 'staff_voicemail',
            'recipient_role' => 'editing_manager', 'recipient_ids' => [], 'timezone' => 'Asia/Kolkata',
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['start' => '09:00', 'end' => '18:00']]),
            'office_greeting' => 'voice/greetings/office.mp3', 'closed_greeting' => 'voice/greetings/closed.mp3']);
    }

    public function test_assigned_staff_receive_push_without_a_live_calling_connection_and_can_connect_to_answer(): void
    {
        $subscription = $this->pushDevice($this->staff);
        $unassigned = User::factory()->create(['role' => 'admin']);
        $this->pushDevice($unassigned);
        $this->assertFalse(app(VoiceLinePolicyService::class)->registered($this->staff));
        $call = $this->incomingCall();
        $this->route($call);
        $offer = VoiceIncomingOffer::sole();
        $this->assertSame([$this->staff->id], $offer->eligible_user_ids);
        $delivery = VoicePushDelivery::where('event', 'incoming')->sole();
        $this->assertSame($subscription->id, $delivery->subscription_id);
        $this->assertSame('queued', $delivery->status);
        $this->assertNull($call->fresh()->voicemail);

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/voice/incoming-offers')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', ['device' => 'phone', 'idempotency_key' => 'phone'])->assertStatus(422);
        $device = $this->device($this->staff);
        $this->postJson('/api/voice/incoming-offers/'.$offer->id.'/claim', [
            'device' => 'browser', 'session_id' => $device->id, 'idempotency_key' => 'browser',
        ])->assertOk();
        $this->assertSame($this->staff->id, $offer->fresh()->claimed_by_id);
    }

    #[DataProvider('pushExclusions')]
    public function test_staff_push_respects_assignment_permissions_availability_and_opt_out(string $reason): void
    {
        $subscription = $this->pushDevice($this->staff);
        match ($reason) {
            'revoked' => $subscription->update(['revoked_at' => now()]),
            'opted-out' => DB::table('voice_push_preferences')->insert(['user_id' => $this->staff->id, 'incoming_calls' => false]),
            'unavailable' => VoiceStaffPhone::create(['user_id' => $this->staff->id, 'available' => false]),
            'permission' => $this->staff->update(['permission_overrides' => ['allow' => [], 'deny' => ['voice-calls-operate']]]),
            'unassigned' => $this->policy->update(['recipient_role' => null, 'recipient_roles' => [], 'recipient_ids' => []]),
            'closed' => $this->policy->update(['weekly_hours' => []]),
            'sms-only' => $this->policy->update(['mode' => 'sms_only']),
            'unconfigured' => config(['voice_push.enabled' => false]),
        };
        $this->route($this->incomingCall());
        $this->assertDatabaseCount('voice_incoming_offers', 0);
        $this->assertDatabaseCount('voice_push_deliveries', 0);
        $this->assertNoAiOrForward();
    }

    public static function pushExclusions(): array
    {
        return array_map(fn ($reason) => [$reason], ['revoked', 'opted-out', 'unavailable', 'permission', 'unassigned', 'closed', 'sms-only', 'unconfigured']);
    }

    public function test_unanswered_push_only_staff_offer_falls_back_to_voicemail(): void
    {
        $this->pushDevice($this->staff);
        $call = $this->incomingCall();
        $this->route($call);
        $offer = VoiceIncomingOffer::sole();
        $this->travel(46)->seconds();
        app(VoiceIncomingOfferService::class)->reconcile($offer->id);
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame('office', $call->fresh()->voicemail->greeting_kind);
        $this->assertNoAiOrForward();
    }

    private function pushDevice(User $user): VoicePushSubscription
    {
        static $keys = null;
        $keys ??= VAPID::createVapidKeys();
        config(['voice_push.enabled' => true, 'voice_push.public_key' => $keys['publicKey'], 'voice_push.private_key' => $keys['privateKey'],
            'voice_push.subject' => 'mailto:support@example.test', 'voice_push.connection' => 'database']);
        $identity = app(VoicePushService::class)->subscribe($user, [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/staff-'.$user->id,
            'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')], 'label' => 'Browser',
        ]);

        return VoicePushSubscription::findOrFail($identity['id']);
    }

    private function incomingCall(): VoiceCall
    {
        return VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'ringing', 'call_control_id' => 'customer-'.Str::uuid(),
            'from_phone' => '+12025550200', 'to_phone' => '+12027803332', 'assistant_id' => 'must-not-start']);
    }

    private function route(VoiceCall $call): void
    {
        app(VoiceRoutingService::class)->beginInboundCall($call, []);
    }

    private function device(User $user): VoiceBrowserSession
    {
        return VoiceBrowserSession::create(['user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'credential-'.Str::uuid(),
            'sip_username' => 'gencred'.Str::random(10), 'status' => 'ready', 'registered' => true, 'heartbeat_at' => now(), 'expires_at' => now()->addHour()]);
    }

    private function assertNoAiOrForward(): void
    {
        Http::assertNotSent(fn ($r) => preg_match('~/actions/(ai_assistant_start|transfer|gather_using_speak)$~', $r->url()) === 1);
    }
}
