<?php

namespace Tests\Feature;

use App\Jobs\SendVoicePush;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceIncomingOffer;
use App\Models\VoicePushDelivery;
use App\Models\VoicePushSubscription;
use App\Services\Voice\VoicePushService;
use App\Services\Voice\VoicePushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;
use Mockery;
use Tests\TestCase;

class VoicePushTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$keys ??= VAPID::createVapidKeys();
        config(['voice_push.enabled' => true, 'voice_push.public_key' => self::$keys['publicKey'], 'voice_push.private_key' => self::$keys['privateKey'], 'voice_push.subject' => 'mailto:support@example.test', 'voice_push.connection' => 'database']);
        Queue::fake();
        $this->operator = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->operator, 'sanctum');
    }

    private function payload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/test-one'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::$keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')], 'label' => 'Test browser'];
    }

    private function subscribe(): array
    {
        return $this->postJson('/api/voice/push/subscriptions', $this->payload())->assertCreated()->json();
    }

    private function incoming(): VoicePushDelivery
    {
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'inbound', 'status' => 'ringing']);
        $offer = VoiceIncomingOffer::create(['voice_call_id' => $call->id, 'status' => 'waiting', 'eligible_user_ids' => [$this->operator->id], 'expires_at' => now()->addSeconds(45)]);
        app(VoicePushService::class)->notifyIncoming($call, $offer->id, [$this->operator->id], $offer->expires_at);

        return VoicePushDelivery::where('offer_id', $offer->id)->firstOrFail();
    }

    public function test_configuration_subscription_is_encrypted_and_devices_are_private(): void
    {
        $this->getJson('/api/voice/push/settings')->assertOk()->assertJsonPath('configured', true)->assertJsonMissing(['private_key' => self::$keys['privateKey']]);
        $identity = $this->subscribe();
        $this->assertNotSame($this->payload()['endpoint'], DB::table('voice_push_subscriptions')->value('endpoint'));
        $this->getJson('/api/voice/push/settings')->assertJsonPath('devices.0.label', 'Test browser')->assertJsonMissingPath('devices.0.endpoint')->assertJsonMissingPath('devices.0.revoke_token');
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other, 'sanctum')->getJson('/api/voice/push/settings')->assertJsonCount(0, 'devices');
        $this->deleteJson('/api/voice/push/subscriptions/'.$identity['id'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'photographer']), 'sanctum')->postJson('/api/voice/push/subscriptions', $this->payload())->assertForbidden();
    }

    public function test_unconfigured_and_arbitrary_endpoints_are_rejected_without_network(): void
    {
        foreach (['https://127.0.0.1/secret', 'https://fcm.googleapis.com.evil.test/x', 'https://fcm.googleapis.com@evil.test/x', 'http://fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $url) {
            $this->postJson('/api/voice/push/subscriptions', $this->payload($url))->assertUnprocessable();
        }
        config(['voice_push.enabled' => false]);
        $this->postJson('/api/voice/push/subscriptions', $this->payload())->assertConflict();
        $this->getJson('/api/voice/push/settings')->assertJsonPath('configured', false)->assertJsonPath('public_key', null);
        $this->assertDatabaseCount('voice_push_subscriptions', 0);
    }

    public function test_revoke_capability_works_after_logout_and_cannot_revoke_another_device(): void
    {
        $identity = $this->subscribe();
        $this->postJson('/api/voice/push/revoke', ['id' => $identity['id'], 'token' => str_repeat('x', 64)])->assertNoContent();
        $this->assertNull(VoicePushSubscription::find($identity['id'])->revoked_at);
        $this->postJson('/api/voice/push/revoke', ['id' => $identity['id'], 'token' => $identity['revoke_token']])->assertNoContent();
        $this->assertNotNull(VoicePushSubscription::find($identity['id'])->revoked_at);
    }

    public function test_incoming_push_is_durable_generic_and_ends_after_offer_is_claimed(): void
    {
        $this->subscribe();
        $delivery = $this->incoming();
        Queue::assertPushed(SendVoicePush::class, fn ($job) => $job->deliveryId === $delivery->id && $job->connection === 'database' && $job->queue === 'voice-notifications' && $job->afterCommit);
        $transport = Mockery::mock(VoicePushTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(function ($subscription, $payload, $ttl) use ($delivery) {
            $this->assertSame('/calls?offer='.$delivery->offer_id, $payload['url']);
            $this->assertArrayNotHasKey('caller_name', $payload);
            $this->assertArrayNotHasKey('remote_phone', $payload);
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(45, $ttl);

            return true;
        })->andReturn(['accepted' => true, 'expired' => false, 'status' => 201]);
        $this->app->instance(VoicePushTransport::class, $transport);
        $lock = Cache::lock('voice-push-delivery:'.$delivery->id, 30);
        $this->assertTrue($lock->get());
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('queued', $delivery->fresh()->status);
        $lock->release();
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('accepted', $delivery->fresh()->status);
        app(VoicePushService::class)->closeOffer($delivery->offer_id);
        $this->assertDatabaseHas('voice_push_deliveries', ['offer_id' => $delivery->offer_id, 'event' => 'closed']);
        $delivery->refresh()->update(['status' => 'queued']);
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_delivery_rechecks_permission_subscription_scope_preferences_and_expiry(): void
    {
        $this->subscribe();
        $transport = Mockery::mock(VoicePushTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(VoicePushTransport::class, $transport);
        $delivery = $this->incoming();
        $this->patchJson('/api/voice/push/settings', ['incoming_calls' => false])->assertOk();
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->patchJson('/api/voice/push/settings', ['incoming_calls' => true])->assertOk();
        $delivery = $this->incoming();
        $this->subscribe(); // New local scope invalidates old queue work.
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('expired', $delivery->fresh()->status);
        $delivery = $this->incoming();
        $this->operator->update(['role' => 'client']);
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNotNull($delivery->subscription->fresh()->revoked_at);
    }

    public function test_expired_gateway_subscription_is_revoked_and_test_is_never_reported_delivered(): void
    {
        $identity = $this->subscribe();
        $response = $this->postJson('/api/voice/push/subscriptions/'.$identity['id'].'/test')->assertAccepted()->assertJsonPath('status', 'queued');
        $transport = Mockery::mock(VoicePushTransport::class);
        $transport->shouldReceive('send')->once()->andReturn(['accepted' => false, 'expired' => true, 'status' => 410]);
        $this->app->instance(VoicePushTransport::class, $transport);
        app(VoicePushService::class)->deliver($response['delivery_id']);
        $this->getJson('/api/voice/push/deliveries/'.$response['delivery_id'])->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('http_status', 410);
        $this->assertNotNull(VoicePushSubscription::find($identity['id'])->revoked_at);
    }

    public function test_locked_accounts_do_not_receive_and_exhausted_retries_become_failed(): void
    {
        $this->subscribe();
        $delivery = $this->incoming();
        $this->operator->update(['account_status' => 'locked']);
        $transport = Mockery::mock(VoicePushTransport::class);
        $transport->shouldNotReceive('send');
        $this->app->instance(VoicePushTransport::class, $transport);
        app(VoicePushService::class)->deliver($delivery->id);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $delivery->update(['status' => 'retrying']);
        (new SendVoicePush($delivery->id))->failed(new \RuntimeException('Safe test error'));
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('push_attempts_exhausted', $delivery->fresh()->error_code);
    }

    public function test_test_diagnostics_expire_without_a_worker_and_device_count_is_bounded(): void
    {
        $identity = $this->subscribe();
        $response = $this->postJson('/api/voice/push/subscriptions/'.$identity['id'].'/test')->assertAccepted();
        $this->travel(61)->seconds();
        $this->getJson('/api/voice/push/deliveries/'.$response['delivery_id'])->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('error_code', 'notification_window_elapsed');
        $prototype = VoicePushSubscription::findOrFail($identity['id']);
        for ($i = 1; $i < 20; $i++) {
            $copy = $prototype->replicate();
            $copy->endpoint_hash = hash('sha256', 'local-device-'.$i);
            $copy->save();
        }
        $this->postJson('/api/voice/push/subscriptions', $this->payload('https://web.push.apple.com/test-new'))->assertUnprocessable();
        $this->subscribe(); // Updating this existing device remains possible at the limit.
        $this->assertDatabaseCount('voice_push_subscriptions', 20);
    }
}
