<?php

namespace Tests\Unit;

use App\Services\Dropbox\DropboxWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DropboxWebhookHandlerTest extends TestCase
{
    private const SECRET = 'dropbox-test-app-secret';
    private const BODY = '{"list_folder":{"accounts":["dbid:test_account-1"]},"delta":{"users":[12345678]}}';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dropbox.client_secret', self::SECRET);
        Http::fake();
        Bus::fake();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        parent::tearDown();
    }

    public function test_public_get_echoes_challenge_as_plain_text_without_config_or_processing(): void
    {
        config()->set('services.dropbox.client_secret', null);
        $challenge = '<script>alert("test")</script>';
        $request = Request::create('/api/dropbox/webhook', 'GET', ['challenge' => $challenge]);

        $response = app(DropboxWebhookHandler::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($challenge, $response->getContent());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    #[DataProvider('invalidChallenges')]
    public function test_rejects_missing_array_empty_oversized_and_control_character_challenges(mixed $challenge): void
    {
        $response = app(DropboxWebhookHandler::class)->handle(
            Request::create('/api/dropbox/webhook', 'GET', ['challenge' => $challenge]),
        );

        $this->assertSame(400, $response->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    public static function invalidChallenges(): array
    {
        return [
            'missing' => [null],
            'array' => [['unexpected']],
            'empty' => [''],
            'oversized' => [str_repeat('a', 1025)],
            'newline' => ["test\r\nvalue"],
            'nul' => ["test\0value"],
        ];
    }

    #[DataProvider('emptySecrets')]
    public function test_empty_configuration_fails_closed_even_with_a_signature(mixed $secret): void
    {
        config()->set('services.dropbox.client_secret', $secret);

        $response = $this->deliver(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('300', $response->headers->get('Retry-After'));
        Log::shouldHaveReceived('error')->once()->with('Dropbox webhook authentication is not configured.');
        Log::shouldNotHaveReceived('info');
    }

    public static function emptySecrets(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => ['   ']];
    }

    #[DataProvider('invalidSignatures')]
    public function test_rejects_missing_wrong_or_malformed_signature(?string $signature): void
    {
        $this->assertSame(403, $this->deliver(self::BODY, $signature)->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    public static function invalidSignatures(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'wrong' => [str_repeat('0', 64)],
            'nonhex' => [str_repeat('z', 64)],
            'prefixed' => ['sha256='.hash_hmac('sha256', self::BODY, self::SECRET)],
            'newline' => [hash_hmac('sha256', self::BODY, self::SECRET)."\n"],
        ];
    }

    public function test_authentication_uses_exact_body_bytes_and_does_not_accept_reserialized_signature(): void
    {
        $body = " { \"list_folder\" : { \"accounts\" : [\"dbid:test\"] } }\n";
        $reserialized = json_encode(json_decode($body));
        $this->assertSame(403, $this->deliver($body, hash_hmac('sha256', $reserialized, self::SECRET))->getStatusCode());
        $this->assertSame(200, $this->deliver($body, hash_hmac('sha256', $body, self::SECRET))->getStatusCode());
        Log::shouldHaveReceived('info')->once()->with('Dropbox webhook notification accepted.', ['account_count' => 1]);
    }

    public function test_post_challenge_or_body_token_cannot_bypass_signature(): void
    {
        $body = json_encode(['challenge' => 'test', 'token' => self::SECRET, 'list_folder' => ['accounts' => ['dbid:test']]]);
        $this->assertSame(403, $this->deliver($body)->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    #[DataProvider('invalidBodies')]
    public function test_rejects_signed_invalid_json_and_malformed_notification_shapes(string $body): void
    {
        $this->assertSame(400, $this->deliver($body, hash_hmac('sha256', $body, self::SECRET))->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    public static function invalidBodies(): array
    {
        return [
            'empty' => [''],
            'invalid json' => ['{'],
            'scalar' => ['123'],
            'null' => ['null'],
            'top-level array' => ['[]'],
            'missing list' => ['{}'],
            'list object required' => ['{"list_folder":[]}'],
            'missing accounts' => ['{"list_folder":{}}'],
            'accounts object' => ['{"list_folder":{"accounts":{}}}'],
            'accounts string' => ['{"list_folder":{"accounts":"dbid:test"}}'],
            'account array' => ['{"list_folder":{"accounts":[[]]}}'],
            'account numeric' => ['{"list_folder":{"accounts":[123]}}'],
            'account malformed' => ['{"list_folder":{"accounts":["dbid:test\\nvalue"]}}'],
            'legacy users malformed' => ['{"list_folder":{"accounts":[]},"delta":{"users":[{}]}}'],
            'legacy users object' => ['{"list_folder":{"accounts":[]},"delta":{"users":{}}}'],
        ];
    }

    public function test_rejects_oversized_body_even_without_declared_length(): void
    {
        $body = str_repeat(' ', 1_048_577);
        $this->assertSame(413, $this->deliver($body, hash_hmac('sha256', $body, self::SECRET))->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    public function test_rejects_oversized_declared_length_before_processing(): void
    {
        $request = $this->webhookRequest(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET));
        $request->headers->set('Content-Length', '1048577');
        $this->assertSame(413, app(DropboxWebhookHandler::class)->handle($request)->getStatusCode());
        Log::shouldNotHaveReceived('info');
    }

    public function test_signed_repeated_notifications_are_acknowledged_without_logging_payload_or_ids(): void
    {
        $signature = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertSame(200, $this->deliver(self::BODY, $signature)->getStatusCode());
        $this->assertSame(200, $this->deliver(self::BODY, $signature)->getStatusCode());

        Log::shouldHaveReceived('info')->twice()->with('Dropbox webhook notification accepted.', ['account_count' => 1]);
        Log::shouldNotHaveReceived('error');
    }

    public function test_signed_empty_account_list_is_a_valid_noop(): void
    {
        $body = '{"list_folder":{"accounts":[]}}';
        $this->assertSame(200, $this->deliver($body, hash_hmac('sha256', $body, self::SECRET))->getStatusCode());
        Log::shouldHaveReceived('info')->once()->with('Dropbox webhook notification accepted.', ['account_count' => 0]);
    }

    public function test_other_methods_cannot_process_a_notification(): void
    {
        $request = Request::create('/api/dropbox/webhook', 'PUT', [], [], [], [
            'HTTP_X_DROPBOX_SIGNATURE' => hash_hmac('sha256', self::BODY, self::SECRET),
        ], self::BODY);
        $response = app(DropboxWebhookHandler::class)->handle($request);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->headers->get('Allow'));
        Log::shouldNotHaveReceived('info');
    }

    private function deliver(string $body, ?string $signature = null): Response
    {
        return app(DropboxWebhookHandler::class)->handle($this->webhookRequest($body, $signature));
    }

    private function webhookRequest(string $body, ?string $signature = null): Request
    {
        $request = Request::create('/api/dropbox/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        if ($signature !== null) {
            $request->headers->set('X-Dropbox-Signature', $signature);
        }

        return $request;
    }
}
