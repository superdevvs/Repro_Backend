<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use FilesystemIterator;
use RuntimeException;

/**
 * Unbound, database-independent intake primitive. No route or service provider
 * activates it. A caller may acknowledge only after store() returns successfully.
 */
class TelnyxCutoverInbox
{
    private readonly string $publicKey;

    public function __construct(
        private readonly string $directory,
        private readonly Encrypter $cipher,
        string $publicKeyBase64,
        private readonly int $maxBodyBytes = 2_097_152,
        private readonly int $toleranceSeconds = 300,
    ) {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || ! function_exists('fsync')
            || ! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Durable intake requires Linux, POSIX permissions, fsync and sodium.');
        }
        $key = base64_decode($publicKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $maxBodyBytes < 1 || $maxBodyBytes > 16_777_216 || $toleranceSeconds < 1 || $toleranceSeconds > 900) {
            throw new RuntimeException('Invalid inbox verification configuration.');
        }
        $this->publicKey = $key;
        $this->assertDirectory();
    }

    /** Returns a local durable receipt, never an HTTP acknowledgement. */
    public function store(string $destination, string $body, string $signature, string $timestamp): array
    {
        $receivedAt = time();
        $eventId = $this->verify($destination, $body, $signature, $timestamp, $receivedAt);
        $receipt = $this->identity($destination, $eventId, $body);
        $lock = $this->lock(LOCK_EX);
        $stage = null;
        try {
            $target = $this->path($receipt);
            if (file_exists($target) || is_link($target)) {
                $prior = $this->open($receipt);
                if ($prior['destination'] !== $destination || $prior['body'] !== $body) {
                    throw new RuntimeException('Inbox receipt identity conflict.');
                }
                // Also completes a prior publish whose directory fsync failed.
                $this->syncDirectory();

                return ['receipt_id' => $receipt, 'duplicate' => true];
            }
            $envelope = ['version' => 1, 'provider' => 'telnyx', 'destination' => $destination,
                'event_id' => $eventId, 'body' => $body, 'body_sha256' => hash('sha256', $body),
                'signature' => $signature, 'timestamp' => $timestamp, 'received_at' => $receivedAt];
            $sealed = $this->cipher->encryptString(json_encode($envelope, JSON_THROW_ON_ERROR));
            $stage = $this->directory.'/.pending-'.bin2hex(random_bytes(16));
            $stream = @fopen($stage, 'x+b');
            if ($stream === false) throw new RuntimeException('Cannot create inbox receipt.');
            try {
                if (! @chmod($stage, 0600)) throw new RuntimeException('Cannot protect inbox receipt.');
                $this->assertFile($stream);
                $this->writeAll($stream, $sealed);
                if (! fflush($stream)) throw new RuntimeException('Cannot flush inbox receipt.');
                $this->syncFile($stream);
                rewind($stream);
                if (stream_get_contents($stream) !== $sealed) throw new RuntimeException('Inbox receipt readback failed.');
            } finally {
                fclose($stream);
            }
            if (! @rename($stage, $target)) throw new RuntimeException('Cannot publish inbox receipt.');
            $stage = null;
            $this->syncDirectory();

            return ['receipt_id' => $receipt, 'duplicate' => false];
        } finally {
            if ($stage !== null && is_file($stage)) @unlink($stage);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Authenticates a snapshot of the local receipts for private reconciliation.
     * Provider IDs and bodies stay inside this reader. The list has no dispatch order.
     */
    public function reconciliationSnapshot(int $maxReceipts = 2000): array
    {
        if ($maxReceipts < 1 || $maxReceipts > 5000) {
            throw new RuntimeException('Invalid inbox reconciliation limit.');
        }
        $deadline = hrtime(true) + 30_000_000_000;
        $lock = $this->lock(LOCK_SH);
        $ids = [];
        try {
            foreach (new FilesystemIterator($this->directory) as $entry) {
                $name = $entry->getFilename();
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Inbox reconciliation timed out.');
                }
                if ($name === '.intake.lock' || preg_match('/\A\.pending-[a-f0-9]{32}\z/', $name)) {
                    continue;
                }
                if (! preg_match('/\A([a-f0-9]{64})\.sealed\z/', $name, $parts)) {
                    throw new RuntimeException('Unexpected entry in the inbox.');
                }
                if (count($ids) >= $maxReceipts) {
                    throw new RuntimeException('Inbox exceeds the reconciliation limit.');
                }
                $ids[] = $parts[1];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // Intake only appends. Enumerate under its lock, then authenticate outside
        // the lock so decryption does not block subsequent HTTP requests.
        sort($ids, SORT_STRING);
        $envelopes = [];
        $eventCounts = [];
        foreach ($ids as $id) {
            $receipt = $this->open($id);
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Inbox reconciliation timed out.');
            }
            $eventId = 'event:'.$receipt['event_id'];
            $envelopes[$id] = [$eventId, $receipt['destination'], $receipt['received_at']];
            $eventCounts[$eventId] = ($eventCounts[$eventId] ?? 0) + 1;
        }
        $receipts = [];
        foreach ($envelopes as $id => [$eventId, $destination, $receivedAt]) {
            $receipts[] = [
                'receipt_id' => $id,
                'destination' => $destination,
                'received_at' => $receivedAt,
                'provider_event_conflict' => $eventCounts[$eventId] > 1,
                'voice_review_required' => $destination === 'voice',
            ];
        }

        return $receipts;
    }

    /** Explicit local replay readers receive the original signed bytes; no dispatch occurs. */
    public function open(string $receipt): array
    {
        $this->assertDirectory();
        $path = $this->path($receipt);
        if (is_link($path)) throw new RuntimeException('Unsafe inbox receipt.');
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Cannot read inbox receipt.');
        try {
            $this->assertFile($stream);
            $limit = $this->maxBodyBytes * 6 + 65536;
            $sealed = stream_get_contents($stream, $limit + 1);
            if ($sealed === false || strlen($sealed) > $limit) throw new RuntimeException('Invalid sealed receipt size.');
        } finally {
            fclose($stream);
        }
        try {
            $envelope = json_decode($this->cipher->decryptString($sealed), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ($envelope['version'] ?? null) !== 1 || ($envelope['provider'] ?? null) !== 'telnyx'
                || ! is_int($envelope['received_at'] ?? null)) throw new RuntimeException('Invalid inbox receipt.');
            $eventId = $this->verify($envelope['destination'], $envelope['body'], $envelope['signature'], $envelope['timestamp'], $envelope['received_at']);
            if ($eventId !== $envelope['event_id'] || ! hash_equals($envelope['body_sha256'], hash('sha256', $envelope['body']))
                || ! hash_equals($receipt, $this->identity($envelope['destination'], $eventId, $envelope['body']))) {
                throw new RuntimeException('Invalid inbox receipt binding.');
            }

            return $envelope;
        } catch (\Throwable $error) {
            throw new RuntimeException('Inbox receipt authentication failed.', previous: $error);
        }
    }

    private function verify(string $destination, string $body, string $signature, string $timestamp, int $receivedAt): string
    {
        if (! preg_match('/\A(?:messaging|status|voice|shared-mms:[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D', $destination)
            || strlen($body) > $this->maxBodyBytes || ! preg_match('/\A[0-9]{1,12}\z/D', $timestamp)
            || abs($receivedAt - (int) $timestamp) > $this->toleranceSeconds) {
            throw new RuntimeException('Invalid or stale inbox request.');
        }
        $signatureBytes = base64_decode($signature, true);
        if ($signatureBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signatureBytes, $timestamp.'|'.$body, $this->publicKey)) {
            throw new RuntimeException('Invalid inbox signature.');
        }
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Invalid inbox JSON.', previous: $error);
        }
        $eventId = $payload['data']['id'] ?? ($destination === 'voice' ? ($payload['id'] ?? null) : null);
        if (! is_string($eventId) || ! preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $eventId)) {
            throw new RuntimeException('A stable provider event ID is required.');
        }

        return $eventId;
    }

    private function identity(string $destination, string $eventId, string $body): string
    {
        // Retry envelopes may vary. Retain each distinct signed body for audit;
        // replay deduplication belongs to the downstream provider-event consumer.
        return hash('sha256', 'telnyx'."\0".$destination."\0".$eventId."\0".hash('sha256', $body));
    }

    private function path(string $receipt): string
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $receipt)) throw new RuntimeException('Invalid receipt identifier.');

        return $this->directory.'/'.$receipt.'.sealed';
    }

    private function lock(int $mode)
    {
        $this->assertDirectory();
        $path = $this->directory.'/.intake.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe inbox lock.');
        $stream = @fopen($path, 'c+b');
        if ($stream === false) throw new RuntimeException('Cannot open inbox lock.');
        try {
            if (! @chmod($path, 0600)) throw new RuntimeException('Cannot protect inbox lock.');
            $this->assertFile($stream);
            $deadline = hrtime(true) + 2_000_000_000;
            while (! flock($stream, $mode | LOCK_NB)) {
                if (hrtime(true) >= $deadline) throw new RuntimeException('Inbox intake is busy.');
                usleep(10_000);
            }

            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    private function assertDirectory(): void
    {
        clearstatcache();
        $stat = @stat($this->directory);
        if (! str_starts_with($this->directory, '/') || realpath($this->directory) !== $this->directory
            || is_link($this->directory) || ! is_dir($this->directory) || $stat === false
            || ($stat['mode'] & 0077) !== 0 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Inbox must be an existing private directory owned by the intake user.');
        }
    }

    private function assertFile($stream): void
    {
        $stat = fstat($stream);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1) throw new RuntimeException('Unsafe inbox file.');
    }

    protected function writeAll($stream, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Inbox receipt write failed.');
        }
    }

    protected function syncFile($stream): void
    {
        if (! @fsync($stream)) throw new RuntimeException('Cannot durably sync inbox receipt.');
    }

    protected function syncDirectory(): void
    {
        $directory = @fopen($this->directory, 'r');
        if ($directory === false) throw new RuntimeException('Cannot open inbox directory for sync.');
        try {
            if (! @fsync($directory)) throw new RuntimeException('Cannot durably sync inbox directory.');
        } finally {
            fclose($directory);
        }
    }
}
