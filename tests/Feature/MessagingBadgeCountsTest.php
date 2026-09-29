<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageThread;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingBadgeCountsTest extends TestCase
{
    use RefreshDatabase;

    private function makeContact(string $suffix): Contact
    {
        return Contact::query()->create([
            'name' => 'Badge Contact '.$suffix,
            'email' => "badge-{$suffix}@example.com",
            'phone' => '+1202555'.str_pad((string) random_int(1000, 9999), 4, '0'),
            'type' => 'client',
        ]);
    }

    public function test_badge_counts_endpoint_is_user_scoped_and_permission_aware(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $other = User::factory()->create(['role' => 'admin']);

        MessageThread::query()->create([
            'contact_id' => $this->makeContact('email-admin')->id,
            'channel' => 'EMAIL',
            'status' => 'OPEN',
            'unread_for_user_ids_json' => [$admin->id],
            'last_message_at' => now(),
        ]);
        MessageThread::query()->create([
            'contact_id' => $this->makeContact('sms-shared')->id,
            'channel' => 'SMS',
            'status' => 'OPEN',
            'unread_for_user_ids_json' => [$admin->id, $other->id],
            'last_message_at' => now(),
        ]);
        MessageThread::query()->create([
            'contact_id' => $this->makeContact('email-other')->id,
            'channel' => 'EMAIL',
            'status' => 'OPEN',
            'unread_for_user_ids_json' => [$other->id],
            'last_message_at' => now(),
        ]);

        VoiceCall::query()->create([
            'direction' => 'INBOUND',
            'status' => 'completed',
            'disposition' => 'handoff_to_staff',
            'needs_follow_up' => true,
            'from_phone' => '+15551212',
            'to_phone' => '+15550000',
            'ended_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/messaging/badge-counts')
            ->assertOk()
            ->assertJson([
                'email' => 1,
                'sms' => 1,
                'call' => 1,
                'total' => 3,
            ]);

        $this->actingAs($other)
            ->getJson('/api/messaging/badge-counts')
            ->assertOk()
            ->assertJsonPath('email', 1)
            ->assertJsonPath('sms', 1)
            ->assertJsonPath('call', 1);
    }

    public function test_notifications_feed_no_longer_hard_caps_at_fifty(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/API/DashboardController.php'));
        $this->assertStringContainsString('->take(500)', $source);
        $this->assertStringNotContainsString('->take(50)', $source);
        $this->assertStringContainsString('unread_counts', $source);
    }
}
