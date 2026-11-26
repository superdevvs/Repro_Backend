<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TelnyxCutoverCapture;
use App\Support\TelnyxCutoverInbox;
use Composer\Autoload\ClassLoader;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class TelnyxCutoverReplayDrillTest extends TestCase
{
    public function test_private_replica_recovers_each_acknowledgement_and_projects_only_one_sms_effect(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Requires the Linux durable inbox.');
        }
        $root = realpath(sys_get_temp_dir()).'/telnyx-replay-drill-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        mkdir($root.'/local', 0700);
        mkdir($root.'/recovered', 0700);
        try {
            $key = random_bytes(32);
            $sign = sodium_crypto_sign_keypair();
            $publicKey = base64_encode(sodium_crypto_sign_publickey($sign));
            $secretKey = sodium_crypto_sign_secretkey($sign);
            $local = new TelnyxCutoverInbox($root.'/local', new Encrypter($key, 'AES-256-GCM'), $publicKey);
            $replica = new TelnyxCutoverInbox($root.'/recovered', new Encrypter($key, 'AES-256-GCM'), $publicKey);
            $lastReceipt = null;
            $replicationAvailable = false;
            $capture = new TelnyxCutoverCapture($local, function (string $receiptId) use (
                $local, $replica, &$lastReceipt, &$replicationAvailable,
            ): bool {
                if (! $replicationAvailable) {
                    return false;
                }
                $envelope = $local->open($receiptId);
                $verified = $replica->store(
                    $envelope['destination'], $envelope['body'], $envelope['signature'], $envelope['timestamp'],
                );
                $recovered = $replica->open($verified['receipt_id']);
                unset($envelope['received_at'], $recovered['received_at']);
                if ($receiptId !== $verified['receipt_id'] || $envelope !== $recovered) {
                    return false;
                }
                $lastReceipt = $receiptId;

                return true;
            });
            $inbound = $this->request('messaging', 'fixture-event-one', 'message.received',
                ['id' => 'fixture-sms-one', 'text' => 'fixture-body-stays-private'], $secretKey);
            $this->assertSame(503, $capture->capture($inbound)->getStatusCode());
            $this->assertSame([], $replica->reconciliationSnapshot());
            $replicationAvailable = true;
            $this->assertSame(204, $capture->capture($inbound)->getStatusCode());
            $first = $lastReceipt;
            $this->assertSame(204, $capture->capture($inbound)->getStatusCode());
            $this->assertSame($first, $lastReceipt);

            $send = function (string $path, string $id, string $type, array $payload) use (
                $capture, $secretKey, &$lastReceipt,
            ): string {
                $lastReceipt = null;
                $this->assertSame(204, $capture->capture($this->request($path, $id, $type, $payload, $secretKey))->getStatusCode());
                $this->assertIsString($lastReceipt);

                return $lastReceipt;
            };
            $alias = $send('messaging', 'fixture-event-alias', 'message.received', ['id' => 'fixture-sms-one']);
            $manual = [
                $send('voice', 'fixture-voice', 'call.initiated', []) => 'manual-voice',
                $send('status', 'fixture-status', 'message.finalized', ['id' => 'fixture-sms-one']) => 'manual-status-order',
                $send('shared-mms/01234567-89ab-cdef-0123-456789abcdef', 'fixture-mms',
                    'message.finalized', ['id' => 'fixture-mms-one']) => 'manual-status-order',
                $send('messaging', 'fixture-event-conflict', 'message.received', ['id' => 'fixture-a']) => 'manual-conflicting-event',
                $send('messaging', 'fixture-event-conflict', 'message.received', ['id' => 'fixture-b']) => 'manual-conflicting-event',
                $send('messaging', 'fixture-no-message-id', 'message.received', []) => 'manual-invalid-message',
            ];
            $acknowledged = [$first, $alias, ...array_keys($manual)];
            sort($acknowledged, SORT_STRING);
            $this->assertSame($acknowledged, array_column($local->reconciliationSnapshot(), 'receipt_id'));
            $this->assertSame($acknowledged, array_column($replica->reconciliationSnapshot(), 'receipt_id'));

            $input = fopen($root.'/fixture-private.json', 'xb');
            try {
                chmod($root.'/fixture-private.json', 0600);
                fwrite($input, json_encode(['cipher_key' => base64_encode($key), 'public_key' => $publicKey,
                    'acknowledged_receipts' => $acknowledged], JSON_THROW_ON_ERROR));
            } finally {
                fclose($input);
            }
            $this->removeOwnedDirectory($root.'/local');
            unset($capture, $local, $replica);

            $firstRun = $this->recover($root, 'report-first.json');
            $this->assertSame(0, $firstRun->getExitCode(), $firstRun->getOutput());
            $this->assertSame('', $firstRun->getErrorOutput());
            $summary = json_decode($firstRun->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('dry-run-verified', $summary['result']);
            $this->assertSame(8, $summary['recovered']);
            $this->assertSame([
                'duplicate-message' => 1, 'manual-conflicting-event' => 2, 'manual-invalid-message' => 1,
                'manual-status-order' => 2, 'manual-voice' => 1, 'would-ingest' => 1,
            ], $summary['counts']);
            $this->assertStringNotContainsString('fixture-body-stays-private', $firstRun->getOutput());
            $this->assertStringNotContainsString($first, $firstRun->getOutput());

            $report = file_get_contents($root.'/report-first.json');
            $private = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($acknowledged, array_keys($private['receipts']));
            foreach ($manual as $id => $decision) {
                $this->assertSame($decision, $private['receipts'][$id]);
            }
            $this->assertEqualsCanonicalizing(['would-ingest', 'duplicate-message'],
                [$private['receipts'][$first], $private['receipts'][$alias]]);
            $this->assertStringNotContainsString('fixture-body-stays-private', $report);
            $this->assertSame(0600, fileperms($root.'/report-first.json') & 0777);

            $again = $this->recover($root, 'report-second.json');
            $this->assertSame(0, $again->getExitCode(), $again->getOutput());
            $this->assertSame($report, file_get_contents($root.'/report-second.json'));

            $sealed = $root.'/recovered/'.$first.'.sealed';
            $held = $root.'/temporarily-held.sealed';
            rename($sealed, $held);
            try {
                $missing = $this->recover($root, 'report-missing.json');
                $this->assertSame(1, $missing->getExitCode());
                $this->assertSame(['result' => 'REFUSED', 'reason' => 'inventory-mismatch'],
                    json_decode($missing->getOutput(), true, flags: JSON_THROW_ON_ERROR));
                $this->assertFileDoesNotExist($root.'/report-missing.json');
            } finally {
                rename($held, $sealed);
            }
            $unexpectedRequest = $this->request('messaging', 'fixture-extra', 'message.received',
                ['id' => 'fixture-sms-extra'], $secretKey);
            $replica = new TelnyxCutoverInbox($root.'/recovered', new Encrypter($key, 'AES-256-GCM'), $publicKey);
            $extra = $replica->store('messaging', $unexpectedRequest->getContent(),
                $unexpectedRequest->headers->get('Telnyx-Signature-Ed25519'), $unexpectedRequest->headers->get('Telnyx-Timestamp'));
            try {
                $unexpected = $this->recover($root, 'report-extra.json');
                $this->assertSame(1, $unexpected->getExitCode());
                $this->assertSame(['result' => 'REFUSED', 'reason' => 'inventory-mismatch'],
                    json_decode($unexpected->getOutput(), true, flags: JSON_THROW_ON_ERROR));
                $this->assertFileDoesNotExist($root.'/report-extra.json');
            } finally {
                unlink($root.'/recovered/'.$extra['receipt_id'].'.sealed');
            }
            file_put_contents($sealed, 'invalid synthetic ciphertext');
            $damaged = $this->recover($root, 'report-damaged.json');
            $this->assertSame(1, $damaged->getExitCode());
            $this->assertSame(['result' => 'REFUSED', 'reason' => 'fixture-refused'],
                json_decode($damaged->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertFileDoesNotExist($root.'/report-damaged.json');
        } finally {
            $this->removeOwnedDirectory($root.'/local');
            $this->removeOwnedDirectory($root.'/recovered');
            $this->removeOwnedDirectory($root);
        }
    }

    private function request(string $path, string $id, string $type, array $payload, string $secretKey): Request
    {
        $body = json_encode(['data' => ['id' => $id, 'event_type' => $type, 'payload' => $payload]], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $secretKey));

        return Request::create('/api/webhooks/telnyx/'.$path, 'POST', server: [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
            'HTTP_TELNYX_TIMESTAMP' => $timestamp,
        ], content: $body);
    }

    private function recover(string $root, string $file): Process
    {
        $composer = new ReflectionClass(ClassLoader::class);
        $autoload = dirname($composer->getFileName(), 2).'/autoload.php';
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/telnyx-cutover-recovery-worker.php',
            $autoload, $root, $file]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    private function removeOwnedDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            foreach (array_diff(scandir($directory), ['.', '..']) as $file) {
                unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }
}
