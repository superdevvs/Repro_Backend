<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsGroup;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SmsSenderSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const PERSON = '+12025550112';

    public function test_only_superadmins_can_list_sender_numbers_without_settings_secrets(): void
    {
        $default = $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        SmsNumber::create(['phone_number' => '+12025550102', 'provider' => 'TWILIO', 'label' => 'Legacy']);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $response = $this->getJson('/api/messaging/sms/senders')->assertOk()->assertJsonCount(2, 'numbers')
            ->assertJsonPath('numbers.0.id', $default->id)->assertJsonPath('numbers.1.id', $alternate->id);
        $this->assertEqualsCanonicalizing(['id', 'phone_number', 'label', 'is_default'], array_keys($response->json('numbers.0')));
        foreach (['admin', 'editing_manager', 'salesRep', 'photographer', 'client'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/messaging/sms/senders')->assertForbidden();
        }
    }

    public function test_new_sms_uses_the_selected_configured_sender(): void
    {
        $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->expectSender($alternate);
        $this->postJson('/api/messaging/sms/send', ['to' => self::PERSON, 'body_text' => 'New conversation', 'sms_number_id' => $alternate->id])
            ->assertOk()->assertJsonPath('thread.businessNumber', $alternate->phone_number);
        $this->assertSame($alternate->phone_number, Message::sole()->from_address);
    }

    public function test_default_reply_preserves_the_conversation_number_for_editing_managers(): void
    {
        $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        $actor = User::factory()->create(['role' => 'editing_manager']);
        $thread = $this->thread($alternate, $actor, inbound: true);
        Sanctum::actingAs($actor);
        $this->expectSender($alternate);
        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/messages', ['body' => 'Reply from same line'])
            ->assertOk()->assertJsonPath('thread.id', (string) $thread->id)
            ->assertJsonPath('thread.businessNumber', $alternate->phone_number);
        $this->assertSame($alternate->phone_number, Message::where('direction', 'OUTBOUND')->sole()->from_address);
    }

    public function test_selecting_a_different_reply_number_creates_a_separate_conversation(): void
    {
        $default = $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        $actor = User::factory()->create(['role' => 'superadmin']);
        $original = $this->thread($alternate, $actor);
        Sanctum::actingAs($actor);
        $this->expectSender($default);
        $response = $this->postJson('/api/messaging/sms/threads/'.$original->id.'/messages', [
            'body' => 'From another line', 'sms_number_id' => $default->id,
        ])->assertOk()->assertJsonPath('thread.businessNumber', $default->phone_number);
        $this->assertNotSame((string) $original->id, $response->json('thread.id'));
        $this->assertSame(1, $original->messages()->count());
        $this->assertDatabaseCount('message_threads', 2);
        $this->assertNull($original->fresh()->ai_paused_until);
    }

    public function test_selecting_another_line_reuses_its_existing_matching_conversation(): void
    {
        $default = $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        $actor = User::factory()->create(['role' => 'superadmin']);
        $first = $this->thread($alternate, $actor);
        $matching = $this->thread($default, $actor);
        Sanctum::actingAs($actor);
        $this->expectSender($default);
        $this->postJson('/api/messaging/sms/threads/'.$first->id.'/messages', [
            'body' => 'Existing other line', 'sms_number_id' => $default->id,
        ])->assertOk()->assertJsonPath('thread.id', (string) $matching->id);
        $this->assertDatabaseCount('message_threads', 2);
    }

    public function test_legacy_mixed_sender_history_does_not_capture_a_new_pair(): void
    {
        $default = $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        $actor = User::factory()->create(['role' => 'superadmin']);
        $original = $this->thread($alternate, $actor);
        Message::forceCreate(['channel' => 'SMS', 'thread_id' => $original->id, 'direction' => 'OUTBOUND',
            'from_address' => $default->phone_number, 'to_address' => self::PERSON, 'body_text' => 'Older mixed line',
            'status' => 'DELIVERED', 'send_source' => 'MANUAL', 'created_by' => $actor->id, 'created_at' => now()->subDay()]);
        Sanctum::actingAs($actor);
        $this->expectSender($default);
        $sent = $this->postJson('/api/messaging/sms/threads/'.$original->id.'/messages', ['body' => 'Separate line', 'sms_number_id' => $default->id])
            ->assertOk()->assertJsonPath('thread.businessNumber', $default->phone_number);
        $this->assertNotSame((string) $original->id, $sent->json('thread.id'));
        $this->assertSame(2, $original->messages()->count());
    }

    public function test_changed_contact_phone_starts_the_correct_pair_and_later_inbound_reuses_it(): void
    {
        $number = $this->number('+12025550100', true);
        $actor = User::factory()->create(['role' => 'editing_manager']);
        $original = $this->thread($number, $actor, inbound: true);
        $original->contact->update(['phone' => '+12025550113']);
        Sanctum::actingAs($actor);
        $this->expectSender($number);
        $response = $this->postJson('/api/messaging/sms/threads/'.$original->id.'/messages', ['body' => 'New contact number'])
            ->assertOk()->assertJsonPath('message.to', '+12025550113');
        $newThreadId = $response->json('thread.id');
        $this->assertNotSame((string) $original->id, $newThreadId);
        $this->assertSame(1, $original->messages()->count());
        config(['services.telnyx.public_key' => '', 'services.telnyx.ai_sms_enabled' => false]);
        $this->postJson('/api/webhooks/telnyx/messaging', ['data' => ['id' => 'event-updated-contact', 'event_type' => 'message.received',
            'payload' => ['id' => 'inbound-updated-contact', 'from' => ['phone_number' => '+12025550113'],
                'to' => [['phone_number' => $number->phone_number]], 'text' => 'Reply from new contact number']]])->assertOk();
        $this->assertSame((int) $newThreadId, Message::where('provider_message_id', 'inbound-updated-contact')->value('thread_id'));
        $this->assertSame(1, $original->messages()->count());
    }

    public function test_group_reply_preserves_its_number_and_an_explicit_change_keeps_histories_separate(): void
    {
        $default = $this->number('+12025550100', true);
        $alternate = $this->number('+12025550101');
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $group = SmsGroup::create(['name' => 'Crew', 'created_by' => $superadmin->id]);
        $group->members()->create(['phone' => self::PERSON, 'name' => 'Photographer']);
        Sanctum::actingAs($superadmin);
        $provider = $this->expectSender($alternate, 2);
        $provider->shouldReceive('send')->once()->with(Mockery::on(fn ($sender) => $sender->id === $default->id), Mockery::type('array'))
            ->andReturn('fake-default-group');
        $sent = $this->postJson('/api/messaging/sms/send', ['group_ids' => [$group->id], 'body_text' => 'Crew note', 'sms_number_id' => $alternate->id])
            ->assertOk()->assertJsonPath('thread.businessNumber', $alternate->phone_number);
        $threadId = $sent->json('thread.id');
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->postJson('/api/messaging/sms/threads/'.$threadId.'/messages', ['body' => 'Crew follow up'])
            ->assertOk()->assertJsonPath('thread.id', $threadId)->assertJsonPath('thread.businessNumber', $alternate->phone_number);
        $this->assertSame(2, Message::where('thread_id', $threadId)->count());
        Sanctum::actingAs($superadmin);
        $changed = $this->postJson('/api/messaging/sms/threads/'.$threadId.'/messages', ['body' => 'Another line', 'sms_number_id' => $default->id])
            ->assertOk()->assertJsonPath('thread.businessNumber', $default->phone_number);
        $this->assertNotSame($threadId, $changed->json('thread.id'));
        $this->assertSame(2, Message::where('thread_id', $threadId)->count());
        $this->assertSame(2, MessageThread::where('sms_group_id', $group->id)->count());
    }

    public function test_unknown_or_legacy_sender_ids_are_rejected_before_sending(): void
    {
        $default = $this->number('+12025550100', true);
        $legacy = SmsNumber::create(['phone_number' => '+12025550102', 'provider' => 'TWILIO']);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $thread = $this->thread($default, $actor);
        Sanctum::actingAs($actor);
        $this->mock(TelnyxSmsProvider::class)->shouldNotReceive('send');
        foreach ([$legacy->id, 999999] as $senderId) {
            $this->postJson('/api/messaging/sms/send', ['to' => self::PERSON, 'body_text' => 'Hi', 'sms_number_id' => $senderId])->assertUnprocessable();
            $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/messages', ['body' => 'Hi', 'sms_number_id' => $senderId])->assertUnprocessable();
        }
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_removed_conversation_number_does_not_fall_back_to_another_line(): void
    {
        $this->number('+12025550100', true);
        $removed = $this->number('+12025550101');
        $actor = User::factory()->create(['role' => 'editing_manager']);
        $thread = $this->thread($removed, $actor);
        $removed->delete();
        Sanctum::actingAs($actor);
        $this->mock(TelnyxSmsProvider::class)->shouldNotReceive('send');
        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/messages', ['body' => 'Hi'])->assertUnprocessable()
            ->assertJsonValidationErrors('sms_number_id');
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_group_sender_migration_preserves_rows_foreign_keys_and_refuses_destructive_rollback(): void
    {
        $number = $this->number('+12025550100', true);
        $actor = User::factory()->create(['role' => 'superadmin']);
        $group = SmsGroup::create(['name' => 'Preserved crew', 'created_by' => $actor->id]);
        $original = $this->thread($number, $actor);
        $original->update(['sms_group_id' => $group->id]);
        $before = Message::get(['id', 'thread_id', 'body_text'])->toArray();
        $foreignKeys = DB::select('PRAGMA foreign_key_list(message_threads)');
        $migration = require base_path('database/migrations/2026_10_17_130000_allow_sms_group_threads_per_sender.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, Message::get(['id', 'thread_id', 'body_text'])->toArray());
        $this->assertEquals($foreignKeys, DB::select('PRAGMA foreign_key_list(message_threads)'));
        $second = MessageThread::create(['contact_id' => $original->contact_id, 'channel' => 'SMS', 'sms_group_id' => $group->id]);
        try {
            $migration->down();
            $this->fail('Rollback must preserve multiple sender histories.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('preserved', $error->getMessage());
        }
        $this->assertDatabaseCount('message_threads', 2);
        $this->assertSame($before, Message::get(['id', 'thread_id', 'body_text'])->toArray());
        $group->delete();
        $this->assertNull($original->fresh()->sms_group_id);
        $this->assertNull($second->fresh()->sms_group_id);
        $this->assertDatabaseCount('messages', 1);
    }

    private function number(string $phone, bool $default = false): SmsNumber
    {
        return SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => $phone, 'label' => 'Business '.$phone,
            'is_default' => $default, 'messaging_profile_id' => 'private-profile-id', 'telnyx_phone_number_id' => 'private-number-id']);
    }

    private function thread(SmsNumber $number, User $actor, bool $inbound = false): MessageThread
    {
        $contact = Contact::create(['phone' => self::PERSON, 'name' => 'Client', 'type' => 'client']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS']);
        Message::create(['channel' => 'SMS', 'thread_id' => $thread->id, 'direction' => $inbound ? 'INBOUND' : 'OUTBOUND',
            'from_address' => $inbound ? self::PERSON : $number->phone_number,
            'to_address' => $inbound ? $number->phone_number : self::PERSON,
            'body_text' => 'Initial conversation', 'send_source' => 'MANUAL', 'created_by' => $actor->id, 'status' => 'DELIVERED']);

        return $thread;
    }

    private function expectSender(SmsNumber $number, int $times = 1): \Mockery\MockInterface
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $provider = Mockery::mock(TelnyxSmsProvider::class);
        $provider->shouldReceive('send')->times($times)
            ->with(Mockery::on(fn ($sender) => $sender->id === $number->id), Mockery::type('array'))
            ->andReturnUsing(fn () => 'fake-'.uniqid());
        $this->app->instance(TelnyxSmsProvider::class, $provider);

        return $provider;
    }
}
