<?php

namespace Tests\Feature;

use App\Events\SmsMessageReceived;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Services\Messaging\SmsReactionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmsReactionPresentationTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS = '+12025550100';

    private const PERSON = '+12025550112';

    public function test_romanian_reaction_in_a_duplicate_contact_thread_returns_the_actual_sent_message(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $sentThread = $this->thread();
        $inboundThread = $this->thread();
        $body = "Shoot reminder\n12800 Middlebrook Rd, Unit 206, Germantown, MD 20874\nOct 15, 2026 2:30 PM EDT\nContact: Andrew 703-887-2513\nDetails: https://reprodashboard.com/shoots/2474";
        $sent = $this->message($sentThread, 'OUTBOUND', $body, '2026-10-15 12:30:00');
        $reaction = $this->message($inboundThread, 'INBOUND', 'A aprobat „'.$body.'”', '2026-10-15 12:30:26');
        $before = $reaction->fresh()->getRawOriginal();

        $response = $this->getJson('/api/messaging/sms/threads/'.$inboundThread->id)->assertOk();
        $response->assertJsonCount(2, 'messages');
        $response->assertJsonPath('messages.0.id', (string) $sent->id);
        $response->assertJsonPath('messages.0.threadId', (string) $sentThread->id);
        $response->assertJsonPath('messages.0.body', $body);
        $response->assertJsonPath('messages.1.reaction.emoji', '👍');
        $response->assertJsonPath('messages.1.reaction.targetMessageId', (string) $sent->id);
        $response->assertJsonPath('messages.1.reaction.quotedMessage', $body);
        $response->assertJsonPath('messages.1.body', $reaction->body_text);
        $this->assertSame($before, $reaction->fresh()->getRawOriginal());
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_same_thread_uses_latest_preceding_exact_match_with_normalized_both_endpoints(): void
    {
        $thread = $this->thread();
        $this->message($thread, 'OUTBOUND', 'Hello', '2026-10-15 12:00:00');
        $target = $this->message($thread, 'OUTBOUND', 'Hello', '2026-10-15 12:01:00', [
            'from_address' => '(202) 555-0100', 'to_address' => '1 (202) 555-0112',
        ]);
        $event = $this->message($thread, 'INBOUND', 'Liked "Hello"', '2026-10-15 12:02:00');
        $this->message($thread, 'OUTBOUND', 'Hello', '2026-10-15 12:03:00');

        $messages = $this->present($thread);

        $this->assertSame((string) $target->id, $messages->find($event->id)->getAttribute('_sms_reaction')['targetMessageId']);
    }

    public function test_wrong_person_or_business_number_never_links_or_leaks_an_external_message(): void
    {
        $otherThread = $this->thread();
        $thread = $this->thread();
        $this->message($otherThread, 'OUTBOUND', 'Private reminder', '2026-10-15 12:00:00', ['to_address' => '+12025550113']);
        $this->message($otherThread, 'OUTBOUND', 'Private reminder', '2026-10-15 12:00:01', ['from_address' => '+12025550101']);
        $this->message($thread, 'OUTBOUND', 'Private reminder', '2026-10-15 12:00:02', ['to_address' => '+12025550114']);
        $event = $this->message($thread, 'INBOUND', 'Liked "Private reminder"', '2026-10-15 12:01:00');

        $messages = $this->present($thread);

        $this->assertCount(2, $messages);
        $this->assertNull($messages->find($event->id)->getAttribute('_sms_reaction')['targetMessageId']);
        $this->assertFalse($messages->contains('thread_id', $otherThread->id));
    }

    public function test_exact_match_rejects_prefix_substring_and_differing_whitespace(): void
    {
        $thread = $this->thread();
        $other = $this->thread();
        $this->message($other, 'OUTBOUND', 'Reminder tomorrow at 1', '2026-10-15 12:00:00');
        $this->message($other, 'OUTBOUND', 'Reminder ', '2026-10-15 12:00:01');
        $event = $this->message($thread, 'INBOUND', 'Liked "Reminder"', '2026-10-15 12:01:00');

        $messages = $this->present($thread);

        $this->assertCount(1, $messages);
        $this->assertNull($messages->find($event->id)->getAttribute('_sms_reaction')['targetMessageId']);
    }

    public function test_normal_unknown_and_outbound_envelopes_are_untouched(): void
    {
        $thread = $this->thread();
        $this->message($thread, 'OUTBOUND', 'Liked "Hello"', '2026-10-15 12:00:00');
        $this->message($thread, 'INBOUND', 'A aprobat planul', '2026-10-15 12:01:00');
        $this->message($thread, 'INBOUND', 'Agreed "Hello"', '2026-10-15 12:02:00');

        foreach ($this->present($thread) as $message) {
            $this->assertNull($message->getAttribute('_sms_reaction'));
        }
    }

    public function test_replacement_and_removal_events_resolve_to_the_same_message_without_mutating_history(): void
    {
        $thread = $this->thread();
        $target = $this->message($thread, 'OUTBOUND', 'Hello', '2026-10-15 12:00:00');
        $like = $this->message($thread, 'INBOUND', 'Liked "Hello"', '2026-10-15 12:01:00');
        $love = $this->message($thread, 'INBOUND', 'Loved "Hello"', '2026-10-15 12:02:00');
        $remove = $this->message($thread, 'INBOUND', 'Removed a heart from "Hello"', '2026-10-15 12:03:00');

        $messages = $this->present($thread);

        foreach ([$like, $love, $remove] as $event) {
            $this->assertSame((string) $target->id, $messages->find($event->id)->getAttribute('_sms_reaction')['targetMessageId']);
        }
        $this->assertSame('add', $messages->find($like->id)->getAttribute('_sms_reaction')['action']);
        $this->assertSame('❤️', $messages->find($love->id)->getAttribute('_sms_reaction')['emoji']);
        $this->assertSame('remove', $messages->find($remove->id)->getAttribute('_sms_reaction')['action']);
        $this->assertDatabaseCount('messages', 4);
    }

    public function test_same_thread_has_priority_over_a_newer_duplicate_without_querying_other_threads(): void
    {
        $thread = $this->thread();
        $other = $this->thread();
        $target = $this->message($thread, 'OUTBOUND', 'Hello', '2026-10-15 12:00:00');
        $this->message($other, 'OUTBOUND', 'Hello', '2026-10-15 12:01:00');
        for ($i = 0; $i < 20; $i++) {
            $this->message($thread, 'INBOUND', 'Liked "Hello"', '2026-10-15 12:02:00');
        }
        $history = $thread->messages()->get();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $messages = app(SmsReactionPresenter::class)->present($thread, $history);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $this->assertCount(21, $messages);
        $this->assertSame((string) $target->id, $messages->last()->getAttribute('_sms_reaction')['targetMessageId']);
    }

    public function test_cross_thread_resolution_batches_all_reactions_into_one_query(): void
    {
        $thread = $this->thread();
        $other = $this->thread();
        $target = $this->message($other, 'OUTBOUND', 'Hello', '2026-10-15 12:00:00');
        for ($i = 0; $i < 20; $i++) {
            $this->message($thread, 'INBOUND', 'Liked "Hello"', '2026-10-15 12:02:00');
        }
        $history = $thread->messages()->get();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $messages = app(SmsReactionPresenter::class)->present($thread, $history);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertCount(21, $messages);
        $this->assertSame((string) $target->id, $messages->last()->getAttribute('_sms_reaction')['targetMessageId']);
    }

    public function test_realtime_resource_exposes_unresolved_reaction_without_querying_for_its_target(): void
    {
        $thread = $this->thread();
        $message = $this->message($thread, 'INBOUND', 'A aprobat „Hello”', '2026-10-15 12:02:00');
        $event = new SmsMessageReceived($message);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $payload = $event->broadcastWith();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('👍', $payload['message']['reaction']['emoji']);
        $this->assertNull($payload['message']['reaction']['targetMessageId']);
        $this->assertSame('A aprobat „Hello”', $payload['message']['body']);
        $this->assertCount(0, $queries);
    }

    private function thread(): MessageThread
    {
        $contact = Contact::query()->create(['name' => 'Reaction test', 'phone' => self::PERSON, 'type' => 'client']);

        return MessageThread::query()->create(['channel' => 'SMS', 'contact_id' => $contact->id]);
    }

    private function message(MessageThread $thread, string $direction, string $body, string $time, array $overrides = []): Message
    {
        return Message::query()->forceCreate(array_merge([
            'thread_id' => $thread->id,
            'channel' => 'SMS',
            'provider' => 'TEST',
            'direction' => $direction,
            'from_address' => $direction === 'INBOUND' ? self::PERSON : self::BUSINESS,
            'to_address' => $direction === 'INBOUND' ? self::BUSINESS : self::PERSON,
            'body_text' => $body,
            'status' => $direction === 'INBOUND' ? 'RECEIVED' : 'SENT',
            'created_at' => $time,
            'updated_at' => $time,
        ], $overrides));
    }

    private function present(MessageThread $thread)
    {
        return app(SmsReactionPresenter::class)->present($thread, $thread->messages()->orderBy('created_at')->get());
    }
}
