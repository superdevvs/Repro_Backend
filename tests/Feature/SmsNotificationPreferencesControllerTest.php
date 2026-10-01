<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SmsNotificationPreferencesControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        SmsNumber::create([
            'provider' => 'TELNYX',
            'phone_number' => '+18883426998',
            'messaging_profile_id' => 'test-profile',
            'owner_type' => 'GLOBAL',
            'is_default' => true,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_single_sms_reports_notification_preference_block_without_sending(): void
    {
        $this->expectProviderSends(0);
        $this->disabledRecipient('+12025550110');

        $this->postJson('/api/messaging/sms/send', [
            'to' => '+12025550110',
            'body_text' => 'Schedule update.',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'sms_send_blocked');

        $this->assertSame('BLOCKED', Message::query()->sole()->status);
    }

    public function test_bulk_sms_counts_only_delivered_recipients(): void
    {
        $this->expectProviderSends(1);
        $this->disabledRecipient('+12025550110');

        $this->postJson('/api/messaging/sms/send', [
            'to' => ['+12025550110', '+12025550111'],
            'body_text' => 'Schedule update.',
        ])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('results.0.status', 'failed')
            ->assertJsonPath('results.1.status', 'sent')
            ->assertJsonPath('message.to', '+12025550111');

        $this->assertSame(1, Message::query()->where('status', 'SENT')->count());
        $this->assertSame(1, Message::query()->where('status', 'BLOCKED')->count());
    }

    public function test_fully_blocked_group_does_not_create_a_sent_group_copy(): void
    {
        $this->expectProviderSends(0);
        $recipient = $this->disabledRecipient('+12025550110');
        $groupId = $this->createGroup([['user_id' => $recipient->id]]);

        $this->postJson('/api/messaging/sms/send', [
            'group_ids' => [$groupId],
            'body_text' => 'Schedule update.',
        ])->assertUnprocessable()
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('results.0.status', 'failed');

        $this->assertSame(0, Message::query()->where('status', 'SENT')->count());
        $this->assertSame(0, MessageThread::query()->where('sms_group_id', $groupId)->count());
    }

    public function test_partly_blocked_group_counts_only_delivered_members(): void
    {
        $this->expectProviderSends(1);
        $recipient = $this->disabledRecipient('+12025550110');
        $groupId = $this->createGroup([
            ['user_id' => $recipient->id],
            ['phone' => '+12025550111', 'name' => 'Reachable'],
        ]);

        $this->postJson('/api/messaging/sms/send', [
            'group_ids' => [$groupId],
            'body_text' => 'Schedule update.',
        ])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 1);

        $this->assertSame(1, Message::query()->where('hidden_from_inbox', true)->where('status', 'SENT')->count());
        $this->assertSame(1, Message::query()->where('to_address', 'group:'.$groupId)->where('status', 'SENT')->count());
    }

    public function test_group_and_direct_send_reports_blocked_direct_recipient(): void
    {
        $this->expectProviderSends(1);
        $this->disabledRecipient('+12025550110');
        $groupId = $this->createGroup([['phone' => '+12025550111', 'name' => 'Reachable']]);

        $this->postJson('/api/messaging/sms/send', [
            'group_ids' => [$groupId],
            'to' => '+12025550110',
            'body_text' => 'Schedule update.',
        ])->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('results.1.status', 'failed');
    }

    public function test_blocked_thread_reply_does_not_pause_ai_or_claim_success(): void
    {
        $this->expectProviderSends(0);
        $recipient = $this->disabledRecipient('+12025550110');
        $contact = Contact::create([
            'name' => $recipient->name,
            'user_id' => $recipient->id,
            'phone' => '+12025550110',
            'type' => 'client',
        ]);
        $thread = MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id, 'status' => 'OPEN']);

        $this->postJson('/api/messaging/sms/threads/'.$thread->id.'/messages', [
            'body' => 'Schedule update.',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'sms_send_blocked');

        $this->assertNull($thread->refresh()->ai_paused_until);
        $this->assertSame('BLOCKED', Message::query()->sole()->status);
    }

    private function disabledRecipient(string $phone): User
    {
        return User::factory()->create([
            'role' => 'client',
            'phonenumber' => $phone,
            'phone' => $phone,
            'metadata' => ['preferences' => ['notificationSMS' => false]],
        ]);
    }

    private function createGroup(array $members): int
    {
        $response = $this->postJson('/api/messaging/sms/groups', [
            'name' => 'Notification preferences test',
            'members' => $members,
        ])->assertCreated();

        return (int) $response->json('id');
    }

    private function expectProviderSends(int $count): void
    {
        $provider = Mockery::mock(TelnyxSmsProvider::class);
        $provider->shouldReceive('send')->times($count)->andReturn('test-provider-message');
        $this->app->instance(TelnyxSmsProvider::class, $provider);
    }
}
