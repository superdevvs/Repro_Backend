<?php

namespace Tests\Unit\Messaging;

use App\Models\MessageChannel;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $provider = new CakemailProvider;
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
                && $payload['content']['type'] === 'transactional'
                && $payload['content']['text'] === "Line one\nLine two"
                && str_contains((string) $payload['content']['html'], 'Line one')
                && str_contains((string) $payload['content']['html'], '<br')
                && ! str_contains((string) $payload['content']['html'], '[CLIENT.ADDRESS]');
        });
    }

    #[DataProvider('legacyAddressMarkerCases')]
    public function test_send_removes_only_standalone_legacy_address_markers(string $html, ?string $text, string $expectedHtml, string $expectedText): void
    {
        config([
            'services.cakemail.username' => 'mailer@example.com',
            'services.cakemail.password' => 'synthetic-password',
            'services.cakemail.sender_id' => 'sender-default',
            'services.cakemail.list_id' => 8651530,
            'services.cakemail.base_url' => 'https://cakemail.example/api',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://cakemail.example/api/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://cakemail.example/api/v2/emails' => Http::response(['data' => ['id' => 'msg-clean-footer']]),
        ]);
        $provider = new CakemailProvider;
        $provider->clearCache();
        $channel = new MessageChannel(['type' => 'EMAIL', 'provider' => 'CAKEMAIL', 'display_name' => 'Default Mailer']);

        $this->assertSame('msg-clean-footer', $provider->send($channel, [
            'to' => 'recipient@example.com', 'subject' => 'A booking update',
            'body_html' => $html, 'body_text' => $text,
        ]));
        Http::assertSent(function (Request $request) use ($expectedHtml, $expectedText): bool {
            if ($request->url() !== 'https://cakemail.example/api/v2/emails') {
                return false;
            }
            $content = $request['content'];

            return $content['html'] === $expectedHtml
                && $content['text'] === $expectedText
                && $content['type'] === 'transactional'
                && $request['list_id'] === 8651530
                && $request['tracking'] === ['opens' => true, 'clicks_html' => true, 'clicks_text' => true];
        });
    }

    public static function legacyAddressMarkerCases(): array
    {
        $body = '<p>Your booking is confirmed.</p><footer>123 Example Lane <a href="https://example.com/preferences">Preferences</a></footer>';
        $plain = 'Your booking is confirmed. 123 Example Lane Preferences';
        $marker = '<div style="margin-top:8px; text-align:center; color:#7f8fa3; font-size:11px; line-height:1.6;">[CLIENT.ADDRESS]</div>';

        return [
            'supported provider tags replace the styled footer address exactly once' => [
                '<div data-email-provider-footer="true" class="dark-muted" style="color:#65758b;font-size:11px;line-height:19px;"><p>Old configured address</p></div>',
                'Plain message',
                '<div data-email-provider-footer="true" class="dark-muted" style="color:#65758b;font-size:11px;line-height:19px;"><p style="margin:12px 0 0;">[CLIENTS.ADDRESS]</p><p style="margin:8px 0 0;"><a class="dark-muted" href="[GLOBAL_UNSUBSCRIBE]" style="color:inherit;text-decoration:underline;">Unsubscribe</a></p></div>',
                'Plain message',
            ],
            'supported tags in custom templates are preserved' => [
                '<p>[CLIENTS.ADDRESS]</p><a href="[GLOBAL_UNSUBSCRIBE]">Unsubscribe</a>',
                'Plain message',
                '<p>[CLIENTS.ADDRESS]</p><a href="[GLOBAL_UNSUBSCRIBE]">Unsubscribe</a>',
                'Plain message',
            ],
            'old appended transport div and text line' => [
                '<html><body>'.$body.$marker.'</body></html>', $plain."\n\n[CLIENT.ADDRESS]",
                '<html><body>'.$body.'</body></html>', $plain,
            ],
            'standalone paragraph and CRLF text line' => [
                $body.'<p> [CLIENT.ADDRESS] </p>', $plain."\r\n [CLIENT.ADDRESS] \r\n",
                $body, $plain,
            ],
            'plain text fallback uses cleaned HTML' => [
                '<p>Booking ready</p>'.$marker, null, '<p>Booking ready</p>', 'Booking ready',
            ],
            'bare trailing marker after an HTML block' => [
                '<p>Booking ready</p> [CLIENT.ADDRESS]', "Booking ready\n[CLIENT.ADDRESS]", '<p>Booking ready</p>', 'Booking ready',
            ],
            'authored sentence and real address remain unchanged' => [
                '<p>The literal shortcode [CLIENT.ADDRESS] is documented here.</p>'.$body,
                'The literal shortcode [CLIENT.ADDRESS] is documented here. '.$plain,
                '<p>The literal shortcode [CLIENT.ADDRESS] is documented here.</p>'.$body,
                'The literal shortcode [CLIENT.ADDRESS] is documented here. '.$plain,
            ],
        ];
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

        $provider = new CakemailProvider;
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

        $provider = new CakemailProvider;
        $provider->clearCache();

        $result = $provider->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Cakemail base URL is not configured. Set CAKEMAIL_BASE_URL before sending transactional email.',
            $result['error']
        );
    }
}
