<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\Providers\TwilioSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class TwilioMessagingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_sms_numbers_schema_is_twilio_ready(): void
    {
        $this->assertTrue(Schema::hasColumn('sms_numbers', 'provider'));
        $this->assertTrue(Schema::hasColumn('sms_numbers', 'twilio_phone_number_sid'));
        $this->assertFalse(Schema::hasColumn('sms_numbers', 'mighty_call_key'));
    }

    public function test_admin_can_send_sms_via_twilio_provider(): void
    {
        $this->bindTwilioProviderMock('SM123');
        $this->createDefaultSmsNumber();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/sms/send', [
            'to' => '+12025550100',
            'body_text' => 'Twilio test message',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('SMS', $message->channel);
        $this->assertSame('TWILIO', $message->provider);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('SM123', $message->provider_message_id);
        $this->assertSame('+12025550100', $message->to_address);
    }

    public function test_sms_automation_uses_twilio_provider(): void
    {
        $this->bindTwilioProviderMock('SM456', null);
        $this->createDefaultSmsNumber();

        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Taylor Client',
            'phonenumber' => '+12025550125',
        ]);

        $template = MessageTemplate::create([
            'channel' => 'SMS',
            'name' => 'Property Contact Reminder SMS',
            'body_text' => 'Reminder for {{recipient_name}}',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        AutomationRule::create([
            'name' => 'Property Contact Reminder SMS',
            'trigger_type' => 'PROPERTY_CONTACT_REMINDER',
            'template_id' => $template->id,
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => ['client'],
        ]);

        app(AutomationService::class)->handleEvent('PROPERTY_CONTACT_REMINDER', [
            'client' => $client,
        ]);

        $message = Message::where('send_source', 'AUTOMATION')
            ->where('channel', 'SMS')
            ->first();
        $this->assertNotNull($message);
        $this->assertSame('TWILIO', $message->provider);
        $this->assertSame('+12025550125', $message->to_address);
        $this->assertSame('SENT', $message->status);
    }

    public function test_twilio_inbound_webhook_creates_sms_thread_message(): void
    {
        config()->set('services.twilio.auth_token', 'test-token');
        $this->createDefaultSmsNumber();

        $payload = [
            'MessageSid' => 'SM_INBOUND_001',
            'From' => '+12025550177',
            'To' => '+18883426998',
            'Body' => 'Reply from client',
        ];

        $response = $this->postTwilioForm('/api/webhooks/twilio/messaging', $payload);

        $response->assertOk();

        $message = Message::where('provider_message_id', 'SM_INBOUND_001')->first();
        $this->assertNotNull($message);
        $this->assertSame('TWILIO', $message->provider);
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame('DELIVERED', $message->status);

        $thread = MessageThread::find($message->thread_id);
        $this->assertNotNull($thread);
        $this->assertSame('INBOUND', $thread->last_direction);
        $this->assertSame('Reply from client', $thread->last_snippet);
    }

    public function test_twilio_status_webhook_updates_outbound_message_status(): void
    {
        config()->set('services.twilio.auth_token', 'test-token');
        $thread = $this->createSmsThread();

        $message = Message::create([
            'channel' => 'SMS',
            'direction' => 'OUTBOUND',
            'provider' => 'TWILIO',
            'provider_message_id' => 'SM_STATUS_001',
            'from_address' => '+18883426998',
            'to_address' => '+12025550188',
            'body_text' => 'Status test',
            'status' => 'SENT',
            'thread_id' => $thread->id,
            'sent_at' => now(),
        ]);

        $response = $this->postTwilioForm('/api/webhooks/twilio/status', [
            'MessageSid' => 'SM_STATUS_001',
            'MessageStatus' => 'delivered',
        ]);

        $response->assertOk();

        $message->refresh();
        $this->assertSame('DELIVERED', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    private function bindTwilioProviderMock(string $messageSid, ?int $times = 1): void
    {
        $mock = Mockery::mock(TwilioSmsProvider::class);
        $expectation = $mock->shouldReceive('send');

        if ($times === null) {
            $expectation->atLeast()->once();
        } else {
            $expectation->times($times);
        }

        $expectation->andReturn($messageSid);

        $this->app->instance(TwilioSmsProvider::class, $mock);
    }

    private function createDefaultSmsNumber(): SmsNumber
    {
        return SmsNumber::create([
            'provider' => 'TWILIO',
            'phone_number' => '+18883426998',
            'label' => 'Twilio Toll-Free',
            'twilio_phone_number_sid' => 'PN123',
            'owner_type' => 'GLOBAL',
            'is_default' => true,
        ]);
    }

    private function createSmsThread(): MessageThread
    {
        $contact = \App\Models\Contact::create([
            'name' => 'Client Contact',
            'phone' => '+12025550188',
            'type' => 'client',
        ]);

        return MessageThread::create([
            'channel' => 'SMS',
            'contact_id' => $contact->id,
            'last_message_at' => now(),
        ]);
    }

    private function postTwilioForm(string $uri, array $payload)
    {
        $url = url($uri);
        $signature = (new RequestValidator((string) config('services.twilio.auth_token')))
            ->computeSignature($url, $payload);

        return $this->post($uri, $payload, [
            'X-Twilio-Signature' => $signature,
        ]);
    }
}
