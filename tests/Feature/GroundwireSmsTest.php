<?php

namespace Tests\Feature;

use App\Jobs\DeliverGroundwireSmsPush;
use App\Models\GroundwireSmsPush;
use App\Models\GroundwireSmsSend;
use App\Models\Message;
use App\Models\SmsNumber;
use App\Models\User;
use App\Models\VoiceLinePolicy;
use App\Models\VoiceSipDevice;
use App\Services\Messaging\GroundwireSmsPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroundwireSmsTest extends TestCase
{
    use RefreshDatabase;

    private VoiceSipDevice $device;

    private SmsNumber $number;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://example.test', 'services.telnyx.api_key' => 'test',
            'services.telnyx.sms_paused' => false, 'services.telnyx.voice.browser_enabled' => true]);
        Queue::fake();
        Http::preventStrayRequests();
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        // All HTTP remains faked, including the concrete provider pipeline.
        $this->app->bind(\App\Services\Messaging\Providers\TelnyxSmsProvider::class, fn () => new \App\Services\Messaging\Providers\TelnyxSmsProvider);
        $this->staff = User::factory()->create(['role' => 'admin']);
        VoiceLinePolicy::create(['phone_number' => '+12025550100', 'mode' => 'staff_voicemail', 'recipient_ids' => [$this->staff->id],
            'recipient_roles' => [], 'timezone' => 'Asia/Kolkata', 'weekly_hours' => []]);
        $this->number = SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550100', 'messaging_profile_id' => 'profile', 'owner_type' => 'SYSTEM']);
        $this->device = VoiceSipDevice::create(['user_id' => $this->staff->id, 'label' => 'Phone', 'creation_key' => (string) Str::uuid(),
            'caller_id' => '+12025550100', 'state' => 'ready', 'connection_id' => 'connection', 'enabled' => true,
            'sip_username' => 'private-user', 'sip_password' => 'secret-password', 'sms_number' => '+12025550100', 'sms_bound_at' => now()->subMinute()]);
    }

    private function postSms(string $action, array $data = [])
    {
        return $this->postJson('/api/groundwire/sms/'.$action, $data + ['username' => 'private-user', 'password' => 'secret-password', 'installid' => 'phone-one']);
    }

    private function message(array $attributes = []): Message
    {
        return Message::create($attributes + ['channel' => 'SMS', 'direction' => 'INBOUND', 'from_address' => '+12025550200',
            'to_address' => '+12025550100', 'body_text' => 'Test & + café', 'provider' => 'TELNYX', 'status' => 'DELIVERED', 'hidden_from_inbox' => false]);
    }

    public function test_provisioning_merges_only_messaging_keys_and_keeps_passwords_out_of_urls(): void
    {
        $r = $this->get('/api/groundwire/sms/provision')->assertOk();
        $xml = simplexml_load_string($r->getContent());
        $this->assertSame('POST', (string) $xml->genericSmsSendMethod);
        $this->assertStringContainsString('password=%account[password]%', (string) $xml->genericSmsSendPostData);
        $this->assertStringContainsString('%pushTokenOther%', (string) $xml->pushTokenReporterPostData);
        foreach ($xml->children() as $key => $value) {
            $this->assertNotContains($key, ['username', 'password', 'host', 'transport', 'port', 'sdes']);
            if (str_ends_with($key, 'Url')) {
                $this->assertStringNotContainsString('password', (string) $value);
            }
        }
        $this->assertStringNotContainsString('secret-password', $r->getContent());
    }

    public function test_credentials_are_required_and_invalid_revoked_paused_and_reassigned_devices_fail_closed(): void
    {
        $this->postJson('/api/groundwire/sms/fetch')->assertStatus(422);
        $this->postSms('fetch', ['password' => 'wrong'])->assertForbidden();
        $this->get('/api/groundwire/sms/fetch?username=private-user&password=secret-password')->assertStatus(405);
        foreach (['enabled' => false, 'revoked_at' => now(), 'state' => 'uncertain'] as $key => $value) {
            $this->device->update([$key => $value]);
            $this->postSms('fetch')->assertForbidden();
            $this->device->update(['enabled' => true, 'revoked_at' => null, 'state' => 'ready']);
        }
        VoiceLinePolicy::first()->update(['recipient_ids' => []]);
        $this->postSms('fetch')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_is_scoped_to_physical_line_and_never_leaks_other_messages_on_the_contact_thread(): void
    {
        $one = $this->message();
        $this->message(['to_address' => '+18885550100', 'body_text' => 'Other business secret']);
        $this->message(['channel' => 'EMAIL', 'body_text' => 'Email secret']);
        $this->message(['hidden_from_inbox' => true, 'body_text' => 'Hidden secret']);
        $this->message(['direction' => 'OUTBOUND', 'from_address' => '+18885550100', 'body_text' => 'Other outbound secret']);
        $sent = $this->message(['direction' => 'OUTBOUND', 'from_address' => '+12025550100', 'to_address' => '+12025550200', 'status' => 'SENT']);
        $r = $this->postSms('fetch')->assertOk()->assertJsonCount(1, 'received_smss')->assertJsonCount(1, 'sent_smss');
        $r->assertJsonPath('received_smss.0.sms_id', (string) $one->id)->assertJsonPath('sent_smss.0.sms_id', (string) $sent->id);
        $this->assertStringNotContainsString('secret', $r->getContent());
        $this->postSms('fetch', ['last_known_sms_id' => (string) $one->id, 'last_known_sent_sms_id' => (string) $sent->id])
            ->assertOk()->assertJsonCount(0, 'received_smss')->assertJsonCount(0, 'sent_smss');
    }

    public function test_one_install_is_bound_and_pairing_reset_is_owner_only(): void
    {
        $this->postSms('fetch')->assertOk();
        $this->postSms('fetch', ['installid' => 'phone-two'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']), 'sanctum');
        $this->patchJson('/api/voice/sip/devices/'.$this->device->id.'/sms', ['reset_pairing' => true])->assertForbidden();
        $this->actingAs($this->staff, 'sanctum');
        $this->patchJson('/api/voice/sip/devices/'.$this->device->id.'/sms', ['reset_pairing' => true])->assertOk();
        $this->postSms('fetch', ['installid' => 'phone-two'])->assertOk();
    }

    public function test_send_uses_fixed_business_sender_preserves_unicode_and_deduplicates_retries(): void
    {
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'provider-one']])]);
        $data = ['to' => '+12025550200', 'body' => 'Test & + café', 'content_type' => 'text/plain', 'client_id' => 'imdn-one:positive-delivery'];
        $r = $this->postSms('send', $data)->assertOk();
        $this->postSms('send', $data)->assertOk()->assertJsonPath('sms_id', $r->json('sms_id'));
        $this->assertDatabaseCount('groundwire_sms_sends', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['from'] === '+12025550100' && $r['text'] === 'Test & + café');
        $this->postSms('send', ['to' => '+12025550200', 'body' => 'Changed', 'client_id' => $data['client_id']])->assertStatus(409);
        $this->postSms('fetch')->assertOk()->assertJsonPath('sent_smss.0.sms_id', $r->json('sms_id'));
    }

    public function test_phone_can_reply_to_another_managed_business_number(): void
    {
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550200', 'messaging_profile_id' => 'profile', 'owner_type' => 'SYSTEM']);
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'internal-reply']])]);

        $this->postSms('send', ['to' => '+12025550200', 'body' => 'TEST OK'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['from'] === '+12025550100'
            && $request['to'] === '+12025550200' && $request['text'] === 'TEST OK');
        $this->assertDatabaseHas('messages', ['direction' => 'OUTBOUND', 'from_address' => '+12025550100',
            'to_address' => '+12025550200', 'body_text' => 'TEST OK', 'status' => 'SENT']);
    }

    public function test_phone_cannot_text_its_own_normalized_sender_number(): void
    {
        $this->postSms('send', ['to' => '(202) 555-0100', 'body' => 'Self message'])->assertStatus(422)
            ->assertJsonPath('message', 'Choose a different recipient number.');

        $this->assertDatabaseCount('groundwire_sms_sends', 0);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_ambiguous_provider_send_is_not_retried_or_resent(): void
    {
        Http::fake(['api.telnyx.com/*' => Http::response([], 503)]);
        $data = ['to' => '+12025550200', 'body' => 'Ambiguous test'];
        $this->postSms('send', $data)->assertStatus(409);
        $this->postSms('send', $data)->assertStatus(409);
        Http::assertSentCount(1);
        $this->assertSame('uncertain', GroundwireSmsSend::first()->status);
    }

    public function test_push_tokens_are_encrypted_and_notifications_are_deduplicated_and_private(): void
    {
        $this->postSms('push-token', ['token' => 'sensitive-token', 'app_id' => 'cz.acrobits.groundwire', 'selector' => 'selector-one'])->assertOk();
        $this->assertStringNotContainsString('sensitive-token', DB::table('voice_sip_devices')->value('sms_push'));
        $this->assertStringNotContainsString('sensitive-token', $this->device->fresh()->toJson());
        $m = $this->message(['body_text' => 'Private message body']);
        $service = app(GroundwireSmsPushService::class);
        $service->queueIncoming($m->id);
        $service->queueIncoming($m->id);
        $this->assertDatabaseCount('groundwire_sms_pushes', 1);
        Queue::assertPushed(DeliverGroundwireSmsPush::class);
        Http::fake(['pnm.cloudsoftphone.com/*' => Http::response(['status' => 200])]);
        $service->deliver(GroundwireSmsPush::first()->id);
        $service->deliver(GroundwireSmsPush::first()->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['Id'] === (string) $m->id && ! str_contains($r->body(), 'Private message body'));
        $this->assertSame('accepted', GroundwireSmsPush::first()->status);
    }

    public function test_pausing_or_replacing_token_cancels_a_queued_push(): void
    {
        $this->postSms('push-token', ['token' => 'old', 'app_id' => 'app', 'selector' => 'selector'])->assertOk();
        $m = $this->message();
        $service = app(GroundwireSmsPushService::class);
        $service->queueIncoming($m->id);
        $this->device->update(['enabled' => false]);
        $service->deliver(GroundwireSmsPush::first()->id);
        Http::assertNothingSent();
        $this->assertSame('expired', GroundwireSmsPush::first()->status);
    }

    public function test_opt_out_and_media_requests_never_reach_the_carrier(): void
    {
        \App\Models\Contact::create(['name' => 'Opted out', 'phone' => '+12025550200', 'sms_opt_out' => true]);
        $this->postSms('send', ['to' => '+12025550200', 'body' => 'Must not send'])->assertStatus(409);
        $this->postSms('send', ['to' => '+12025550300', 'body' => '{}', 'content_type' => 'application/x-acro-filetransfer+json'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_sms_line_cannot_change_under_existing_phone_conversations(): void
    {
        $this->staff->update(['role' => 'superadmin']);
        VoiceLinePolicy::create(['phone_number' => '+12025550300', 'mode' => 'staff_voicemail', 'recipient_ids' => [$this->staff->id], 'recipient_roles' => [], 'timezone' => 'UTC', 'weekly_hours' => []]);
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550300', 'messaging_profile_id' => 'profile', 'owner_type' => 'SYSTEM']);
        $this->postSms('fetch')->assertOk();
        $this->device->update(['caller_id' => '+12025550300']);
        $this->assertSame('+12025550100', $this->device->fresh()->sms_number);
        $this->actingAs($this->staff, 'sanctum');
        $this->patchJson('/api/voice/sip/devices/'.$this->device->id.'/sms', ['number' => '+12025550300'])->assertStatus(409);
        $this->assertSame('+12025550100', $this->device->fresh()->sms_number);
    }

    public function test_duplicate_number_rows_block_access_instead_of_selecting_an_arbitrary_profile(): void
    {
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '2025550100', 'messaging_profile_id' => 'wrong-profile', 'owner_type' => 'SYSTEM']);
        $this->postSms('fetch')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_push_token_is_cleared_but_removal_does_not_revoke_voice(): void
    {
        $this->postSms('push-token', ['token' => 'old', 'app_id' => 'app', 'selector' => 'selector'])->assertOk();
        $m = $this->message();
        $service = app(GroundwireSmsPushService::class);
        $service->queueIncoming($m->id);
        Http::fake(['pnm.cloudsoftphone.com/*' => Http::response(['status' => 404])]);
        $service->deliver(GroundwireSmsPush::first()->id);
        $this->assertNull($this->device->fresh()->sms_push);
        $this->postSms('remove')->assertOk();
        $this->assertTrue($this->device->fresh()->enabled);
        $this->assertSame('ready', $this->device->fresh()->state);
    }
}
