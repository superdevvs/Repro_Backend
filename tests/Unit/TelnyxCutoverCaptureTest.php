<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TelnyxCutoverCapture;
use App\Support\TelnyxCutoverInbox;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class TelnyxCutoverCaptureTest extends TestCase
{
    private const MESSAGING = '/api/webhooks/telnyx/messaging';

    private static function request(string $uri = self::MESSAGING, string $method = 'POST', mixed $body = '{}'): Request
    {
        return Request::create($uri, $method, server: [
            'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(str_repeat('s', 64)),
            'HTTP_TELNYX_TIMESTAMP' => '1795320000',
        ], content: $body);
    }

    public static function destinations(): array
    {
        return [
            [self::MESSAGING, 'messaging'],
            ['/api/webhooks/telnyx/status', 'status'],
            ['/api/webhooks/telnyx/voice', 'voice'],
            ['/api/webhooks/telnyx/shared-mms/01234567-89AB-CDEF-0123-456789ABCDEF', 'shared-mms:01234567-89ab-cdef-0123-456789abcdef'],
        ];
    }

    #[DataProvider('destinations')]
    public function test_exact_routes_pass_original_signed_bytes_and_confirm_the_same_receipt(string $path, string $destination): void
    {
        $body = "{\r\n  \"data\": {\"id\": \"fixture-event\"}\r\n}\n";
        $request = self::request($path, body: $body);
        $request->headers->set('Content-Length', (string) strlen($body));
        $receiptId = str_repeat('a', 64);
        $stored = false;
        $inbox = $this->createMock(TelnyxCutoverInbox::class);
        $inbox->expects($this->once())->method('store')
            ->with($destination, $body, $request->headers->get('Telnyx-Signature-Ed25519'), '1795320000')
            ->willReturnCallback(function () use (&$stored, $receiptId): array {
                $stored = true;

                return ['receipt_id' => $receiptId, 'duplicate' => false];
            });
        $capture = new TelnyxCutoverCapture($inbox, function (string $receipt) use (&$stored, $receiptId): bool {
            $this->assertTrue($stored);
            $this->assertSame($receiptId, $receipt);

            return true;
        });

        $response = $capture->capture($request);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_other_targets_and_method_overrides_are_not_captured(): void
    {
        $inbox = $this->createMock(TelnyxCutoverInbox::class);
        $inbox->expects($this->never())->method('store');
        $capture = new TelnyxCutoverCapture($inbox, fn () => $this->fail('Unexpected durability check.'));
        foreach ([
            '/api/telnyx-ai/tools/update_call',
            '/api/webhooks/stripe',
            '/webhooks/telnyx/messaging',
            self::MESSAGING.'/',
            self::MESSAGING.'?extra=1',
            '/api/webhooks/telnyx/%6dessaging',
            '/api/webhooks/telnyx/shared-mms/not-a-uuid',
        ] as $path) {
            $request = self::request($path);
            $request->headers->set('X-Original-URL', self::MESSAGING);
            $this->assertSame(404, $capture->capture($request)->getStatusCode(), $path);
        }
        $request = self::request(method: 'PUT');
        $request->headers->set('X-HTTP-Method-Override', 'POST');
        $response = $capture->capture($request);
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->headers->get('Allow'));
    }

    public function test_ambiguous_headers_and_declared_body_mismatch_never_reach_the_inbox(): void
    {
        $inbox = $this->createMock(TelnyxCutoverInbox::class);
        $inbox->expects($this->never())->method('store');
        $capture = new TelnyxCutoverCapture($inbox, fn () => $this->fail('Unexpected durability check.'));
        $signature = base64_encode(str_repeat('s', 64));
        foreach ([
            ['Telnyx-Signature-Ed25519', null],
            ['Telnyx-Signature-Ed25519', [$signature, $signature]],
            ['Telnyx-Signature-Ed25519', $signature.','.$signature],
            ['Telnyx-Timestamp', ['1795320000', '1795320000']],
            ['Telnyx-Timestamp', '1795320000, 1795320001'],
            ['Content-Length', '-3'],
            ['Content-Length', ['2', '2']],
            ['Content-Length', '3'],
        ] as [$header, $value]) {
            $request = self::request();
            $request->headers->set($header, $value);
            $response = $capture->capture($request);
            $this->assertSame(400, $response->getStatusCode(), $header);
            $this->assertSame('Capture refused.', $response->getContent());
        }
    }

    public function test_oversize_is_rejected_before_store_with_or_without_content_length(): void
    {
        $body = fopen('php://temp', 'w+b');
        try {
            fwrite($body, str_repeat('a', TelnyxCutoverCapture::MAX_BODY_BYTES + 19));
            $inbox = $this->createMock(TelnyxCutoverInbox::class);
            $inbox->expects($this->never())->method('store');
            $capture = new TelnyxCutoverCapture($inbox, fn () => $this->fail('Unexpected durability check.'));

            rewind($body);
            $declared = self::request(body: $body);
            $declared->headers->set('Content-Length', (string) (TelnyxCutoverCapture::MAX_BODY_BYTES + 19));
            $this->assertSame(413, $capture->capture($declared)->getStatusCode());
            $this->assertSame(0, ftell($body));

            $this->assertSame(413, $capture->capture(self::request(body: $body))->getStatusCode());
            $this->assertSame(TelnyxCutoverCapture::MAX_BODY_BYTES + 1, ftell($body));
        } finally {
            fclose($body);
        }
    }

    public function test_local_receipt_and_uncertain_protection_return_retryable_generic_failure(): void
    {
        $inbox = $this->createMock(TelnyxCutoverInbox::class);
        $inbox->expects($this->exactly(3))->method('store')
            ->willReturn(['receipt_id' => str_repeat('b', 64), 'duplicate' => true]);
        foreach ([
            fn () => false,
            fn () => 1,
            fn () => throw new RuntimeException('private protection details'),
        ] as $confirm) {
            $response = (new TelnyxCutoverCapture($inbox, $confirm))->capture(self::request());
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('Capture refused.', $response->getContent());
        }
    }

    public function test_inbox_error_never_reaches_the_protection_callback(): void
    {
        $called = false;
        $inbox = $this->createMock(TelnyxCutoverInbox::class);
        $inbox->expects($this->once())->method('store')->willThrowException(new RuntimeException('private inbox error'));
        $capture = new TelnyxCutoverCapture($inbox, function () use (&$called): bool {
            $called = true;

            return true;
        });
        $response = $capture->capture(self::request());
        $this->assertFalse($called);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Capture refused.', $response->getContent());
    }

    public function test_signed_retry_waits_for_explicit_confirmation_of_a_real_local_receipt(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The durable inbox requires Linux.');
        }
        $directory = realpath(sys_get_temp_dir()).'/telnyx-capture-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $keys = sodium_crypto_sign_keypair();
            $inbox = new TelnyxCutoverInbox(
                $directory,
                new Encrypter(random_bytes(32), 'AES-256-GCM'),
                base64_encode(sodium_crypto_sign_publickey($keys)),
            );
            $body = "{\n \"data\":{\"id\":\"signed-fixture\"}\n}";
            $timestamp = (string) time();
            $signature = base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, sodium_crypto_sign_secretkey($keys)));
            $request = self::request(body: $body);
            $request->headers->set('Telnyx-Timestamp', $timestamp);
            $request->headers->set('Telnyx-Signature-Ed25519', $signature);
            $confirmed = [];
            $capture = new TelnyxCutoverCapture($inbox, function (string $receipt) use ($inbox, $body, &$confirmed): bool {
                $this->assertSame($body, $inbox->open($receipt)['body']);
                $confirmed[] = $receipt;

                return count($confirmed) === 2;
            });

            $this->assertSame(503, $capture->capture($request)->getStatusCode());
            $this->assertSame(204, $capture->capture($request)->getStatusCode());
            $this->assertSame($confirmed[0], $confirmed[1]);
            $this->assertCount(1, glob($directory.'/*.sealed'));
            $request->headers->set('Telnyx-Signature-Ed25519', base64_encode(str_repeat('x', 64)));
            $this->assertSame(503, $capture->capture($request)->getStatusCode());
            $this->assertCount(2, $confirmed);
        } finally {
            foreach (array_diff(scandir($directory), ['.', '..']) as $file) {
                unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }
}
