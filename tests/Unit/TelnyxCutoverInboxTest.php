<?php

namespace Tests\Unit;

use App\Support\TelnyxCutoverInbox;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelnyxCutoverInboxTest extends TestCase
{
    private string $directory;
    private string $publicKey;
    private string $secretKey;
    private Encrypter $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cutover-inbox-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $keys = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keys));
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->cipher = new Encrypter(random_bytes(32), 'AES-256-GCM');
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0700);
        foreach (array_diff(scandir($this->directory), ['.', '..']) as $file) unlink($this->directory.'/'.$file);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function inbox(): TelnyxCutoverInbox
    {
        return new TelnyxCutoverInbox($this->directory, $this->cipher, $this->publicKey);
    }

    private function request(?string $body = null, ?string $timestamp = null, string $destination = 'messaging'): array
    {
        $body ??= "{\n  \"data\": {\"id\":\"event-fixture-1\",\"event_type\":\"message.received\",\"payload\":{\"text\":\"private fixture content\"}}\n}";
        $timestamp ??= (string) time();

        return [$destination, $body, base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $this->secretKey)), $timestamp];
    }

    private function failure(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            return $error;
        }
        $this->fail('Failed or invalid persistence returned a receipt.');
    }

    public function test_exact_signed_bytes_are_encrypted_and_identical_retries_share_one_receipt(): void
    {
        $inbox = $this->inbox();
        $request = $this->request();
        $first = $inbox->store(...$request);
        $this->assertFalse($first['duplicate']);
        $second = $inbox->store(...$request);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $files = glob($this->directory.'/*.sealed');
        $this->assertCount(1, $files);
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $this->assertStringNotContainsString('private fixture content', file_get_contents($files[0]));
        $this->assertStringNotContainsString('event-fixture-1', file_get_contents($files[0]));
        $receipt = $inbox->open($first['receipt_id']);
        $this->assertSame($request[1], $receipt['body']);
        $this->assertSame($request[2], $receipt['signature']);
        $this->assertSame($request[3], $receipt['timestamp']);
        $this->assertSame(hash('sha256', $request[1]), $receipt['body_sha256']);
    }

    public function test_different_signed_bodies_for_one_event_are_retained_without_silent_overwrite(): void
    {
        $inbox = $this->inbox();
        $first = $inbox->store(...$this->request());
        $different = '{"data":{"id":"event-fixture-1","event_type":"message.received","payload":{"text":"second body"}},"meta":{"attempt":2}}';
        $second = $inbox->store(...$this->request($different));
        $this->assertNotSame($first['receipt_id'], $second['receipt_id']);
        $this->assertCount(2, glob($this->directory.'/*.sealed'));
        $this->assertSame($different, $inbox->open($second['receipt_id'])['body']);
        $this->assertSame('event-fixture-1', $inbox->open($first['receipt_id'])['event_id']);
    }

    public function test_invalid_or_stale_requests_never_create_a_receipt(): void
    {
        $invalidSignature = $this->request(); $invalidSignature[2] = base64_encode(str_repeat('x', 64));
        $invalidTimestamp = $this->request(timestamp: time().'junk');
        $requests = [$invalidSignature, $invalidTimestamp, $this->request(timestamp: (string) (time() - 3600)),
            $this->request('{"data":{}}'), $this->request('{not json'), $this->request(destination: '../../escape')];
        foreach ($requests as $request) {
            $this->failure(fn () => $this->inbox()->store(...$request));
            $this->assertSame([], glob($this->directory.'/*.sealed'));
        }
        $this->assertSame(['.', '..'], scandir($this->directory));
    }

    public function test_missing_verification_key_is_rejected_even_in_a_test_process(): void
    {
        $this->expectException(RuntimeException::class);
        new TelnyxCutoverInbox($this->directory, $this->cipher, '');
    }

    public function test_public_or_symlinked_storage_is_rejected(): void
    {
        chmod($this->directory, 0755);
        $error = $this->failure(fn () => $this->inbox());
        $this->assertStringContainsString('private directory', $error->getMessage());
        chmod($this->directory, 0700);
        symlink($this->directory, $this->directory.'/alias');
        $this->expectException(RuntimeException::class);
        new TelnyxCutoverInbox($this->directory.'/alias', $this->cipher, $this->publicKey);
    }

    public function test_partial_write_and_file_sync_failures_never_return_success(): void
    {
        foreach (['write', 'sync'] as $failure) {
            $inbox = new class($this->directory, $this->cipher, $this->publicKey) extends TelnyxCutoverInbox {
                public string $failure;
                protected function writeAll($stream, string $bytes): void
                {
                    parent::writeAll($stream, $this->failure === 'write' ? substr($bytes, 0, 10) : $bytes);
                    if ($this->failure === 'write') throw new RuntimeException('Synthetic short write.');
                }
                protected function syncFile($stream): void { throw new RuntimeException('Synthetic fsync failure.'); }
            };
            $inbox->failure = $failure;
            $error = $this->failure(fn () => $inbox->store(...$this->request()));
            $this->assertStringStartsWith('Synthetic', $error->getMessage());
            $this->assertSame([], glob($this->directory.'/*.sealed'));
            $this->assertSame([], glob($this->directory.'/.pending-*'));
        }
    }

    public function test_uncertain_directory_sync_does_not_ack_and_a_retry_verifies_the_existing_receipt(): void
    {
        $inbox = new class($this->directory, $this->cipher, $this->publicKey) extends TelnyxCutoverInbox {
            protected function syncDirectory(): void { throw new RuntimeException('Synthetic directory fsync failure.'); }
        };
        $request = $this->request();
        $error = $this->failure(fn () => $inbox->store(...$request));
        $this->assertSame('Synthetic directory fsync failure.', $error->getMessage());
        $this->assertCount(1, glob($this->directory.'/*.sealed'));
        $retry = $this->inbox()->store(...$request);
        $this->assertTrue($retry['duplicate']);
        $this->assertSame($request[1], $this->inbox()->open($retry['receipt_id'])['body']);
    }

    public function test_tampered_receipt_is_neither_acknowledged_nor_overwritten(): void
    {
        $inbox = $this->inbox(); $request = $this->request();
        $receipt = $inbox->store(...$request);
        $path = $this->directory.'/'.$receipt['receipt_id'].'.sealed';
        file_put_contents($path, 'corrupted');
        $error = $this->failure(fn () => $inbox->store(...$request));
        $this->assertSame('Inbox receipt authentication failed.', $error->getMessage());
        $this->assertSame('corrupted', file_get_contents($path));
        $this->expectException(RuntimeException::class);
        $inbox->open('../escape');
    }
}
