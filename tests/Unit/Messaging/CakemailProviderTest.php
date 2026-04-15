<?php

namespace Tests\Unit\Messaging;

use App\Models\MessageChannel;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CakemailProviderTest extends TestCase
{
    public function test_send_uses_configured_base_url_and_builds_html_from_text_when_missing(): void
    {
        config([
            'services.cakemail.username' => 'mailer@example.com',
            'services.cakemail.password' => 'secret-password',
            'services.cakemail.sender_id' => 'sender-default',
            'services.cakemail.list_id' => 8651530,
            'services.cakemail.base_url' => 'https://cakemail.example/api',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://cakemail.example/api/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ], 200),
            'https://cakemail.example/api/v2/emails' => Http::response([
                'data' => [
                    'id' => 'msg-123',
                    'status' => 'queued',
                ],
            ], 200),
        ]);

        $provider = new CakemailProvider();
        $provider->clearCache();

        $channel = new MessageChannel([
            'type' => 'EMAIL',
            'provider' => 'CAKEMAIL',
            'display_name' => 'Default Mailer',
            'from_email' => 'mailer@example.com',
            'config_json' => null,
        ]);

        $messageId = $provider->send($channel, [
            'to' => 'recipient@example.com',
            'subject' => 'Shoot update',
            'text' => "Line one\nLine two",
            'html' => '',
        ]);

        $this->assertSame('msg-123', $messageId);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://cakemail.example/api/token'
                && $request['username'] === 'mailer@example.com'
                && $request['password'] === 'secret-password';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://cakemail.example/api/v2/emails') {
                return false;
            }

            $payload = $request->data();

            return $payload['sender']['id'] === 'sender-default'
                && $payload['list_id'] === 8651530
                && $payload['content']['text'] === "Line one\nLine two"
                && str_contains((string) $payload['content']['html'], 'Line one')
                && str_contains((string) $payload['content']['html'], '<br');
        });
    }

    public function test_send_requires_an_explicit_cakemail_base_url(): void
    {
        config([
            'services.cakemail.username' => 'mailer@example.com',
            'services.cakemail.password' => 'secret-password',
            'services.cakemail.sender_id' => 'sender-default',
            'services.cakemail.list_id' => 8651530,
            'services.cakemail.base_url' => null,
        ]);

        $provider = new CakemailProvider();
        $provider->clearCache();

        $channel = new MessageChannel([
            'type' => 'EMAIL',
            'provider' => 'CAKEMAIL',
            'display_name' => 'Default Mailer',
            'from_email' => 'mailer@example.com',
            'config_json' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cakemail base URL is not configured. Set CAKEMAIL_BASE_URL before sending transactional email.');

        $provider->send($channel, [
            'to' => 'recipient@example.com',
            'subject' => 'Shoot update',
            'text' => "Line one\nLine two",
            'html' => '',
        ]);
    }

    public function test_connection_returns_an_actionable_error_when_base_url_is_missing(): void
    {
        config([
            'services.cakemail.username' => 'mailer@example.com',
            'services.cakemail.password' => 'secret-password',
            'services.cakemail.base_url' => null,
        ]);

        $provider = new CakemailProvider();
        $provider->clearCache();

        $result = $provider->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Cakemail base URL is not configured. Set CAKEMAIL_BASE_URL before sending transactional email.',
            $result['error']
        );
    }
}
