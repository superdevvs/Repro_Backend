<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmailContactMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_email_threading_stays_contact_level_even_with_related_shoots(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $shootA = Shoot::factory()->create([
            'client_id' => $client->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);
        $shootB = Shoot::factory()->create([
            'client_id' => $client->id,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'status' => Shoot::STATUS_DELIVERED,
        ]);

        $service = app(MessagingService::class);

        $firstMessage = $service->scheduleEmail([
            'to' => $client->email,
            'subject' => 'Admin outbound A',
            'body_text' => 'Admin outbound message A',
            'related_shoot_id' => $shootA->id,
            'related_account_id' => $client->id,
            'contact_email' => $client->email,
            'contact_name' => $client->name,
            'contact_type' => 'client',
            'contact_user_id' => $client->id,
            'contact_account_id' => $client->id,
            'user_id' => $admin->id,
            'sender_user_id' => $admin->id,
            'sender_role' => $admin->role,
            'sender_display_name' => $admin->name,
        ], Carbon::now()->addHour());

        $secondMessage = $service->scheduleEmail([
            'to' => $client->email,
            'subject' => 'Admin outbound B',
            'body_text' => 'Admin outbound message B',
            'related_shoot_id' => $shootB->id,
            'related_account_id' => $client->id,
            'contact_email' => $client->email,
            'contact_name' => $client->name,
            'contact_type' => 'client',
            'contact_user_id' => $client->id,
            'contact_account_id' => $client->id,
            'user_id' => $admin->id,
            'sender_user_id' => $admin->id,
            'sender_role' => $admin->role,
            'sender_display_name' => $admin->name,
        ], Carbon::now()->addHours(2));

        $this->assertSame($firstMessage->thread_id, $secondMessage->thread_id);
        $this->assertNull(MessageThread::query()->findOrFail($firstMessage->thread_id)->related_shoot_id);
    }

    public function test_backfill_splits_existing_mixed_contact_thread_by_shoot_and_rebuilds_thread_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $salesRepA = User::factory()->create(['role' => 'salesRep']);
        $salesRepB = User::factory()->create(['role' => 'salesRep']);
        $shootA = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRepA->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);
        $shootB = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRepB->id,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'status' => Shoot::STATUS_DELIVERED,
        ]);

        $contact = Contact::query()->create([
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->email,
            'type' => 'client',
            'user_id' => $client->id,
            'account_id' => $client->id,
        ]);

        $legacyThread = MessageThread::query()->create([
            'channel' => 'EMAIL',
            'contact_id' => $contact->id,
            'related_shoot_id' => null,
            'last_message_at' => Carbon::parse('2026-04-07 11:00:00'),
            'last_direction' => 'INBOUND',
            'last_snippet' => 'Legacy mixed thread',
            'unread_for_user_ids_json' => [$admin->id, $editingManager->id, $salesRepA->id, $salesRepB->id],
        ]);

        $messageA = Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'INBOUND',
            'provider' => 'INTERNAL',
            'from_address' => $client->email,
            'to_address' => config('mail.contact_address', 'contact@reprophotos.com'),
            'subject' => 'Shoot A contact',
            'body_text' => 'Only shoot A details',
            'status' => 'SENT',
            'send_source' => 'MANUAL',
            'created_by' => $client->id,
            'sender_user_id' => $client->id,
            'sender_account_id' => $client->id,
            'sender_role' => $client->role,
            'sender_display_name' => $client->name,
            'related_shoot_id' => $shootA->id,
            'related_account_id' => $client->id,
            'thread_id' => $legacyThread->id,
            'created_at' => Carbon::parse('2026-04-07 10:00:00'),
            'updated_at' => Carbon::parse('2026-04-07 10:00:00'),
        ]);

        $messageB = Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'INBOUND',
            'provider' => 'INTERNAL',
            'from_address' => $client->email,
            'to_address' => config('mail.contact_address', 'contact@reprophotos.com'),
            'subject' => 'Shoot B contact',
            'body_text' => 'Only shoot B details',
            'status' => 'SENT',
            'send_source' => 'MANUAL',
            'created_by' => $client->id,
            'sender_user_id' => $client->id,
            'sender_account_id' => $client->id,
            'sender_role' => $client->role,
            'sender_display_name' => $client->name,
            'related_shoot_id' => $shootB->id,
            'related_account_id' => $client->id,
            'thread_id' => $legacyThread->id,
            'created_at' => Carbon::parse('2026-04-07 11:00:00'),
            'updated_at' => Carbon::parse('2026-04-07 11:00:00'),
        ]);

        app(MessagingService::class)->backfillLinkedInternalContactThreadsByShoot();

        $messageA->refresh();
        $messageB->refresh();

        $this->assertNotSame($messageA->thread_id, $messageB->thread_id);
        $this->assertNull(MessageThread::query()->find($legacyThread->id));

        $threadA = MessageThread::query()->findOrFail($messageA->thread_id);
        $threadB = MessageThread::query()->findOrFail($messageB->thread_id);

        $this->assertSame($shootA->id, $threadA->related_shoot_id);
        $this->assertSame($shootB->id, $threadB->related_shoot_id);
        $this->assertSame('Only shoot A details', $threadA->last_snippet);
        $this->assertSame('Only shoot B details', $threadB->last_snippet);
        $this->assertContains($admin->id, $threadA->unread_for_user_ids_json);
        $this->assertContains($editingManager->id, $threadA->unread_for_user_ids_json);
        $this->assertContains($salesRepA->id, $threadA->unread_for_user_ids_json);
        $this->assertNotContains($salesRepB->id, $threadA->unread_for_user_ids_json);
        $this->assertContains($salesRepB->id, $threadB->unread_for_user_ids_json);
        $this->assertNotContains($salesRepA->id, $threadB->unread_for_user_ids_json);
    }
}
