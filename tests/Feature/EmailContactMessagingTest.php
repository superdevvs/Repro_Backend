<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailContactMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_compose_requires_shoot_context(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);

        Sanctum::actingAs($client);

        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Can someone check this?',
        ]);

        $response->assertStatus(422);
    }

    public function test_client_cannot_compose_against_another_clients_shoot(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $otherClient = User::factory()->create([
            'role' => 'client',
            'email' => 'other-client@example.com',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $otherClient->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($client);

        $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Can someone check this?',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertStatus(422)->assertJsonFragment([
            'message' => 'The selected shoot is not available for this contact message.',
        ]);
    }

    public function test_non_admin_compose_links_message_and_marks_expected_staff_unread(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($client);

        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Can someone check this?',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ]);

        $response->assertOk();

        $message = $response->json();
        $this->assertSame($shoot->id, $message['related_shoot_id']);
        $this->assertSame('new_shoot', $message['related_shoot_context_type']);
        $this->assertSame($client->id, $message['related_account_id']);

        $storedMessage = \App\Models\Message::query()->with('thread')->firstOrFail();
        $unreadUserIds = $storedMessage->thread?->unread_for_user_ids_json ?? [];

        $this->assertContains($admin->id, $unreadUserIds);
        $this->assertContains($editingManager->id, $unreadUserIds);
        $this->assertContains($salesRep->id, $unreadUserIds);
        $this->assertNotContains($client->id, $unreadUserIds);
    }

    public function test_linked_contact_message_visibility_matches_role_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $otherSalesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'status' => Shoot::STATUS_DELIVERED,
        ]);

        Sanctum::actingAs($client);
        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Past shoot question',
            'body_text' => 'Need an update on edits.',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'previous_shoot',
        ]);
        $response->assertOk();

        $messageId = (int) $response->json('id');

        Sanctum::actingAs($admin);
        $this->getJson("/api/messaging/email/messages/{$messageId}")->assertOk();

        Sanctum::actingAs($editingManager);
        $this->getJson("/api/messaging/email/messages/{$messageId}")->assertOk();
        $this->getJson('/api/messaging/email/messages')
            ->assertOk()
            ->assertJsonFragment(['id' => $messageId]);

        Sanctum::actingAs($salesRep);
        $this->getJson("/api/messaging/email/messages/{$messageId}")->assertOk();
        $this->getJson('/api/messaging/email/messages')
            ->assertOk()
            ->assertJsonFragment(['id' => $messageId]);

        Sanctum::actingAs($otherSalesRep);
        $this->getJson("/api/messaging/email/messages/{$messageId}")->assertForbidden();
    }

    public function test_sales_rep_cannot_compose_against_shoot_outside_their_scope(): void
    {
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $otherSalesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $otherSalesRep->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($salesRep);

        $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Can someone check this?',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertStatus(422)->assertJsonFragment([
            'message' => 'The selected shoot is not available for this contact message.',
        ]);
    }

    public function test_linked_contact_messages_for_same_shoot_reuse_the_same_thread(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $salesRep->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($client);

        $firstResponse = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'First question',
            'body_text' => 'Question one',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertOk();

        $secondResponse = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Second question',
            'body_text' => 'Question two',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertOk();

        $firstMessage = Message::query()->findOrFail($firstResponse->json('id'));
        $secondMessage = Message::query()->findOrFail($secondResponse->json('id'));

        $this->assertSame($firstMessage->thread_id, $secondMessage->thread_id);

        $thread = MessageThread::query()->findOrFail($firstMessage->thread_id);
        $this->assertSame($shoot->id, $thread->related_shoot_id);
    }

    public function test_linked_contact_messages_for_different_shoots_split_into_separate_threads(): void
    {
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

        Sanctum::actingAs($client);

        $firstResponse = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Shoot A question',
            'body_text' => 'Need help on shoot A',
            'related_shoot_id' => $shootA->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertOk();

        $secondResponse = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Shoot B question',
            'body_text' => 'Need help on shoot B',
            'related_shoot_id' => $shootB->id,
            'related_shoot_context_type' => 'previous_shoot',
        ])->assertOk();

        $firstMessage = Message::query()->findOrFail($firstResponse->json('id'));
        $secondMessage = Message::query()->findOrFail($secondResponse->json('id'));

        $this->assertNotSame($firstMessage->thread_id, $secondMessage->thread_id);
        $this->assertSame($shootA->id, MessageThread::query()->findOrFail($firstMessage->thread_id)->related_shoot_id);
        $this->assertSame($shootB->id, MessageThread::query()->findOrFail($secondMessage->thread_id)->related_shoot_id);
    }

    public function test_sales_reps_only_see_thread_metadata_for_their_own_shoots(): void
    {
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

        Sanctum::actingAs($client);
        $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Shoot A contact',
            'body_text' => 'Thread for shoot A',
            'related_shoot_id' => $shootA->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertOk();
        $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Shoot B contact',
            'body_text' => 'Thread for shoot B',
            'related_shoot_id' => $shootB->id,
            'related_shoot_context_type' => 'previous_shoot',
        ])->assertOk();

        Sanctum::actingAs($salesRepA);
        $repAThreads = $this->getJson('/api/messaging/email/threads')->assertOk()->json('data');
        $this->assertCount(1, $repAThreads);
        $this->assertSame($shootA->id, $repAThreads[0]['related_shoot_id']);
        $this->assertSame('Thread for shoot A', $repAThreads[0]['last_snippet']);

        Sanctum::actingAs($salesRepB);
        $repBThreads = $this->getJson('/api/messaging/email/threads')->assertOk()->json('data');
        $this->assertCount(1, $repBThreads);
        $this->assertSame($shootB->id, $repBThreads[0]['related_shoot_id']);
        $this->assertSame('Thread for shoot B', $repBThreads[0]['last_snippet']);

        Sanctum::actingAs($editingManager);
        $managerThreads = $this->getJson('/api/messaging/email/threads')->assertOk()->json('data');
        $this->assertCount(2, $managerThreads);
    }

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

    public function test_legacy_unlinked_internal_messages_keep_existing_non_admin_visibility_rules(): void
    {
        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client@example.com',
        ]);

        $message = app(MessagingService::class)->storeInternalEmail([
            'from' => $client->email,
            'to' => config('mail.contact_address', 'contact@reprophotos.com'),
            'body_text' => 'Legacy message',
            'user_id' => $client->id,
            'sender_user_id' => $client->id,
            'sender_account_id' => $client->id,
            'sender_role' => $client->role,
            'sender_display_name' => $client->name ?? $client->email,
            'contact_email' => $client->email,
            'contact_name' => $client->name ?? $client->email,
            'contact_type' => $client->role,
            'contact_user_id' => $client->id,
            'contact_account_id' => $client->id,
        ], 'INBOUND');

        Sanctum::actingAs($editingManager);
        $this->getJson("/api/messaging/email/messages/{$message->id}")->assertForbidden();
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
