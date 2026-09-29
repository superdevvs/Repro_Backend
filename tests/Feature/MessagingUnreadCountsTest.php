<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Messaging\UnreadCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingUnreadCountsTest extends TestCase
{
    use RefreshDatabase;

    private function makeContact(string $suffix = 'a'): Contact
    {
        return Contact::query()->create([
            'name' => 'Unread Contact '.$suffix,
            'email' => "unread-{$suffix}@example.com",
            'phone' => '+12025550'.str_pad((string) random_int(100, 999), 3, '0'),
            'type' => 'client',
        ]);
    }

    private function makeUnreadThread(string $channel, array $userIds, ?Contact $contact = null): MessageThread
    {
        return MessageThread::query()->create([
            'channel' => $channel,
            'contact_id' => ($contact ?? $this->makeContact($channel.uniqid()))->id,
            'last_direction' => 'INBOUND',
            'last_message_at' => now(),
            'unread_for_user_ids_json' => $userIds,
        ]);
    }

    public function test_unread_count_service_scopes_email_and_sms_to_the_viewer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);

        $this->makeUnreadThread('EMAIL', [$admin->id]);
        $this->makeUnreadThread('SMS', [$admin->id, $other->id]);
        $this->makeUnreadThread('SMS', [$other->id]);
        $this->makeUnreadThread('EMAIL', []);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'handoff_to_staff',
            'from_phone' => '+12025550112',
            'to_phone' => '+12025550100',
            'answered_at' => now()->subHours(2),
            'ended_at' => now()->subHours(2),
            'needs_follow_up' => true,
        ]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'callback_needed',
            'from_phone' => '+12025550116',
            'to_phone' => '+12025550100',
            'answered_at' => now()->subHours(3),
            'ended_at' => now()->subHours(3),
        ]);
        // Resolved call must not badge.
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'caller_hangup',
            'from_phone' => '+12025550113',
            'to_phone' => '+12025550100',
            'answered_at' => now()->subHours(4),
            'ended_at' => now()->subHours(4),
            'needs_follow_up' => false,
            'summary' => 'Resolved',
        ]);

        $counts = app(UnreadCountService::class)->forUser((int) $admin->id);

        $this->assertSame(1, $counts['email']);
        $this->assertSame(1, $counts['sms']);
        $this->assertSame(2, $counts['call']);
        $this->assertSame(4, $counts['total']);
    }

    public function test_messaging_overview_exposes_per_channel_unread_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->makeUnreadThread('EMAIL', [$admin->id]);
        $this->makeUnreadThread('SMS', [$admin->id]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'handoff_to_staff',
            'from_phone' => '+12025550114',
            'to_phone' => '+12025550100',
            'answered_at' => now()->subMinute(),
            'ended_at' => now(),
            'needs_follow_up' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/messaging/overview')
            ->assertOk()
            ->assertJsonPath('unread_email_count', 1)
            ->assertJsonPath('unread_sms_count', 1)
            ->assertJsonPath('unread_call_count', 1)
            ->assertJsonPath('unread_total', 3)
            ->assertJsonPath('unread_counts.email', 1)
            ->assertJsonPath('unread_counts.sms', 1)
            ->assertJsonPath('unread_counts.call', 1)
            ->assertJsonPath('unread_counts.total', 3);

        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/messaging/overview')
            ->assertOk()
            ->assertJsonPath('unread_email_count', 0)
            ->assertJsonPath('unread_sms_count', 0)
            ->assertJsonPath('unread_call_count', 1);
    }

    public function test_notifications_endpoint_includes_unread_counts_and_keeps_more_than_fifty_feed_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        for ($i = 0; $i < 55; $i++) {
            $this->makeUnreadThread('EMAIL', [$admin->id]);
        }

        for ($i = 0; $i < 60; $i++) {
            $shoot = Shoot::factory()->create([
                'client_id' => $admin->id,
                'status' => Shoot::STATUS_SCHEDULED,
                'workflow_status' => Shoot::STATUS_SCHEDULED,
            ]);
            ShootActivityLog::query()->create([
                'user_id' => $admin->id,
                'shoot_id' => $shoot->id,
                'action' => 'shoot_created',
                'description' => 'Seed activity '.$i,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/notifications')->assertOk();
        $feed = $response->json('data.activity_log');

        $this->assertIsArray($feed);
        $this->assertGreaterThan(50, count($feed), 'Notification feed must not hard-cap at 50 items');
        $response->assertJsonPath('data.unread_counts.email', 55)
            ->assertJsonPath('data.unread_counts.sms', 0)
            ->assertJsonStructure([
                'data' => [
                    'activity_log',
                    'user_role',
                    'unread_counts' => ['email', 'sms', 'call', 'total'],
                ],
            ]);
    }

    public function test_client_notifications_omit_call_unread_counts(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'missed',
            'from_phone' => '+12025550115',
            'to_phone' => '+12025550100',
            'answered_at' => null,
            'ended_at' => now(),
        ]);

        Sanctum::actingAs($client);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_counts.call', 0)
            ->assertJsonPath('data.unread_counts.total', 0);
    }

    public function test_messaging_badge_counts_endpoint_is_user_scoped(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);

        $this->makeUnreadThread('EMAIL', [$admin->id]);
        $this->makeUnreadThread('SMS', [$admin->id]);
        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'callback_needed',
            'from_phone' => '+12025550117',
            'to_phone' => '+12025550100',
            'answered_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/messaging/badge-counts')
            ->assertOk()
            ->assertJson([
                'email' => 1,
                'sms' => 1,
                'call' => 1,
                'total' => 3,
            ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/messaging/badge-counts')
            ->assertOk()
            ->assertJsonPath('email', 0)
            ->assertJsonPath('sms', 0)
            ->assertJsonPath('call', 1);
    }
}
