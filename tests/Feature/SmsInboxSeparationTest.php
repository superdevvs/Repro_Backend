<?php

namespace Tests\Feature;

use App\Events\SmsMessageReceived;
use App\Events\SmsMessageSent;
use App\Events\SmsThreadUpdated;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsGroup;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use App\Services\Messaging\SmsInboxPolicy;
use App\Services\Messaging\UnreadCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SmsInboxSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const PERSON = '+12025550112';

    private const BUSINESS = '+12025550100';

    public function test_system_only_sms_and_reactions_are_hidden_until_a_real_reply(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        Sanctum::actingAs($manager);
        $thread = $this->thread();
        $reminder = $this->message($thread, 'Reminder tomorrow');
        $this->message($thread, 'Liked "Reminder tomorrow"', 'INBOUND');

        $this->getJson('/api/messaging/sms/threads')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/sms/threads?scope=system')->assertForbidden();
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertForbidden();
        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/mark-read')->assertForbidden();
        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/resume-ai')->assertForbidden();
        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/messages', ['body' => 'Hi'])->assertForbidden();

        $reply = $this->message($thread, 'Running late', 'INBOUND');
        $this->getJson('/api/messaging/sms/threads')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inboxScope', 'inbox')->assertJsonPath('data.0.needsReply', true);
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk()->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.0.id', (string) $reminder->id)->assertJsonPath('messages.2.id', (string) $reply->id);
    }

    public function test_admin_can_view_system_sms_but_default_inbox_is_clean(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $thread = $this->thread();
        $this->message($thread, 'Shoot reminder');
        $this->getJson('/api/messaging/sms/threads')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/sms/threads?scope=system')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inboxScope', 'system')->assertJsonPath('data.0.needsReply', false);
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk();
        $this->getJson('/api/messaging/sms/threads?scope=anything')->assertStatus(422);
    }

    public function test_manual_notifications_are_system_but_a_manual_conversation_promotes(): void
    {
        $actor = User::factory()->create(['role' => 'editing_manager']);
        $thread = $this->thread();
        $template = \App\Models\MessageTemplate::create(['channel' => 'SMS', 'name' => 'Reminder', 'body_text' => 'Hi', 'is_active' => true]);
        $this->message($thread, 'One click reminder', 'OUTBOUND', ['send_source' => 'MANUAL', 'created_by' => $actor->id, 'template_id' => $template->id]);
        $this->assertFalse($thread->fresh()->sms_inbox_active);
        $this->message($thread, 'Can you check the photos?', 'OUTBOUND', ['send_source' => 'MANUAL', 'created_by' => $actor->id]);
        $this->assertTrue($thread->fresh()->sms_inbox_active);
        $this->assertFalse($thread->fresh()->sms_needs_reply);
    }

    public function test_automation_and_reactions_do_not_clear_a_human_question_or_create_unread(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        Sanctum::actingAs($manager);
        $thread = $this->thread();
        $this->message($thread, 'Where is my delivery?', 'INBOUND');
        $thread->update(['unread_for_user_ids_json' => [$manager->id]]);
        $this->message($thread, 'Payment reminder');
        $this->message($thread, 'Liked "Payment reminder"', 'INBOUND');
        $this->getJson('/api/messaging/sms/threads?filter=unanswered')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, app(UnreadCountService::class)->forUser($manager->id, false)['sms']);
        $this->message($thread, 'We will deliver today.', 'OUTBOUND', ['send_source' => 'MANUAL', 'created_by' => $manager->id]);
        $this->getJson('/api/messaging/sms/threads?filter=unanswered')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_broadcasts_and_badges_do_not_leak_system_only_sms(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $thread = $this->thread();
        $system = $this->message($thread, 'Reminder');
        $reaction = $this->message($thread, 'Liked "Reminder"', 'INBOUND');
        $thread->update(['unread_for_user_ids_json' => [$manager->id]]);
        $channels = fn ($event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertContains('private-sms.system-thread-list', $channels(new SmsMessageSent($system)));
        $this->assertNotContains('private-sms.thread-list', $channels(new SmsMessageReceived($reaction)));
        $this->assertSame(0, app(UnreadCountService::class)->forUser($manager->id, false)['sms']);
        $this->assertFalse(app(SmsInboxPolicy::class)->canView($manager, $thread->fresh()));
        $hidden = $this->message($thread, 'Private verification code', 'OUTBOUND', ['hidden_from_inbox' => true]);
        $this->assertSame([], $channels(new SmsMessageSent($hidden)));

        $reply = $this->message($thread, 'Hello', 'INBOUND');
        $this->assertContains('private-sms.thread-list', $channels(new SmsMessageReceived($reply)));
        $this->assertContains('private-sms.thread-list', $channels(new SmsThreadUpdated($thread->fresh())));
        $this->assertTrue(app(SmsInboxPolicy::class)->canView($manager, $thread->fresh()));
    }

    public function test_search_finds_historical_body_names_formatted_phones_and_groups(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $thread = $this->thread('Clara Client', '(202) 555-0112');
        $this->message($thread, 'Kitchen revision ABC%_42', 'INBOUND');
        $this->message($thread, 'Latest unrelated message');
        $group = SmsGroup::create(['name' => 'Weekend crew']);
        $groupThread = $this->thread('Crew', 'group:1');
        $groupThread->update(['sms_group_id' => $group->id]);
        $this->message($groupThread, 'Group message', 'OUTBOUND', ['metadata' => ['sms_inbox' => ['kind' => 'manual']]]);
        $hidden = $this->thread('Hidden contact');
        $this->message($hidden, 'Kitchen revision', 'OUTBOUND', ['hidden_from_inbox' => true]);
        foreach (['kItChEn', 'clara', '2025550112', '(202) 555-0112', 'ABC%_42'] as $search) {
            $this->getJson('/api/messaging/sms/threads?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', (string) $thread->id);
        }
        $this->getJson('/api/messaging/sms/threads?search=weekend')->assertOk()->assertJsonPath('data.0.id', (string) $groupThread->id);
        $this->getJson('/api/messaging/sms/threads?search=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/messaging/sms/threads?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
    }

    public function test_hidden_messages_do_not_leak_through_search_or_history_on_active_threads(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $thread = $this->thread();
        $this->message($thread, 'Human message', 'INBOUND');
        $this->message($thread, 'Secret group fanout', 'OUTBOUND', ['hidden_from_inbox' => true]);
        $thread->update(['last_snippet' => 'Secret group fanout', 'last_direction' => 'OUTBOUND', 'last_message_at' => now()->addDay()]);
        $this->getJson('/api/messaging/sms/threads')->assertOk()->assertJsonPath('data.0.lastMessageSnippet', 'Human message')
            ->assertJsonPath('data.0.lastDirection', 'INBOUND');
        $this->getJson('/api/messaging/sms/threads?search=secret')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_historical_backfill_uses_latest_human_event_not_later_automation(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $active = $this->thread();
        $system = $this->thread();
        $this->message($active, 'Question', 'INBOUND');
        $this->message($active, 'Reminder');
        $this->message($system, 'Automated reminder');
        $this->message($system, 'Liked "Automated reminder"', 'INBOUND');
        DB::table('message_threads')->update(['sms_inbox_active' => false, 'sms_needs_reply' => false]);
        app(SmsInboxPolicy::class)->backfill();
        $this->assertTrue($active->fresh()->sms_inbox_active);
        $this->assertTrue($active->fresh()->sms_needs_reply);
        $this->assertFalse($system->fresh()->sms_inbox_active);
        $this->message($active, 'Human answer', 'OUTBOUND', ['created_by' => $actor->id, 'send_source' => 'MANUAL']);
        $this->message($active, 'Another reminder');
        app(SmsInboxPolicy::class)->backfill();
        $this->assertFalse($active->fresh()->sms_needs_reply);
    }

    public function test_legacy_duplicate_context_is_exact_pair_only_and_excludes_hidden_and_groups(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $original = $this->thread();
        $reminder = $this->message($original, 'Original appointment');
        $this->message($original, 'Other business secret', 'OUTBOUND', ['from_address' => '+12025550101']);
        $this->message($original, 'Other person secret', 'OUTBOUND', ['to_address' => '+12025550113']);
        $this->message($original, 'Hidden group fanout', 'OUTBOUND', ['hidden_from_inbox' => true]);
        $group = $this->thread();
        $group->update(['sms_group_id' => SmsGroup::create(['name' => 'Group'])->id]);
        $this->message($group, 'Private group message');
        $replyThread = $this->thread();
        $reply = $this->message($replyThread, 'I will be late', 'INBOUND');
        $this->getJson('/api/messaging/sms/threads/'.$replyThread->id)->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.id', (string) $reminder->id)->assertJsonPath('messages.1.id', (string) $reply->id);
        $this->getJson('/api/messaging/sms/threads?search=appointment')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $replyThread->id);
        foreach (['business secret', 'person secret', 'group fanout', 'group message'] as $private) {
            $this->getJson('/api/messaging/sms/threads?search='.urlencode($private))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertDatabaseCount('messages', 6);
    }

    public function test_future_sms_uses_the_existing_exact_pair_even_when_contact_email_differs(): void
    {
        $actor = User::factory()->create(['role' => 'editing_manager']);
        Sanctum::actingAs($actor);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $provider = Mockery::mock(TelnyxSmsProvider::class);
        $provider->shouldReceive('send')->once()->andReturn('test-manual');
        $this->app->instance(TelnyxSmsProvider::class, $provider);
        SmsNumber::create(['phone_number' => self::BUSINESS, 'provider' => 'TELNYX', 'owner_type' => 'GLOBAL', 'is_default' => true]);
        $thread = $this->thread();
        $this->message($thread, 'Reminder');
        $this->postJson('/api/messaging/sms/send', ['to' => self::PERSON, 'body_text' => 'Human follow up'])
            ->assertOk()->assertJsonPath('thread.id', (string) $thread->id)->assertJsonPath('thread.inboxScope', 'inbox');
        $this->assertDatabaseCount('message_threads', 1);
    }

    public function test_photographers_only_see_their_own_active_direct_conversations(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'phone' => null, 'phonenumber' => '(202) 555-0112',
            'permission_overrides' => ['allow' => ['messaging-sms-view'], 'deny' => []]]);
        $own = $this->thread();
        $own->update(['unread_for_user_ids_json' => [$photographer->id]]);
        $ownMessage = $this->message($own, 'Photographer direct question', 'INBOUND');
        $other = $this->thread('Other client', '+12025550113');
        $other->update(['unread_for_user_ids_json' => [$photographer->id]]);
        $otherMessage = $this->message($other, 'Private client question', 'INBOUND');
        $group = SmsGroup::create(['name' => 'Private group']);
        $groupThread = $this->thread();
        $groupThread->update(['sms_group_id' => $group->id]);
        $this->message($groupThread, 'Private group note', 'INBOUND');
        Sanctum::actingAs($photographer);
        $this->getJson('/api/messaging/sms/threads')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $own->id);
        $this->getJson('/api/messaging/sms/threads/'.$own->id)->assertOk();
        $this->getJson('/api/messaging/sms/threads/'.$other->id)->assertForbidden();
        $this->getJson('/api/messaging/sms/threads/'.$groupThread->id)->assertForbidden();
        $this->getJson('/api/messaging/sms/threads?scope=system')->assertForbidden();
        $this->getJson('/api/messaging/sms/groups')->assertOk()->assertExactJson([]);
        $this->postJson('/api/messaging/sms/groups', ['name' => 'No access', 'members' => [['phone' => self::PERSON]]])->assertForbidden();
        $this->postJson('/api/messaging/sms/send', ['to' => '+12025550113', 'body_text' => 'No access'])->assertForbidden();
        $this->assertSame(0, app(UnreadCountService::class)->forUser($photographer->id, false)['sms']); // own was opened above
        $channels = \Illuminate\Support\Facades\Broadcast::getChannels();
        $this->assertFalse($channels['sms.thread-list']($photographer));
        $this->assertFalse($channels['sms.system-thread-list']($photographer));
        $this->assertTrue($channels['sms.user.{userId}']($photographer, $photographer->id));
        $this->assertFalse($channels['sms.user.{userId}']($photographer, $photographer->id + 100));
        $this->assertTrue($channels['sms.thread.{threadId}']($photographer, $own->id));
        $this->assertFalse($channels['sms.thread.{threadId}']($photographer, $other->id));
        $ownChannels = array_map(fn ($channel) => $channel->name, (new SmsMessageReceived($ownMessage))->broadcastOn());
        $otherChannels = array_map(fn ($channel) => $channel->name, (new SmsMessageReceived($otherMessage))->broadcastOn());
        $this->assertContains('private-sms.user.'.$photographer->id, $ownChannels);
        $this->assertNotContains('private-sms.user.'.$photographer->id, $otherChannels);
    }

    public function test_webhook_reuses_matching_business_pair_and_skips_ai_for_reactions(): void
    {
        config(['services.telnyx.public_key' => '', 'services.telnyx.ai_sms_enabled' => true]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ProcessInboundSmsAiJob::class]);
        $thread = $this->thread();
        $this->message($thread, 'Reminder tomorrow');
        $payload = fn ($id, $text, $business = self::BUSINESS) => ['data' => ['id' => 'event-'.$id, 'event_type' => 'message.received',
            'payload' => ['id' => $id, 'from' => ['phone_number' => self::PERSON], 'to' => [['phone_number' => $business]], 'text' => $text]]];
        $this->postJson('/api/webhooks/telnyx/messaging', $payload('reaction', 'Liked "Reminder tomorrow"'))->assertOk();
        $this->assertSame($thread->id, Message::where('provider_message_id', 'reaction')->value('thread_id'));
        $this->assertFalse($thread->fresh()->sms_inbox_active);
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\ProcessInboundSmsAiJob::class);
        $this->postJson('/api/webhooks/telnyx/messaging', $payload('reply', 'Running late'))->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessInboundSmsAiJob::class,
            fn ($job) => $job->messageId === Message::where('provider_message_id', 'reply')->value('id'));
        $this->assertSame($thread->id, Message::where('provider_message_id', 'reply')->value('thread_id'));
        $this->assertTrue($thread->fresh()->sms_inbox_active);
        $this->postJson('/api/webhooks/telnyx/messaging', $payload('other-line', 'Different line', '+12025550101'))->assertOk();
        $this->assertNotSame($thread->id, Message::where('provider_message_id', 'other-line')->value('thread_id'));
        $this->assertDatabaseCount('message_threads', 2);
    }

    public function test_explicit_sms_permission_denies_and_default_photographer_permissions_are_enforced(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager',
            'permission_overrides' => ['allow' => [], 'deny' => ['messaging-sms-view']]]);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $thread = $this->thread();
        $this->message($thread, 'Private reply', 'INBOUND');
        foreach ([$manager, $photographer] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/messaging/sms/threads')->assertForbidden();
            $this->getJson('/api/messaging/sms/threads/'.$thread->id)->assertForbidden();
            $channels = \Illuminate\Support\Facades\Broadcast::getChannels();
            $this->assertFalse($channels['sms.thread-list']($user));
            $this->assertFalse($channels['sms.thread.{threadId}']($user, $thread->id));
            $this->assertSame(0, app(UnreadCountService::class)->forUser($user->id, false)['sms']);
        }
    }

    private function thread(string $name = 'Test Client', string $phone = self::PERSON): MessageThread
    {
        $contact = Contact::create(['name' => $name, 'phone' => $phone, 'email' => uniqid().'@example.com', 'type' => 'client']);

        return MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id]);
    }

    private function message(MessageThread $thread, string $body, string $direction = 'OUTBOUND', array $extra = []): Message
    {
        return Message::create(array_merge([
            'thread_id' => $thread->id, 'channel' => 'SMS', 'direction' => $direction,
            'provider' => 'TEST', 'from_address' => $direction === 'INBOUND' ? self::PERSON : self::BUSINESS,
            'to_address' => $direction === 'INBOUND' ? self::BUSINESS : self::PERSON,
            'body_text' => $body, 'send_source' => $direction === 'INBOUND' ? 'MANUAL' : 'AUTOMATION', 'status' => 'DELIVERED',
        ], $extra));
    }
}
