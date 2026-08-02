<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class TelnyxMessagingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private string $publicKeyB64;
    private string $secretKeyB64;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite asserts the send pipeline itself (SENT status, provider id,
        // handled 422 on provider rejection), so it needs messages to reach the
        // injected Mockery double rather than being withheld by the delivery
        // guard. The double is not a live provider, and the concrete provider
        // remains bound to a fake, so nothing leaves the process.
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();

        if (!extension_loaded('sodium')) {
            // Webhook signing tests still need libsodium to forge signed fixtures.
            // The provider+automation tests (which don't need sodium) won't reach this branch
            // because we still need keys initialized; allow them to run with empty placeholders.
            $this->publicKeyB64 = '';
            $this->secretKeyB64 = '';
            config()->set('services.telnyx.public_key', '');
            config()->set('services.telnyx.webhook_tolerance_seconds', 300);

            return;
        }

        // Generate an Ed25519 key pair once per test for signed-webhook fixtures.
        $keyPair = sodium_crypto_sign_keypair();
        $this->publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $this->secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($keyPair));

        config()->set('services.telnyx.public_key', $this->publicKeyB64);
        config()->set('services.telnyx.webhook_tolerance_seconds', 300);
    }

    private function skipIfNoSodium(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('libsodium extension is required to forge Telnyx Ed25519 webhook signatures.');
        }
    }

    public function test_sms_numbers_schema_is_telnyx_ready(): void
    {
        $this->assertTrue(Schema::hasColumn('sms_numbers', 'provider'));
        $this->assertTrue(Schema::hasColumn('sms_numbers', 'telnyx_phone_number_id'));
        $this->assertTrue(Schema::hasColumn('sms_numbers', 'messaging_profile_id'));
    }

    public function test_admin_can_send_sms_via_telnyx_provider(): void
    {
        $this->bindTelnyxProviderMock('telnyx-msg-1');
        $this->createDefaultSmsNumber();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/sms/send', [
            'to' => '+12025550100',
            'body_text' => 'Telnyx test message',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('SMS', $message->channel);
        $this->assertSame('TELNYX', $message->provider);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('telnyx-msg-1', $message->provider_message_id);
        $this->assertSame('+12025550100', $message->to_address);
    }

    public function test_sms_send_against_unverified_number_returns_handled_422_and_records_failure(): void
    {
        // Provider rejects the send the way Telnyx does for an unverified toll-free sender.
        $this->bindThrowingTelnyxProviderMock(
            new RuntimeException('Toll-free number is unverified (code 40300): traffic blocked until verification completes.')
        );
        $this->createDefaultSmsNumber();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/sms/send', [
            'to' => '+12025550100',
            'body_text' => 'Telnyx unverified-number test',
        ]);

        // Must be a handled 4xx (422), never an unhandled 500 (Req 2.1, 2.2, 7.2).
        $response->assertStatus(422);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertJson([
            'success' => false,
            'error' => 'sms_send_failed',
        ]);

        // The client-safe message must indicate the sending number is not verified (Req 2.4).
        $this->assertStringContainsStringIgnoringCase(
            'not verified',
            (string) $response->json('message')
        );

        // The failure must be recorded on the message row (Req 2.1, 2.3).
        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('SMS', $message->channel);
        $this->assertSame('FAILED', $message->status);
        $this->assertNotNull($message->failed_at);
    }

    public function test_sms_send_returns_handled_422_even_when_failure_write_throws(): void
    {
        // Provider rejects the send (generic provider error).
        $this->bindThrowingTelnyxProviderMock(
            new RuntimeException('Telnyx provider rejected the request.')
        );
        $this->createDefaultSmsNumber();

        // Simulate production schema drift: the failure-write itself fails because the
        // failed_at column is missing from the messages table. The defensive inner
        // try/catch must swallow this so the request still resolves to a handled 422.
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('failed_at');
        });

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/sms/send', [
            'to' => '+12025550100',
            'body_text' => 'Telnyx failure-write drift test',
        ]);

        // Even though recording the failure threw a DB error, the endpoint must return a
        // handled 422 and never an unhandled 500 (Req 2.3, 7.2).
        $response->assertStatus(422);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertJson([
            'success' => false,
            'error' => 'sms_send_failed',
        ]);
    }

    public function test_sms_automation_uses_telnyx_provider(): void
    {
        $this->bindTelnyxProviderMock('telnyx-msg-2', null);
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
        $this->assertSame('TELNYX', $message->provider);
        $this->assertSame('+12025550125', $message->to_address);
        $this->assertSame('SENT', $message->status);
    }

    public function test_scheduled_runner_dispatches_due_sms_and_internal_messages(): void
    {
        $this->bindTelnyxProviderMock('scheduled-sms-id');
        $number = $this->createDefaultSmsNumber();

        $sms = Message::create([
            'channel' => 'SMS',
            'direction' => 'OUTBOUND',
            'provider' => 'TELNYX',
            'from_address' => $number->phone_number,
            'to_address' => '+12025550177',
            'body_text' => 'Scheduled SMS body',
            'status' => 'SCHEDULED',
            'scheduled_at' => now()->subMinute(),
            'send_source' => 'MANUAL',
        ]);

        $internal = Message::create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'INTERNAL',
            'from_address' => 'admin@example.com',
            'to_address' => 'team@example.com',
            'subject' => 'Internal note',
            'body_text' => 'Internal scheduled body',
            'status' => 'SCHEDULED',
            'scheduled_at' => now()->subMinute(),
            'send_source' => 'MANUAL',
        ]);

        $workflowExecutor = Mockery::mock(AutomationWorkflowExecutor::class);
        $workflowExecutor->shouldReceive('resumeDueSteps')->once();

        (new DispatchScheduledMessages())->handle(
            $this->app->make(MessagingService::class),
            $workflowExecutor,
            $this->app->make(AutomationService::class)
        );

        $sms->refresh();
        $internal->refresh();

        $this->assertSame('SENT', $sms->status);
        $this->assertSame('scheduled-sms-id', $sms->provider_message_id);
        $this->assertNotNull($sms->sent_at);

        $this->assertSame('SENT', $internal->status);
        $this->assertSame('INTERNAL', $internal->provider);
        $this->assertNotNull($internal->sent_at);
    }

    public function test_telnyx_inbound_webhook_creates_sms_thread_message(): void
    {
        $this->skipIfNoSodium();
        $this->createDefaultSmsNumber();

        $payload = [
            'data' => [
                'id' => 'evt_inbound_1',
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg_inbound_1',
                    'from' => ['phone_number' => '+12025550177'],
                    'to' => [['phone_number' => '+18883426998']],
                    'text' => 'Reply from client',
                ],
            ],
        ];

        $response = $this->postSignedTelnyx('/api/webhooks/telnyx/messaging', $payload);
        $response->assertOk();

        $message = Message::where('provider_message_id', 'msg_inbound_1')->first();
        $this->assertNotNull($message);
        $this->assertSame('TELNYX', $message->provider);
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame('DELIVERED', $message->status);

        $thread = MessageThread::find($message->thread_id);
        $this->assertNotNull($thread);
        $this->assertSame('INBOUND', $thread->last_direction);
        $this->assertSame('Reply from client', $thread->last_snippet);
    }

    public function test_telnyx_status_webhook_updates_outbound_message_status(): void
    {
        $this->skipIfNoSodium();
        $thread = $this->createSmsThread();

        $message = Message::create([
            'channel' => 'SMS',
            'direction' => 'OUTBOUND',
            'provider' => 'TELNYX',
            'provider_message_id' => 'msg_status_1',
            'from_address' => '+18883426998',
            'to_address' => '+12025550188',
            'body_text' => 'Status test',
            'status' => 'SENT',
            'thread_id' => $thread->id,
            'sent_at' => now(),
        ]);

        $payload = [
            'data' => [
                'id' => 'evt_status_1',
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'msg_status_1',
                    'to' => [['phone_number' => '+12025550188', 'status' => 'delivered']],
                ],
            ],
        ];

        $response = $this->postSignedTelnyx('/api/webhooks/telnyx/messaging', $payload);
        $response->assertOk();

        $message->refresh();
        $this->assertSame('DELIVERED', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_invalid_telnyx_signature_is_rejected(): void
    {
        $this->skipIfNoSodium();
        $this->createDefaultSmsNumber();

        $payload = [
            'data' => [
                'id' => 'evt_bad',
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg_bad',
                    'from' => ['phone_number' => '+12025550177'],
                    'to' => [['phone_number' => '+18883426998']],
                    'text' => 'should not pass',
                ],
            ],
        ];

        $body = json_encode($payload);
        $timestamp = (string) time();
        // Sign with the wrong key (zero bytes) so the verification fails.
        $wrongSig = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));

        $response = $this->call(
            'POST',
            '/api/webhooks/telnyx/messaging',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_TELNYX_SIGNATURE_ED25519' => $wrongSig,
                'HTTP_TELNYX_TIMESTAMP' => $timestamp,
            ],
            $body
        );

        $response->assertStatus(403);
        $this->assertSame(0, Message::where('provider_message_id', 'msg_bad')->count());
    }

    public function test_stale_telnyx_timestamp_is_rejected(): void
    {
        $this->skipIfNoSodium();
        $payload = [
            'data' => [
                'id' => 'evt_stale',
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg_stale',
                    'from' => ['phone_number' => '+12025550177'],
                    'to' => [['phone_number' => '+18883426998']],
                    'text' => 'too late',
                ],
            ],
        ];

        $response = $this->postSignedTelnyx('/api/webhooks/telnyx/messaging', $payload, time() - 3600);
        $response->assertStatus(403);
    }

    private function bindThrowingTelnyxProviderMock(\Throwable $error): void
    {
        $mock = Mockery::mock(TelnyxSmsProvider::class);
        $mock->shouldReceive('send')->once()->andThrow($error);

        $this->app->instance(TelnyxSmsProvider::class, $mock);
    }

    private function bindTelnyxProviderMock(string $messageId, ?int $times = 1): void    {
        $mock = Mockery::mock(TelnyxSmsProvider::class);
        $expectation = $mock->shouldReceive('send');

        if ($times === null) {
            $expectation->atLeast()->once();
        } else {
            $expectation->times($times);
        }

        $expectation->andReturn($messageId);

        $this->app->instance(TelnyxSmsProvider::class, $mock);
    }

    private function createDefaultSmsNumber(): SmsNumber
    {
        return SmsNumber::create([
            'provider' => 'TELNYX',
            'phone_number' => '+18883426998',
            'label' => 'Telnyx Toll-Free',
            'telnyx_phone_number_id' => 'pn-uuid-test',
            'messaging_profile_id' => 'mp-uuid-test',
            'owner_type' => 'GLOBAL',
            'is_default' => true,
        ]);
    }

    private function createSmsThread(): MessageThread
    {
        $contact = Contact::create([
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

    private function postSignedTelnyx(string $uri, array $payload, ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $ts = (string) ($timestamp ?? time());
        $signedPayload = $ts . '|' . $body;

        $secret = base64_decode($this->secretKeyB64, true);
        $signature = base64_encode(sodium_crypto_sign_detached($signedPayload, $secret));

        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
                'HTTP_TELNYX_TIMESTAMP' => $ts,
            ],
            $body
        );
    }
}
