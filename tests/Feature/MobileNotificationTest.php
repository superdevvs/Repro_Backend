<?php

namespace Tests\Feature;

use App\Jobs\SendMobileNotification;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\User;
use App\Services\MobileNotifications\MobileNotificationService;
use App\Services\MobileNotifications\MobileNotificationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileNotificationTest extends TestCase
{
    use RefreshDatabase;
    private string $keyFile;
    private User $user;
    private string $bearer;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Http::preventStrayRequests();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'apns-unit-');
        file_put_contents($this->keyFile, $pem); chmod($this->keyFile, 0600);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'mobile_notifications.enabled' => true, 'mobile_notifications.environment' => 'sandbox',
            'mobile_notifications.team_id' => 'TESTTEAM01', 'mobile_notifications.key_id' => 'TESTKEY001',
            'mobile_notifications.private_key_path' => $this->keyFile]);
        $this->user = User::factory()->create(['role' => 'superadmin']);
        $this->bearer = $this->user->createToken('test-native')->plainTextToken;
    }
    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }
    private function register(?string $installation = null, ?string $bearer = null, string $token = 'aabbccdd'): MobileNotificationDevice
    {
        $this->app['auth']->forgetGuards();
        $installation ??= (string) Str::uuid();
        $this->withToken($bearer ?? $this->bearer)->putJson('/api/mobile-notifications/devices/'.$installation,
            ['sequence' => ++$this->sequence, 'token' => $token, 'environment' => 'sandbox', 'permission' => 'authorized'])
            ->assertOk()->assertJson(['registered' => true, 'installation_id' => $installation]);
        return MobileNotificationDevice::findOrFail($installation);
    }
    private function delivery(MobileNotificationDevice $device): MobileNotificationDelivery
    {
        $contact = \App\Models\Contact::create(['name' => 'Test', 'phone' => '+12025550102', 'type' => 'client']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'status' => 'OPEN', 'sms_inbox_active' => true, 'unread_for_user_ids_json' => [$device->user_id]]);
        $message = Message::create(['channel' => 'SMS', 'direction' => 'INBOUND', 'provider' => 'TELNYX',
            'from_address' => '+12025550102', 'to_address' => '+12025550103', 'body_text' => 'PRIVATE CONTENT',
            'status' => 'DELIVERED', 'thread_id' => $thread->id]);
        return MobileNotificationDelivery::firstOrCreate(['device_id' => $device->id,
            'event_key' => 'sms:'.$message->id.':'.$device->user_id], ['user_id' => $device->user_id, 'kind' => 'sms', 'source_id' => (string) $message->id,
            'status' => 'queued', 'expires_at' => now()->addMinutes(MobileNotificationService::DELIVERY_LIFETIME_MINUTES)]);
    }
    private function runDelivery(MobileNotificationDelivery $delivery): void
    {
        (new SendMobileNotification($delivery->id))->handle(app(MobileNotificationService::class), app(MobileNotificationTransport::class));
    }
    public function test_disabled_and_unauthenticated_registration_fail_closed(): void
    {
        $this->getJson('/api/mobile-notifications/capabilities')->assertUnauthorized();
        config(['mobile_notifications.enabled' => false]);
        $this->withToken($this->bearer)->getJson('/api/mobile-notifications/capabilities')->assertOk()->assertJsonPath('enabled', false);
        $this->putJson('/api/mobile-notifications/devices/'.Str::uuid(), ['sequence' => ++$this->sequence, 'token' => 'aabb', 'environment' => 'sandbox', 'permission' => 'authorized'])->assertStatus(503);
        $this->assertDatabaseCount('mobile_notification_devices', 0);
    }
    public function test_registration_encrypts_token_and_does_not_expose_it(): void
    {
        $device = $this->register();
        $this->assertSame('aabbccdd', $device->token);
        $this->assertNotSame('aabbccdd', DB::table('mobile_notification_devices')->value('token'));
        $this->assertArrayNotHasKey('token', $device->toArray());
        $this->assertSame($this->user->id, $device->activeOwner()->id);
    }
    public function test_wrong_environment_invalid_tokens_and_denied_permission_are_rejected(): void
    {
        foreach ([['token' => 'aa/cc'], ['token' => 'abc'], ['environment' => 'production'], ['permission' => 'denied']] as $change) {
            $this->withToken($this->bearer)->putJson('/api/mobile-notifications/devices/'.Str::uuid(),
                array_merge(['sequence' => ++$this->sequence, 'token' => 'aabb', 'environment' => 'sandbox', 'permission' => 'authorized'], $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('mobile_notification_devices', 0);
    }
    public function test_registration_is_atomic_current_owner_and_late_old_owner_delete_cannot_revoke_it(): void
    {
        $device = $this->register();
        $other = User::factory()->create(['role' => 'admin']);
        $bearer = $other->createToken('other-test')->plainTextToken;
        $this->register($device->id, $bearer, 'ddeeffaa');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->bearer)->deleteJson('/api/mobile-notifications/devices/'.$device->id, ['sequence' => ++$this->sequence])->assertOk();
        $device->refresh();
        $this->assertSame($other->id, $device->activeOwner()->id);
        $this->assertNull($device->revoked_at);
    }
    public function test_removed_or_expired_login_and_device_lease_are_inactive(): void
    {
        $device = $this->register();
        $this->user->tokens()->update(['expires_at' => now()->subSecond()]);
        $this->assertNull($device->activeOwner());
        $this->user->tokens()->update(['expires_at' => null]);
        $device->update(['expires_at' => now()->subSecond()]);
        $this->assertNull($device->activeOwner());
        $device->update(['expires_at' => now()->addDay()]);
        $this->user->tokens()->delete();
        $this->assertNull($device->activeOwner());
    }
    public function test_accepted_alert_uses_sanitized_message_preview_without_metadata_or_badge_guess(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response([], 200)]);
        $device = $this->register(); $delivery = $this->delivery($device);
        Message::findOrFail($delivery->source_id)->update(['metadata' => ['provider_secret' => 'PROVIDER_METADATA_SENTINEL']]);
        $this->runDelivery($delivery);
        $this->assertSame('accepted', $delivery->fresh()->status);
        Http::assertSent(function ($request) use ($delivery) {
            $this->assertSame('Message from Test', $request['aps']['alert']['title']);
            $this->assertSame('PRIVATE CONTENT', $request['aps']['alert']['body']);
            $this->assertStringNotContainsString('PROVIDER_METADATA_SENTINEL', json_encode($request->data()));
            $this->assertArrayNotHasKey('badge', $request['aps']);
            return $request->hasHeader('apns-push-type', 'alert') && $request->hasHeader('apns-id', $delivery->id)
                && $request['repro']['user_id'] === (string) $this->user->id && $request['repro']['destination'] === 'messages';
        });
        $this->runDelivery($delivery); Http::assertSentCount(1);
    }
    public function test_revocation_account_switch_and_permission_loss_suppress_queued_pushes(): void
    {
        $device = $this->register(); $delivery = $this->delivery($device);
        $this->user->update(['role' => 'client']);
        $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status); Http::assertNothingSent();
    }
    public function test_account_switch_suppresses_old_owner_delivery(): void
    {
        $device = $this->register(); $delivery = $this->delivery($device);
        $other = User::factory()->create(['role' => 'superadmin']);
        $this->register($device->id, $other->createToken('new')->plainTextToken);
        $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status); Http::assertNothingSent();
    }
    public function test_invalid_device_token_is_revoked_but_acceptance_never_claims_delivery(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response(['reason' => 'Unregistered'], 410)]);
        $device = $this->register(); $delivery = $this->delivery($device); $this->runDelivery($delivery);
        $this->assertSame('rejected', $delivery->fresh()->status);
        $this->assertNotNull($device->fresh()->revoked_at);
    }
    public function test_network_timeout_stays_uncertain_and_does_not_replay(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('test timeout'));
        $device = $this->register(); $delivery = $this->delivery($device); $this->runDelivery($delivery);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        app(MobileNotificationService::class)->reconcile();
        $this->runDelivery($delivery); $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame(1, MobileNotificationDelivery::where('event_key', $delivery->event_key)->count());
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_late_register_cannot_revive_revoked_binding_and_missing_delete_creates_tombstone(): void
    {
        $installation = (string) Str::uuid();
        $this->withToken($this->bearer)->deleteJson('/api/mobile-notifications/devices/'.$installation, ['sequence' => 2])->assertOk();
        $this->putJson('/api/mobile-notifications/devices/'.$installation,
            ['sequence' => 1, 'token' => 'aabb', 'environment' => 'sandbox', 'permission' => 'authorized'])->assertStatus(409);
        $this->assertNull(MobileNotificationDevice::findOrFail($installation)->activeOwner());
        $this->sequence = 2;
        $this->register($installation);
        $this->assertSame($this->user->id, MobileNotificationDevice::findOrFail($installation)->activeOwner()->id);
    }
    public function test_late_cleanup_from_same_account_old_session_cannot_revoke_new_login(): void
    {
        $device = $this->register();
        $newBearer = $this->user->createToken('new-login')->plainTextToken;
        $this->register($device->id, $newBearer);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->bearer)->deleteJson('/api/mobile-notifications/devices/'.$device->id, ['sequence' => 999])->assertOk();
        $this->assertNotNull($device->fresh()->activeOwner());
        $this->putJson('/api/mobile-notifications/devices/'.$device->id,
            ['sequence' => 1000, 'token' => 'aabb', 'environment' => 'sandbox', 'permission' => 'authorized'])->assertStatus(409);
    }
    public function test_already_read_sms_is_not_pushed_and_reconciler_repairs_pending_enqueue(): void
    {
        $device = $this->register(); $delivery = $this->delivery($device);
        app(MobileNotificationService::class)->reconcile();
        Queue::assertPushed(SendMobileNotification::class, fn ($job) => $job->deliveryID === $delivery->id);
        $message = Message::findOrFail($delivery->source_id);
        $message->thread->markReadForUser($this->user->id);
        $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status); Http::assertNothingSent();
    }
    public function test_missing_keys_or_wrong_topic_never_enables_capabilities(): void
    {
        config(['mobile_notifications.private_key_path' => '/nonexistent-key']);
        $this->assertFalse((bool) app(MobileNotificationTransport::class)->ready());
        config(['mobile_notifications.private_key_path' => $this->keyFile, 'mobile_notifications.topic' => 'arbitrary.topic']);
        $this->assertFalse((bool) app(MobileNotificationTransport::class)->ready());
    }

    public function test_late_invalid_token_response_does_not_revoke_new_same_token_registration(): void
    {
        $device = $this->register(); $delivery = $this->delivery($device);
        Http::fake(function () use ($device) {
            $this->register($device->id);
            return Http::response(['reason' => 'Unregistered'], 410);
        });
        $this->runDelivery($delivery);
        $this->assertSame('rejected', $delivery->fresh()->status);
        $this->assertSame(2, (int) $device->fresh()->sequence);
        $this->assertNotNull($device->fresh()->activeOwner());
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_old_login_cannot_move_same_apns_token_back_from_new_installation(): void
    {
        $old = $this->register();
        $newBearer = $this->user->createToken('new-installation-login')->plainTextToken;
        $new = $this->register(bearer: $newBearer);
        $this->assertNull($old->fresh());
        $this->app['auth']->forgetGuards();
        $this->withToken($this->bearer)->putJson('/api/mobile-notifications/devices/'.$old->id,
            ['sequence' => 999, 'token' => 'aabbccdd', 'environment' => 'sandbox', 'permission' => 'authorized'])->assertConflict();
        $this->assertNotNull($new->fresh()->activeOwner());
        $this->assertSame($new->personal_access_token_id, $new->fresh()->personal_access_token_id);
        $this->assertDatabaseCount('mobile_notification_devices', 1);
    }

    public function test_equal_login_cannot_reorder_different_installation_uuids(): void
    {
        $device = $this->register();
        $this->withToken($this->bearer)->putJson('/api/mobile-notifications/devices/'.Str::uuid(),
            ['sequence' => 999, 'token' => 'aabbccdd', 'environment' => 'sandbox', 'permission' => 'authorized'])->assertConflict();
        $this->assertSame(1, (int) $device->fresh()->sequence);
        $this->assertNotNull($device->fresh()->activeOwner());
    }

    public function test_explicit_temporary_rejections_retry_same_event_with_backoff_then_accept(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::sequence()->push(['reason' => 'InternalServerError'], 500)
            ->push(['reason' => 'Shutdown'], 503)->push([], 200)]);
        $device = $this->register(); $delivery = $this->delivery($device);
        $expiry = $delivery->expires_at->timestamp;
        $this->runDelivery($delivery);
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertEqualsWithDelta(900, now()->diffInSeconds($delivery->fresh()->not_before), 1);
        $this->runDelivery($delivery); Http::assertSentCount(1);
        $this->travelTo($delivery->fresh()->not_before);
        $this->runDelivery($delivery);
        $this->assertEqualsWithDelta(1800, now()->diffInSeconds($delivery->fresh()->not_before), 1);
        $this->travelTo($delivery->fresh()->not_before);
        $this->runDelivery($delivery); $this->runDelivery($delivery);
        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame(3, $delivery->fresh()->attempts);
        $this->assertSame($expiry, $delivery->fresh()->expires_at->timestamp);
        Http::assertSentCount(3);
        $this->assertCount(3, Http::recorded(fn ($request) => $request->hasHeader('apns-id', $delivery->id)));
    }

    public function test_temporary_rejections_stop_after_three_attempts_without_reconciler_replay(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response(['reason' => 'TooManyRequests'], 429)]);
        $device = $this->register(); $delivery = $this->delivery($device);
        for ($i = 0; $i < 3; $i++) {
            if ($delivery->fresh()->not_before) $this->travelTo($delivery->fresh()->not_before);
            $this->runDelivery($delivery);
        }
        $this->assertSame('retry_exhausted', $delivery->fresh()->status);
        $this->assertSame(3, $delivery->fresh()->attempts);
        app(MobileNotificationService::class)->reconcile();
        $this->runDelivery($delivery); Http::assertSentCount(3);
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_retry_respects_original_expiry_and_current_push_preference(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response([], 503)]);
        $device = $this->register(); $short = $this->delivery($device);
        $short->update(['expires_at' => now()->addSeconds(30)]);
        $this->runDelivery($short);
        $this->assertSame('retry_exhausted', $short->fresh()->status);
        $delivery = $this->delivery($device); $this->runDelivery($delivery);
        $this->user->update(['metadata' => ['preferences' => ['notificationSettings' => ['push' => false]]]]);
        $this->travelTo($delivery->fresh()->not_before);
        $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts); Http::assertSentCount(2);
    }

    public function test_retry_stops_after_login_token_removal(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::response([], 500)]);
        $device = $this->register(); $delivery = $this->delivery($device);
        $this->runDelivery($delivery);
        $this->user->tokens()->delete();
        $this->travelTo($delivery->fresh()->not_before); $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts); Http::assertSentCount(1);
    }

    public function test_provider_token_rate_limit_waits_twenty_minutes_and_unknown_429_fails_closed(): void
    {
        Http::fake(['api.sandbox.push.apple.com/*' => Http::sequence()
            ->push(['reason' => 'TooManyProviderTokenUpdates'], 429)->push(['reason' => 'UnknownReason'], 429)]);
        $device = $this->register(); $limited = $this->delivery($device);
        $this->runDelivery($limited);
        $this->assertSame('queued', $limited->fresh()->status);
        $this->assertEqualsWithDelta(1200, now()->diffInSeconds($limited->fresh()->not_before), 1);
        $unknown = $this->delivery($device); $this->runDelivery($unknown); $this->runDelivery($unknown);
        $this->assertSame('rejected', $unknown->fresh()->status);
        $this->assertSame(1, $unknown->fresh()->attempts); Http::assertSentCount(2);
    }
}
