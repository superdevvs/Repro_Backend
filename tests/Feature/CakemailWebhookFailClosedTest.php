<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsInboundWebhooks;
use Tests\TestCase;

class CakemailWebhookFailClosedTest extends TestCase
{
    use RefreshDatabase;
    use SignsInboundWebhooks;

    private const SECRET = 'cakemail-test-webhook-secret';

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function bounceFixture(): array
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'bounce-target@example.test',
            'email_status' => 'verified',
        ]);

        Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'cakemail',
            'provider_message_id' => 'cm-fail-closed',
            'to_address' => $client->email,
            'subject' => 'Verify your email',
            'status' => 'SENT',
            'related_account_id' => $client->id,
        ]);

        $payload = [
            'event' => 'email.bounced',
            'data' => [
                'email_id' => 'cm-fail-closed',
                'email' => $client->email,
                'reason' => 'Mailbox unavailable',
                'bounce_type' => 'hard',
            ],
        ];

        return [$client, $payload];
    }

    public function test_missing_secret_rejects_webhook_and_does_not_process(): void
    {
        config()->set('services.cakemail.webhook_secret', '');

        [$client, $payload] = $this->bounceFixture();

        $this->postJson('/api/webhooks/cakemail', $payload)->assertStatus(503);

        $this->assertSame('verified', $client->fresh()->email_status);
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'cm-fail-closed',
            'status' => 'SENT',
        ]);
    }

    public function test_missing_signature_is_rejected_when_secret_is_configured(): void
    {
        config()->set('services.cakemail.webhook_secret', self::SECRET);

        [$client, $payload] = $this->bounceFixture();

        $this->postJson('/api/webhooks/cakemail', $payload)->assertStatus(401);

        $this->assertSame('verified', $client->fresh()->email_status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config()->set('services.cakemail.webhook_secret', self::SECRET);

        [$client, $payload] = $this->bounceFixture();

        $this->withHeader('X-Cakemail-Signature', 'deadbeef')
            ->postJson('/api/webhooks/cakemail', $payload)
            ->assertStatus(401);

        $this->assertSame('verified', $client->fresh()->email_status);
    }

    public function test_valid_signature_processes_the_bounce(): void
    {
        config()->set('services.cakemail.webhook_secret', self::SECRET);

        [$client, $payload] = $this->bounceFixture();

        $this->postJsonWithHmac(
            '/api/webhooks/cakemail',
            $payload,
            self::SECRET,
            'X-Cakemail-Signature',
        )->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('bounced', $client->fresh()->email_status);
    }

    public function test_vendor_base64_raw_body_signature_is_accepted(): void
    {
        config()->set('services.cakemail.webhook_secret', self::SECRET);

        $payload = [
            'event' => 'Email.Opened',
            'data' => [
                'email_id' => 'cm-vendor-format',
            ],
        ];
        $raw = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $raw, self::SECRET, true));

        $this->call(
            'POST',
            '/api/webhooks/cakemail',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CAKEMAIL_SIGNATURE' => $signature,
            ],
            $raw,
        )->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_vendor_reported_as_spam_event_marks_user_risky(): void
    {
        config()->set('services.cakemail.webhook_secret', self::SECRET);

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'spam-target@example.test',
            'email_status' => 'verified',
        ]);

        $payload = [
            'event' => 'Email.ReportedAsSpam',
            'data' => [
                'email_id' => 'cm-spam-report',
                'email_address' => $client->email,
            ],
        ];

        $this->postJsonWithHmac(
            '/api/webhooks/cakemail',
            $payload,
            self::SECRET,
            'X-Cakemail-Signature',
        )->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('risky', $client->fresh()->email_status);
    }
}
