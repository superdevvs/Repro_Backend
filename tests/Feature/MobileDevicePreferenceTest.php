<?php

namespace Tests\Feature;

use App\Jobs\SendMobileNotification;
use App\Models\MobileDevicePreference;
use App\Models\MobileNotificationDevice;
use App\Models\MobileNotificationDelivery;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\MobileNotifications\MobileNotificationService;
use App\Services\MobileNotifications\MobileNotificationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileDevicePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Http::preventStrayRequests();
        config(['mobile_notifications.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
    }

    private function phone(User $user): array
    {
        $token = $user->createToken('isolated-device-test');
        $device = MobileNotificationDevice::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'personal_access_token_id' => $token->accessToken->id, 'token' => 'aabb'.dechex($token->accessToken->id * 16),
            'sequence' => 1, 'environment' => 'sandbox', 'permission' => 'authorized', 'expires_at' => now()->addDay()]);
        return [$token, $device];
    }

    private function save(array $phone, array $changes, int $sequence = 1)
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($phone[0]->plainTextToken)->putJson('/api/mobile-notifications/preferences', [
            'installation_id' => $phone[1]->id, 'sequence' => $sequence,
            'preferences' => array_replace(MobileDevicePreference::defaults(), $changes),
        ]);
    }

    private function sms(User $user): Message
    {
        $contact = \App\Models\Contact::create(['name' => 'Fixture contact', 'phone' => '+12025550102', 'type' => 'client']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'status' => 'OPEN',
            'sms_inbox_active' => true, 'unread_for_user_ids_json' => [$user->id]]);
        return Message::create(['channel' => 'SMS', 'direction' => 'INBOUND', 'provider' => 'TELNYX',
            'from_address' => '+12025550102', 'to_address' => '+12025550103', 'body_text' => 'Fixture',
            'status' => 'DELIVERED', 'thread_id' => $thread->id]);
    }

    private function pending(MobileNotificationDevice $device, Message $message): MobileNotificationDelivery
    {
        return MobileNotificationDelivery::firstOrCreate(['device_id' => $device->id, 'event_key' => 'sms:'.$message->id.':'.$device->user_id],
            ['user_id' => $device->user_id, 'kind' => 'sms', 'source_id' => (string) $message->id,
                'status' => 'queued', 'expires_at' => now()->addHour()]);
    }

    public function test_preferences_are_private_to_each_sign_in_and_do_not_change_account_or_sibling(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $one = $this->phone($user); $two = $this->phone($user);
        $metadata = $user->getRawOriginal('metadata');
        $this->save($one, ['sms' => false, 'calls' => false])->assertOk()->assertJsonPath('preferences.sms', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($two[0]->plainTextToken)->getJson('/api/mobile-notifications/preferences')
            ->assertOk()->assertJsonPath('saved', false)->assertJsonPath('preferences.sms', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->save($two, ['email' => false])->assertOk();
        $this->assertFalse(MobileDevicePreference::find($one[0]->accessToken->id)->enabled('sms'));
        $this->assertTrue(MobileDevicePreference::find($two[0]->accessToken->id)->enabled('sms'));
        $this->assertSame($metadata, $user->fresh()->getRawOriginal('metadata'));
    }

    public function test_replays_are_idempotent_and_old_or_conflicting_writes_cannot_reenable(): void
    {
        $phone = $this->phone(User::factory()->create());
        $this->save($phone, [], 1)->assertOk();
        $this->save($phone, ['sms' => false], 2)->assertOk();
        $this->save($phone, ['sms' => false], 2)->assertOk();
        $this->save($phone, [], 1)->assertStatus(409);
        $this->save($phone, [], 2)->assertStatus(409);
        $this->assertFalse(MobileDevicePreference::find($phone[0]->accessToken->id)->enabled('sms'));
    }

    public function test_cannot_target_another_installation_or_user_and_revoked_login_cannot_write(): void
    {
        $one = $this->phone(User::factory()->create());
        $two = $this->phone(User::factory()->create());
        $this->save($one, ['sms' => false])->assertOk();
        $this->save($two, [])->assertOk();
        $this->save([$two[0], $one[1]], ['sms' => false], 2)->assertForbidden();
        $this->assertTrue(MobileDevicePreference::find($two[0]->accessToken->id)->enabled('sms'));
        $one[0]->accessToken->delete();
        $this->save($one, [], 3)->assertUnauthorized();
        $this->assertNull(MobileDevicePreference::find($one[0]->accessToken->id));
    }

    public function test_explicit_fields_are_required_and_no_permission_or_provider_setup_is_needed(): void
    {
        config(['mobile_notifications.enabled' => false]);
        $phone = $this->phone(User::factory()->create());
        $phone[1]->update(['permission' => 'denied', 'revoked_at' => now()]);
        $this->save($phone, ['calls' => false])->assertOk();
        $this->putJson('/api/mobile-notifications/preferences', ['installation_id' => $phone[1]->id,
            'sequence' => 2, 'preferences' => ['sms' => false]])->assertUnprocessable();
        $this->save($phone, ['other' => true], 2)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_disabling_cancels_only_this_phones_queued_alerts_and_worker_rechecks_fresh_preferences(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $one = $this->phone($user); $two = $this->phone($user);
        $message = $this->sms($user);
        $muted = $this->pending($one[1], $message); $other = $this->pending($two[1], $message);
        $this->save($one, ['sms' => false])->assertOk();
        $this->assertSame('suppressed', $muted->fresh()->status);
        $this->assertSame('queued', $other->fresh()->status);
        // Simulate a delayed job from before the settings update.
        $muted->update(['status' => 'queued']);
        (new SendMobileNotification($muted->id))->handle(app(MobileNotificationService::class), app(MobileNotificationTransport::class));
        $this->assertSame('suppressed', $muted->fresh()->status);
        $this->assertSame(0, $muted->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_muted_events_stay_suppressed_and_reenable_does_not_replay_history(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $one = $this->phone($user); $two = $this->phone($user);
        $this->save($one, ['sms' => false])->assertOk();
        $old = $this->sms($user);
        $service = app(MobileNotificationService::class);
        $service->queueSMS($old->id);
        $this->assertFalse($service->deviceAllows($one[1], 'sms', (string) $old->id));
        $this->assertTrue($service->deviceAllows($two[1], 'sms', (string) $old->id));
        $this->travel(2)->seconds();
        $this->save($one, [], 2)->assertOk();
        $this->assertFalse($service->deviceAllows($one[1], 'sms', (string) $old->id));
        $this->travel(2)->seconds();
        $new = $this->sms($user);
        $this->assertTrue($service->deviceAllows($one[1], 'sms', (string) $new->id));
        $service->reconcile();
        $row = MobileNotificationDelivery::where('device_id', $one[1]->id)->where('source_id', (string) $old->id)->firstOrFail();
        $this->assertSame('suppressed', $row->status);
        Http::assertNothingSent();
    }

    public function test_all_alert_categories_map_without_muting_other_channels_or_bypassing_access(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $phone = $this->phone($user);
        $service = app(MobileNotificationService::class);
        $sequence = 0;
        foreach (['sms' => ['sms', 'shared_group'], 'email' => ['email'], 'support' => ['support'],
            'voicemail' => ['voicemail'], 'shoots' => ['shoot_activity'], 'account' => ['account_activity']] as $channel => $kinds) {
            $this->save($phone, [$channel => false], ++$sequence)->assertOk();
            foreach ($kinds as $kind) { $this->assertFalse($service->deviceAllows($phone[1], $kind, '999999')); }
        }
        $this->assertFalse($service->allowed($user, 'sms', '999999'));
        $this->assertFalse($service->deviceAllows($phone[1], 'unknown-kind', '1'));
    }
}
