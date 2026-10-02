<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_plJ3nmyCDGBKInavdOK15jsl';

    private function message(): Message
    {
        $message = app(MessagingService::class)->scheduleEmail(['to' => 'client@example.com', 'subject' => 'Test', 'body_text' => 'Test'], now());
        $message->update(['provider' => 'RESEND', 'provider_message_id' => 'resend-123', 'status' => 'SENT']);

        return $message;
    }

    private function webhook(array $payload, string $id = 'msg-test', ?int $timestamp = null)
    {
        config(['services.resend.webhook_secret' => self::SECRET]);
        $raw = json_encode($payload);
        $time = (string) ($timestamp ?? now()->timestamp);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$time.'.'.$raw, base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $time, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
        ], $raw);
    }

    public function test_unconfigured_and_unsigned_webhooks_fail_closed(): void
    {
        config(['services.resend.webhook_secret' => null]);
        $this->postJson('/api/webhooks/resend', [])->assertStatus(503);
        config(['services.resend.webhook_secret' => self::SECRET]);
        $this->postJson('/api/webhooks/resend', [])->assertStatus(401);
    }

    public function test_stale_or_future_signatures_are_rejected(): void
    {
        $this->freezeTime();
        foreach ([now()->timestamp - 301, now()->timestamp + 301] as $time) {
            $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => 'resend-123']], timestamp: $time)->assertStatus(401);
        }
    }

    public function test_signature_matches_the_published_svix_test_vector(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp(1731705121));
        config(['services.resend.webhook_secret' => self::SECRET]);
        $this->call('POST', '/api/webhooks/resend', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => 'msg_loFOjxBNrRLzqYUf',
            'HTTP_SVIX_TIMESTAMP' => '1731705121',
            'HTTP_SVIX_SIGNATURE' => 'v1,rAvfW3dJ/X/qxhsaXPOyyCGmRKsaKWcsNccKXlIktD0=',
        ], '{"event_type":"ping","data":{"success":true}}')->assertOk()->assertJsonPath('status', 'ignored');
    }

    public function test_valid_delivery_updates_message_and_duplicates_are_idempotent(): void
    {
        $message = $this->message();
        $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 'resend-123']];
        $this->webhook($payload)->assertOk();
        $this->assertSame('DELIVERED', $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);
        $this->webhook($payload)->assertOk();
        $this->assertCount(1, $message->refresh()->metadata['resend_webhook_event_ids']);
    }

    public function test_open_replay_does_not_inflate_count(): void
    {
        $message = $this->message();
        $payload = ['type' => 'email.opened', 'data' => ['email_id' => 'resend-123']];
        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();
        $this->assertSame(1, $message->refresh()->metadata['open_count']);
        $this->assertCount(1, $message->metadata['email_activity']);
    }

    public function test_click_timeline_uses_event_time_and_redacts_sensitive_link_parameters(): void
    {
        $message = $this->message();
        $at = now()->subMinute()->toIso8601String();
        $this->webhook(['type' => 'email.clicked', 'created_at' => $at, 'data' => [
            'email_id' => 'resend-123', 'click' => ['link' => 'https://example.com/booking?token=secret'],
        ]])->assertOk();
        $event = $message->refresh()->metadata['email_activity'][0];
        $this->assertSame('clicked', $event['type']);
        $this->assertSame($at, $event['at']);
        $this->assertSame('https://example.com/booking', $event['link']);
    }

    public function test_bounce_wins_over_late_delivery_and_does_not_resend(): void
    {
        $message = $this->message();
        $this->webhook(['type' => 'email.bounced', 'data' => ['email_id' => 'resend-123', 'bounce' => ['message' => 'Mailbox does not exist', 'type' => 'Permanent']]], 'msg-bounce')->assertOk();
        $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => 'resend-123']], 'msg-late')->assertOk();
        $this->assertSame('BOUNCED', $message->refresh()->status);
        $this->assertSame('Mailbox does not exist', $message->error_message);
    }

    public function test_cakemail_message_cannot_be_changed_by_resend_webhook(): void
    {
        $message = $this->message();
        $message->update(['provider' => 'CAKEMAIL']);
        $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => 'resend-123']])->assertStatus(503);
        $this->assertSame('SENT', $message->refresh()->status);
    }

    public function test_missing_message_returns_retryable_status(): void
    {
        $this->webhook(['type' => 'email.delivered', 'data' => ['email_id' => 'not-yet-persisted']])->assertStatus(503);
    }
}
