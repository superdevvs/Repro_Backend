<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Messaging\TelnyxWebhookController;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Services\Messaging\SharedMmsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class TelnyxWebhookArrivalTimeTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $arrivedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrivedAt = CarbonImmutable::parse('2026-11-25 15:00:05', 'UTC');
        // The replay runs minutes after Telnyx delivered the text.
        Carbon::setTestNow('2026-11-25 15:09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_replayed_inbound_text_is_stored_at_its_arrival_time(): void
    {
        $response = $this->controller()->replayVerified('messaging', null, [
            'data' => [
                'id' => 'evt_arrival_inbound', 'event_type' => 'message.received',
                'payload' => [
                    'id' => 'msg_arrival_inbound',
                    'from' => ['phone_number' => '+12025550171'],
                    'to' => [['phone_number' => '+18883426998']],
                    'text' => 'Running ten minutes late',
                ],
            ],
        ], $this->arrivedAt);

        $this->assertSame('received', $response->getData(true)['status']);
        $message = Message::where('provider_message_id', 'msg_arrival_inbound')->firstOrFail();
        $this->assertSame($this->arrivedAt->getTimestamp(), $message->sent_at->getTimestamp());
        $this->assertSame($this->arrivedAt->getTimestamp(), $message->delivered_at->getTimestamp());
        $this->assertSame($this->arrivedAt->getTimestamp(), $message->thread->fresh()->last_message_at->getTimestamp());
    }

    public function test_a_replayed_older_text_never_moves_the_thread_back(): void
    {
        // A newer text reached the thread first (live after `up`, or in an
        // earlier replay); the older one is replayed afterwards.
        $newer = $this->arrivedAt->addMinutes(4);
        $this->controller()->replayVerified('messaging', null, $this->inboundPayload('evt_thread_newer', 'msg_thread_newer', 'Newer text'), $newer);

        $this->controller()->replayVerified('messaging', null, $this->inboundPayload('evt_thread_older', 'msg_thread_older', 'Older text'), $this->arrivedAt);

        $older = Message::where('provider_message_id', 'msg_thread_older')->sole();
        $this->assertSame($this->arrivedAt->getTimestamp(), $older->sent_at->getTimestamp());
        $thread = $older->thread->fresh();
        $this->assertSame(2, $thread->messages()->count());
        $this->assertSame($newer->getTimestamp(), $thread->last_message_at->getTimestamp());
        $this->assertSame('Newer text', $thread->last_snippet);
    }

    public function test_replayed_delivery_status_is_stored_at_its_arrival_time(): void
    {
        $message = $this->outboundMessage('msg_arrival_outbound');

        $response = $this->controller()->replayVerified('messaging', null, [
            'data' => [
                'id' => 'evt_arrival_status', 'event_type' => 'message.finalized',
                'payload' => ['id' => 'msg_arrival_outbound', 'to' => [['phone_number' => '+12025550172', 'status' => 'delivered']]],
            ],
        ], $this->arrivedAt);

        $this->assertSame('updated', $response->getData(true)['status']);
        $message->refresh();
        $this->assertSame('DELIVERED', $message->status);
        $this->assertSame($this->arrivedAt->getTimestamp(), $message->delivered_at->getTimestamp());
    }

    public function test_status_route_replays_sent_events_at_their_arrival_time(): void
    {
        $message = $this->outboundMessage('msg_arrival_sent', 'QUEUED');

        $this->controller()->replayVerified('status', null, [
            'data' => ['id' => 'evt_arrival_sent', 'event_type' => 'message.sent', 'payload' => ['id' => 'msg_arrival_sent']],
        ], $this->arrivedAt);

        $message->refresh();
        $this->assertSame('SENT', $message->status);
        $this->assertSame($this->arrivedAt->getTimestamp(), $message->sent_at->getTimestamp());
    }

    public function test_shared_mms_replay_uses_the_message_named_in_the_original_path(): void
    {
        $payload = ['data' => ['id' => 'evt_shared', 'event_type' => 'message.finalized', 'payload' => ['id' => 'grp_1']]];
        $shared = Mockery::mock(SharedMmsService::class);
        $shared->shouldReceive('webhook')->once()
            ->with($payload, '0b6f6a8e-6f0d-4c1e-9c43-0f6b8d1f2a11')
            ->andReturn(['status' => 'updated']);
        $this->app->instance(SharedMmsService::class, $shared);

        $response = $this->controller()->replayVerified('shared-mms', '0b6f6a8e-6f0d-4c1e-9c43-0f6b8d1f2a11', $payload, $this->arrivedAt);

        $this->assertSame(['status' => 'updated'], $response->getData(true));
    }

    public function test_unknown_routes_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->controller()->replayVerified('voice', null, ['data' => ['id' => 'evt_voice']], $this->arrivedAt);
    }

    /** @return array<string, mixed> */
    private function inboundPayload(string $eventId, string $messageId, string $text): array
    {
        return ['data' => ['id' => $eventId, 'event_type' => 'message.received', 'payload' => [
            'id' => $messageId, 'from' => ['phone_number' => '+12025550171'],
            'to' => [['phone_number' => '+18883426998']], 'text' => $text,
        ]]];
    }

    private function controller(): TelnyxWebhookController
    {
        return $this->app->make(TelnyxWebhookController::class);
    }

    private function outboundMessage(string $providerId, string $status = 'SENT'): Message
    {
        $contact = Contact::create(['name' => 'Arrival fixture', 'phone' => '+12025550172', 'type' => 'other']);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'last_message_at' => now()]);

        return Message::create([
            'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'TELNYX',
            'provider_message_id' => $providerId, 'from_address' => '+18883426998', 'to_address' => '+12025550172',
            'body_text' => 'Arrival fixture', 'status' => $status, 'thread_id' => $thread->id,
        ]);
    }
}
