<?php

namespace Tests\Feature;

use App\Jobs\SendMobileNotification;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\MobileNotifications\MobileNotificationService;
use App\Services\MobileNotifications\MobileNotificationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileNotificationCoverageTest extends TestCase
{
    use RefreshDatabase;
    private MobileNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'mobile_notifications.enabled' => true]);
        $this->service = app(MobileNotificationService::class);
    }

    private function user(string $role = 'client'): User { return User::factory()->create(['role' => $role]); }
    private function device(User $user): MobileNotificationDevice
    {
        return MobileNotificationDevice::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'personal_access_token_id' => $user->createToken('isolated-notification-test')->accessToken->id,
            'token' => 'aabb', 'sequence' => 1, 'environment' => 'sandbox', 'permission' => 'authorized', 'expires_at' => now()->addDay()]);
    }
    private function email(array $users, array $overrides = []): Message
    {
        $contact = \App\Models\Contact::create(['name' => 'TEST contact', 'email' => 'sender@example.test', 'type' => 'client']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'EMAIL', 'status' => 'OPEN', 'unread_for_user_ids_json' => array_map(fn ($u) => $u->id, $users)]);
        return Message::create([...['channel' => 'EMAIL', 'direction' => 'INBOUND', 'provider' => 'LOCAL_SMTP',
            'from_address' => 'sender@example.test', 'to_address' => 'inbox@example.test', 'body_text' => 'PRIVATE TEST CONTENT',
            'status' => 'DELIVERED', 'thread_id' => $thread->id], ...$overrides]);
    }
    private function ticket(User $owner): SupportTicket
    {
        return SupportTicket::create(['requester_id' => $owner->id, 'request_key' => (string) Str::uuid(),
            'subject' => 'PRIVATE TEST SUBJECT', 'category' => 'delivery', 'status' => 'open', 'priority' => 'normal', 'version' => 1]);
    }
    private function activity(Shoot $shoot, string $action = 'shoot_scheduled', ?User $actor = null): ShootActivityLog
    {
        return ShootActivityLog::create(['shoot_id' => $shoot->id, 'user_id' => $actor?->id, 'action' => $action, 'description' => 'PRIVATE TEST PROPERTY']);
    }
    private function receipt(User $user, array $ids = [], ?int $seen = null): void
    {
        DB::table('notification_read_states')->updateOrInsert(['user_id' => $user->id, 'context' => strtolower($user->role)],
            ['read_ids' => json_encode((object) array_fill_keys($ids, now()->getTimestampMs())), 'last_seen_at' => $seen, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function runDelivery(MobileNotificationDelivery $delivery): void
    {
        (new SendMobileNotification($delivery->id))->handle($this->service, app(MobileNotificationTransport::class));
    }

    public function test_email_requires_current_staff_permission_unread_thread_and_visible_received_message(): void
    {
        $roles = ['superadmin', 'admin', 'editing_manager', 'salesRep', 'photographer', 'editor', 'client'];
        $users = array_map(fn ($role) => $this->user($role), $roles);
        $email = $this->email($users);
        foreach ($users as $index => $user) $this->assertSame($index < 3, $this->service->allowed($user, 'email', (string) $email->id), $user->role);
        $admin = $users[1];
        $admin->update(['permission_overrides' => ['allow' => [], 'deny' => ['messaging-email-view']]]);
        $this->assertFalse($this->service->allowed($admin->fresh(), 'email', (string) $email->id));
        $email->thread->markReadForUser($users[0]->id);
        $this->assertFalse($this->service->allowed($users[0], 'email', (string) $email->id));
        $email->update(['hidden_from_inbox' => true]);
        $this->assertFalse($this->service->allowed($users[2], 'email', (string) $email->id));
    }

    public function test_email_conversion_and_sent_or_own_messages_never_duplicate_support_alerts(): void
    {
        $admin = $this->user('admin'); $owner = $this->user();
        $email = $this->email([$admin]);
        $ticket = $this->ticket($owner);
        $ticket->messages()->create(['author_id' => $owner->id, 'request_key' => (string) Str::uuid(),
            'body' => 'PRIVATE TEST CONTENT', 'kind' => 'opened', 'source_message_id' => $email->id]);
        $this->assertFalse($this->service->allowed($admin, 'email', (string) $email->id));
        foreach ([['direction' => 'OUTBOUND'], ['sender_user_id' => $admin->id], ['send_source' => 'INTERNAL_MESSAGE_NOTIFICATION'], ['status' => 'QUEUED']] as $change) {
            $other = $this->email([$admin], $change);
            $this->assertFalse($this->service->allowed($admin, 'email', (string) $other->id));
        }
    }

    public function test_support_is_owned_or_managed_and_internal_notes_never_reach_requester(): void
    {
        $admin = $this->user('admin'); $manager = $this->user('editing_manager'); $stranger = $this->user();
        foreach (['client', 'photographer', 'editor', 'salesRep'] as $role) {
            $owner = $this->user($role); $ticket = $this->ticket($owner);
            $reply = $ticket->messages()->create(['author_id' => $admin->id, 'request_key' => (string) Str::uuid(), 'body' => 'Reply', 'kind' => 'reply']);
            $this->assertTrue($this->service->allowed($owner, 'support', (string) $reply->id));
            $this->assertTrue($this->service->allowed($manager, 'support', (string) $reply->id));
            $this->assertFalse($this->service->allowed($stranger, 'support', (string) $reply->id));
            $this->assertFalse($this->service->allowed($admin, 'support', (string) $reply->id));
            $reply->update(['internal' => true]);
            $this->assertFalse($this->service->allowed($owner, 'support', (string) $reply->id));
            $this->assertTrue($this->service->allowed($manager, 'support', (string) $reply->id));
            $manager->update(['permission_overrides' => ['allow' => [], 'deny' => ['support-manage']]]);
            $this->assertFalse($this->service->allowed($manager->fresh(), 'support', (string) $reply->id));
            $manager->update(['permission_overrides' => null]);
        }
    }

    public function test_shoot_activity_matches_role_action_and_assignment_scopes_and_quiet_own_actions(): void
    {
        $client = $this->user(); $photographer = $this->user('photographer'); $editor = $this->user('editor');
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id, 'editor_id' => $editor->id]);
        $event = $this->activity($shoot);
        foreach (['admin', 'superadmin', 'salesRep', 'editing_manager'] as $role) $this->assertTrue($this->service->allowed($this->user($role), 'shoot_activity', (string) $event->id));
        $this->assertTrue($this->service->allowed($client, 'shoot_activity', (string) $event->id));
        $this->assertTrue($this->service->allowed($photographer, 'shoot_activity', (string) $event->id));
        $this->assertFalse($this->service->allowed($editor, 'shoot_activity', (string) $event->id));
        foreach (['client', 'photographer', 'editor'] as $role) $this->assertFalse($this->service->allowed($this->user($role), 'shoot_activity', (string) $event->id));
        $event->update(['action' => 'shoot_editing_started']);
        $this->assertTrue($this->service->allowed($editor, 'shoot_activity', (string) $event->id));
        $this->assertFalse($this->service->allowed($client, 'shoot_activity', (string) $event->id));
        $event->update(['action' => 'shoot_scheduled', 'user_id' => $client->id]);
        $this->assertFalse($this->service->allowed($client, 'shoot_activity', (string) $event->id));
        $event->update(['metadata' => ['suppress_notifications' => true]]);
        $this->assertFalse($this->service->allowed($photographer, 'shoot_activity', (string) $event->id));
        $event->update(['metadata' => null]); $shoot->delete();
        $this->assertFalse($this->service->allowed($photographer, 'shoot_activity', (string) $event->id));
    }

    public function test_account_activity_is_role_scoped_without_other_clients_or_editor_exposure(): void
    {
        $client = $this->user(); $rep = $this->user('salesRep');
        $event = UserActivityLog::record($client, 'email_bounced', 'Test account issue', metadata: ['sales_rep_id' => $rep->id]);
        foreach ([$client, $rep, $this->user('admin'), $this->user('superadmin'), $this->user('editing_manager')] as $user) $this->assertTrue($this->service->allowed($user, 'account_activity', (string) $event->id));
        foreach (['client', 'salesRep', 'editor', 'photographer'] as $role) $this->assertFalse($this->service->allowed($this->user($role), 'account_activity', (string) $event->id));
        $event->update(['event_type' => 'password_changed']);
        $this->assertFalse($this->service->allowed($client, 'account_activity', (string) $event->id));
    }

    public function test_read_receipts_and_snapshot_watermark_suppress_each_new_stream(): void
    {
        $admin = $this->user('admin'); $client = $this->user(); $ticket = $this->ticket($client);
        $support = $ticket->messages()->create(['author_id' => $client->id, 'request_key' => (string) Str::uuid(), 'body' => 'Test', 'kind' => 'reply']);
        $email = $this->email([$admin]);
        $shoot = $this->activity(Shoot::factory()->create());
        $account = UserActivityLog::record($client, 'email_bounced', 'Test');
        $events = [['email', $email, 'email-'], ['support', $support, 'support-'], ['shoot_activity', $shoot, 'sa-'], ['account_activity', $account, 'email-issue-']];
        foreach ($events as [$kind, $event, $prefix]) $this->assertTrue($this->service->allowed($admin, $kind, (string) $event->id));
        $this->receipt($admin, array_map(fn ($row) => $row[2].$row[1]->id, $events));
        foreach ($events as [$kind, $event]) $this->assertFalse($this->service->allowed($admin, $kind, (string) $event->id));
        $this->receipt($admin, [], now()->getTimestampMs());
        foreach ($events as [$kind, $event]) $this->assertFalse($this->service->allowed($admin, $kind, (string) $event->id));
    }

    public function test_push_preference_and_account_access_are_rechecked_at_delivery(): void
    {
        $admin = $this->user('admin'); $device = $this->device($admin); $email = $this->email([$admin]);
        $this->service->queueEmail($email->id);
        $delivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'email')->firstOrFail();
        $admin->update(['metadata' => ['preferences' => ['notificationSettings' => ['push' => false]]]]);
        $this->runDelivery($delivery); $this->assertSame('expired', $delivery->fresh()->status); Http::assertNothingSent();
        $admin->update(['metadata' => ['preferences' => ['notificationEmail' => false, 'notificationSMS' => false]]]);
        $this->assertTrue($this->service->allowed($admin->fresh(), 'email', (string) $email->id), 'Email/SMS delivery preferences do not disable a separate push channel.');
        $admin->update(['account_status' => 'inactive']);
        $this->assertFalse($this->service->allowed($admin->fresh(), 'email', (string) $email->id));
    }

    public function test_observers_and_reconciliation_make_one_outbox_row_per_event_device_owner(): void
    {
        $admin = $this->user('admin'); $device = $this->device($admin); $client = $this->user();
        $email = $this->email([$admin]);
        $support = $this->ticket($client)->messages()->create(['author_id' => $client->id, 'request_key' => (string) Str::uuid(), 'body' => 'Test', 'kind' => 'opened']);
        $shoot = $this->activity(Shoot::factory()->create());
        $account = UserActivityLog::record($client, 'email_bounced', 'Test');
        $expected = ['email' => $email->id, 'support' => $support->id, 'shoot_activity' => $shoot->id, 'account_activity' => $account->id];
        foreach ($expected as $kind => $id) $this->assertSame(1, MobileNotificationDelivery::where('device_id', $device->id)->where('kind', $kind)->where('source_id', $id)->count());
        $this->service->reconcile(); $this->service->reconcile();
        foreach ($expected as $kind => $id) $this->assertSame(1, MobileNotificationDelivery::where('device_id', $device->id)->where('kind', $kind)->where('source_id', $id)->count());
        $payload = app(MobileNotificationTransport::class)->payload(MobileNotificationDelivery::where('kind', 'shoot_activity')->firstOrFail());
        $this->assertSame('notifications', $payload['repro']['destination']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($payload));
        $this->assertArrayNotHasKey('badge', $payload['aps']);
    }

    public function test_email_reconciliation_waits_for_thread_unread_recipient_and_acceptance(): void
    {
        $admin = $this->user('admin'); $device = $this->device($admin);
        $email = $this->email([], ['status' => 'QUEUED']);
        $this->assertSame(0, MobileNotificationDelivery::where('kind', 'email')->count());
        $email->update(['status' => 'DELIVERED']);
        $this->assertSame(0, MobileNotificationDelivery::where('kind', 'email')->count());
        $email->thread->update(['unread_for_user_ids_json' => [$admin->id]]);
        $this->service->reconcile();
        $this->assertSame(1, MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'email')->where('source_id', $email->id)->count());
    }

    public function test_routine_client_delivery_waits_until_local_morning_without_provider_attempt(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->startOfDay()->setTime(5, 0));
        $client = $this->user(); $client->update(['timezone' => 'America/New_York']); $device = $this->device($client);
        $event = $this->activity(Shoot::factory()->create(['client_id' => $client->id]), 'shoot_delivered');
        $delivery = MobileNotificationDelivery::where('device_id', $device->id)->where('source_id', $event->id)->where('kind', 'shoot_activity')->firstOrFail();
        $this->assertSame('09:00', $delivery->not_before->setTimezone('America/New_York')->format('H:i'));
        $this->assertSame('10:00', $delivery->expires_at->setTimezone('America/New_York')->format('H:i'));
        $this->runDelivery($delivery); $this->assertSame('queued', $delivery->fresh()->status); Http::assertNothingSent();
        $this->travelTo($delivery->not_before);
        // Read in another client before morning suppresses the queued alert.
        $this->receipt($client, ['sa-'.$event->id]); $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status); Http::assertNothingSent();
    }

    public function test_operational_schedule_and_direct_support_are_immediate_even_early_morning(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->startOfDay()->setTime(5, 0));
        $client = $this->user(); $client->update(['timezone' => 'America/New_York']); $device = $this->device($client);
        $event = $this->activity(Shoot::factory()->create(['client_id' => $client->id]), 'shoot_scheduled');
        $reply = $this->ticket($client)->messages()->create(['author_id' => $this->user('admin')->id, 'request_key' => (string) Str::uuid(), 'body' => 'Test', 'kind' => 'reply']);
        foreach ([['shoot_activity', $event->id], ['support', $reply->id]] as [$kind, $id]) {
            $delivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', $kind)->where('source_id', $id)->firstOrFail();
            $this->assertNull($delivery->not_before);
            Queue::assertPushed(SendMobileNotification::class, fn ($job) => $job->deliveryID === $delivery->id);
        }
    }

    public function test_deferred_delivery_rechecks_changed_timezone_then_attempts_once_at_new_morning(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->startOfDay()->setTime(5, 0));
        $client = $this->user(); $client->update(['timezone' => 'America/New_York']); $device = $this->device($client);
        $event = $this->activity(Shoot::factory()->create(['client_id' => $client->id]), 'shoot_completed');
        $delivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'shoot_activity')->where('source_id', $event->id)->firstOrFail();
        $this->travelTo($delivery->not_before);
        $client->update(['timezone' => 'America/Los_Angeles']);
        $this->runDelivery($delivery);
        $delivery->refresh();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame('09:00', $delivery->not_before->setTimezone('America/Los_Angeles')->format('H:i'));
        Http::assertNothingSent();
        $this->travelTo($delivery->not_before);
        $this->mock(MobileNotificationTransport::class)->shouldReceive('send')->once()
            ->andReturn(['status' => 'accepted', 'provider_status' => 200, 'revoke' => false]);
        $this->runDelivery($delivery); $this->runDelivery($delivery);
        $this->service->reconcile(); $this->runDelivery($delivery);
        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame(1, MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'shoot_activity')->where('source_id', $event->id)->count());
    }

    public function test_pending_support_permission_removal_and_email_conversion_suppress_delivery(): void
    {
        $admin = $this->user('admin'); $client = $this->user(); $device = $this->device($admin);
        $ticket = $this->ticket($client);
        $support = $ticket->messages()->create(['author_id' => $client->id, 'request_key' => (string) Str::uuid(), 'body' => 'Test', 'kind' => 'opened']);
        $supportDelivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'support')->where('source_id', $support->id)->firstOrFail();
        $admin->update(['permission_overrides' => ['allow' => [], 'deny' => ['support-view']]]);
        $this->runDelivery($supportDelivery);
        $this->assertSame('expired', $supportDelivery->fresh()->status);
        $email = $this->email([$admin]);
        $emailDelivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'email')->where('source_id', $email->id)->firstOrFail();
        $support->update(['source_message_id' => $email->id]);
        $this->runDelivery($emailDelivery);
        $this->assertSame('expired', $emailDelivery->fresh()->status); Http::assertNothingSent();
    }

    public function test_morning_change_after_a_provider_attempt_cannot_extend_the_alert_deadline(): void
    {
        $this->travelTo(now()->setTimezone('America/New_York')->startOfDay()->setTime(9, 0));
        $client = $this->user(); $client->update(['timezone' => 'America/New_York']); $device = $this->device($client);
        $event = $this->activity(Shoot::factory()->create(['client_id' => $client->id]), 'shoot_delivered');
        $delivery = MobileNotificationDelivery::where('device_id', $device->id)->where('kind', 'shoot_activity')->where('source_id', $event->id)->firstOrFail();
        $expiry = $delivery->expires_at->timestamp;
        $this->mock(MobileNotificationTransport::class)->shouldReceive('send')->once()
            ->andReturn(['status' => 'retryable', 'provider_status' => 503, 'retry_after_seconds' => 900, 'revoke' => false]);
        $this->runDelivery($delivery);
        $client->update(['timezone' => 'America/Los_Angeles']);
        $this->travelTo($delivery->fresh()->not_before);
        $this->runDelivery($delivery);
        $this->assertSame('expired', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame($expiry, $delivery->fresh()->expires_at->timestamp);
    }
}
