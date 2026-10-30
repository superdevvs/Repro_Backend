<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceLinePolicy;
use App\Services\Messaging\UnreadCountService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleAwareMessagingBadgeCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
    }

    private function ticket(User $requester): SupportTicket
    {
        return app(SupportTicketService::class)->create($requester, [
            'request_key' => (string) Str::uuid(), 'subject' => 'Download issue',
            'body' => 'The downloaded file did not open.', 'category' => 'delivery',
        ]);
    }

    private function reply(User $author, SupportTicket $ticket, bool $internal = false): int
    {
        app(SupportTicketService::class)->reply($author, $ticket->id, [
            'request_key' => (string) Str::uuid(), 'body' => 'Please try the download again.', 'internal' => $internal,
        ]);

        return (int) $ticket->messages()->max('id');
    }

    public function test_call_attention_badges_obey_line_assignment_direction_and_current_permission(): void
    {
        $staff = User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => [
            'allow' => ['voice-calls-view', 'voice-calls-operate'], 'deny' => [],
        ]]);
        $other = User::factory()->create(['role' => 'admin']);
        foreach (['+12025550100' => $staff->id, '+12025550101' => $other->id] as $number => $recipient) {
            VoiceLinePolicy::create(['phone_number' => $number, 'mode' => 'staff_voicemail',
                'recipient_ids' => [$recipient], 'recipient_roles' => [], 'timezone' => 'UTC', 'weekly_hours' => []]);
            VoiceCall::create(['direction' => 'INBOUND', 'from_phone' => '+12025550200', 'to_phone' => $number,
                'status' => 'completed', 'disposition' => 'callback_needed']);
            VoiceCall::create(['direction' => 'OUTBOUND', 'from_phone' => $number, 'to_phone' => '+12025550200',
                'status' => 'completed', 'needs_follow_up' => true]);
        }
        // An old permitted call and a completed ordinary call are not actionable.
        VoiceCall::create(['direction' => 'INBOUND', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100',
            'status' => 'completed', 'disposition' => 'callback_needed'])->forceFill(['created_at' => now()->subDays(31)])->save();
        VoiceCall::create(['direction' => 'INBOUND', 'from_phone' => '+12025550200', 'to_phone' => '+12025550100',
            'status' => 'completed']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('call', 2)->assertJsonPath('total', 2);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.unread_counts.call', 2);
        $this->actingAs($other, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('call', 2);
        $super = User::factory()->create(['role' => 'editor', 'secondary_roles' => ['superadmin']]);
        $this->actingAs($super, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('call', 4);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.unread_counts.call', 4);

        $staff->update(['permission_overrides' => ['allow' => ['voice-calls-operate'], 'deny' => ['voice-calls-view']]]);
        $this->actingAs($staff->fresh(), 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('call', 0);
        $this->assertSame(0, app(UnreadCountService::class)->forUser($staff->id)['call']);
    }

    public function test_support_only_customer_sees_own_public_unread_ticket_not_internal_notes_or_other_customers(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $own = $this->ticket($client);
        $first = $this->reply($admin, $own);
        $second = $this->reply($admin, $own);
        $this->reply($admin, $own, true);
        $this->reply($admin, $this->ticket($other));
        $this->reply($admin, $this->ticket($client), true);
        $this->actingAs($client, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()
            ->assertExactJson(['email' => 0, 'sms' => 0, 'call' => 0, 'support' => 1, 'total' => 1]);
        $this->postJson('/api/notifications/read-state', ['ids' => ['support-'.$first]])->assertOk();
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $this->postJson('/api/notifications/read-state', ['ids' => ['support-'.$second]])->assertOk();
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0)->assertJsonPath('total', 0);
        $this->assertSame(0, app(UnreadCountService::class)->forUser($client->id)['support']);
        $this->actingAs($other, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $this->getJson('/api/support/tickets/'.$own->id)->assertNotFound();
    }

    public function test_support_watermark_and_permission_denial_are_rechecked_without_caching(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($client);
        $this->reply($admin, $ticket);
        $this->actingAs($client, 'sanctum')->postJson('/api/notifications/read-state', ['ids' => [], 'lastSeenAt' => now()->getTimestampMs()])->assertOk();
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0);
        $this->travel(1)->seconds();
        $this->reply($admin, $ticket);
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $client->update(['permission_overrides' => ['allow' => [], 'deny' => ['support-view']]]);
        $this->actingAs($client->fresh(), 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()
            ->assertJsonPath('support', 0)->assertJsonPath('total', 0);
        $this->getJson('/api/support/tickets/'.$ticket->id)->assertForbidden();
    }

    public function test_support_manager_visibility_does_not_survive_manage_permission_revocation(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $manager = User::factory()->create(['role' => 'admin']);
        $anotherManager = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($client);
        $this->reply($anotherManager, $ticket, true);
        $this->actingAs($manager, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $manager->update(['permission_overrides' => ['allow' => [], 'deny' => ['support-manage']]]);
        $this->actingAs($manager->fresh(), 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0);
        $this->getJson('/api/support/tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_impersonator_receipts_never_clear_the_real_customers_support_badge(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $message = $this->reply($admin, $this->ticket($client));
        $this->actingAs($admin, 'sanctum')->withHeader('X-Impersonate-User-Id', (string) $client->id)
            ->postJson('/api/notifications/read-state', ['ids' => ['support-'.$message]])->assertOk();
        // Each real request authenticates the original bearer again; actingAs
        // needs resetting because this middleware swaps the in-memory guard.
        $this->actingAs($admin, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0);
        $this->flushHeaders();
        $this->actingAs($client, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
    }

    public function test_support_badges_count_beyond_the_notification_feed_page(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 51; $i++) {
            $this->reply($admin, $this->ticket($client));
        }
        $this->actingAs($client, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()
            ->assertJsonPath('support', 51)->assertJsonPath('total', 51);
    }

    public function test_support_badge_retires_history_at_the_receipt_age_boundary_without_hiding_new_replies(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $cutoff = now()->subDays(90);
        $expiredTicket = $this->ticket($client);
        $expired = $this->reply($admin, $expiredTicket);
        SupportTicketMessage::findOrFail($expired)->update(['created_at' => $cutoff->copy()->subSecond()]);
        $boundary = $this->reply($admin, $this->ticket($client));
        SupportTicketMessage::findOrFail($boundary)->update(['created_at' => $cutoff]);
        $recent = $this->reply($admin, $this->ticket($client));
        SupportTicketMessage::findOrFail($recent)->update(['created_at' => $cutoff->copy()->addSecond()]);
        DB::table('notification_read_states')->insert([
            'user_id' => $client->id, 'context' => 'client', 'last_seen_at' => null,
            'read_ids' => json_encode(['support-'.$expired => $cutoff->copy()->subSecond()->getTimestampMs()]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($client, 'sanctum')->postJson('/api/notifications/read-state', ['ids' => []])->assertOk()
            ->assertJsonMissingPath('data.readIds.support-'.$expired);
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        // Retiring badge history does not remove ticket history or block a fresh reply.
        $this->getJson('/api/support/tickets/'.$expiredTicket->id)->assertOk()->assertJsonCount(2, 'messages');
        $this->reply($admin, $expiredTicket);
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 2);
    }

    public function test_mixed_channel_receipt_capacity_cannot_resurrect_a_recently_read_support_event(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $oldRead = $this->reply($admin, $this->ticket($client));
        SupportTicketMessage::findOrFail($oldRead)->update(['created_at' => now()->subDays(20)]);
        $recentUnread = $this->reply($admin, $this->ticket($client));
        SupportTicketMessage::findOrFail($recentUnread)->update(['created_at' => now()->subHour()]);
        $receipts = ['support-'.$oldRead => now()->subDays(10)->getTimestampMs()];
        for ($i = 0; $i < 9999; $i++) {
            $receipts['email-'.$i] = now()->subDay()->getTimestampMs();
        }
        DB::table('notification_read_states')->insert([
            'user_id' => $client->id, 'context' => 'client', 'last_seen_at' => null,
            'read_ids' => json_encode($receipts), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($client, 'sanctum')->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $result = $this->postJson('/api/notifications/read-state', ['ids' => ['email-new']])->assertOk()
            ->assertJsonMissingPath('data.readIds.support-'.$oldRead);
        $this->assertCount(10000, $result->json('data.readIds'));
        $this->assertNull($result->json('data.lastSeenAt')); // No implicit mark-all-read.
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 1);
        $this->postJson('/api/notifications/read-state', ['ids' => ['support-'.$recentUnread]])->assertOk();
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0);
        // Expiry can shrink the map below capacity; the rolling age floor then
        // remains beyond the retired events, so they still cannot reappear.
        $this->travel(89)->days();
        $this->travel(1)->seconds();
        $result = $this->postJson('/api/notifications/read-state', ['ids' => []])->assertOk();
        $this->assertCount(2, $result->json('data.readIds'));
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('support', 0);
    }
}
