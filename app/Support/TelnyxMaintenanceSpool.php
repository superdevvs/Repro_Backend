<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use Throwable;

/**
 * Private on-disk spool for provider webhooks that arrive while the app is in
 * maintenance mode. Texts wait in texts/ for telnyx:replay-spool; voice and
 * Vapi events stay in calls/ for the missed-call list and are never replayed.
 *
 * Each entry holds the exact request body, the headers needed to re-verify
 * it, and its arrival time, encrypted with the app key. Entries are written to
 * a temporary file, flushed to disk and renamed, so a reader never sees half
 * an entry. Folders are 2770 and files 0640: PHP-FPM writes, and the deploy
 * user reads and moves them through the shared www-data group.
 */
final class TelnyxMaintenanceSpool
{
    public const TEXTS = 'texts';

    public const CALLS = 'calls';

    public const REPLAYED = 'replayed';

    public const REJECTED = 'rejected';

    private const FOLDERS = [self::TEXTS, self::CALLS, self::REPLAYED, self::REJECTED];

    private const ENTRY = '/\A\d{8}T\d{12}Z-[a-f0-9]{16}\.spool\z/';

    public function __construct(
        private readonly string $root,
        private readonly Encrypter $encrypter,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('services.telnyx.maintenance_capture.spool_path', storage_path('app/telnyx-spool')), '/'),
            app('encrypter'),
        );
    }

    /** True when Ed25519 signs "timestamp|body" with the configured Telnyx key. */
    public static function telnyxSignatureValid(string $body, string $signature, string $timestamp): bool
    {
        $publicKey = base64_decode((string) config('services.telnyx.public_key'), true);
        $signatureBytes = base64_decode($signature, true);
        if (! function_exists('sodium_crypto_sign_verify_detached') || $publicKey === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signatureBytes === false
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES || ! preg_match('/\A\d{1,12}\z/', $timestamp)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $timestamp.'|'.$body, $publicKey);
    }

    /**
     * Durably saves one verified request and returns its entry id. Throws on
     * any failure; the caller must not acknowledge the provider then.
     *
     * @param  array<string, string>  $headers
     */
    public function save(string $folder, string $route, ?string $routeKey, string $body, array $headers, CarbonImmutable $arrivedAt): string
    {
        if (! in_array($folder, [self::TEXTS, self::CALLS], true)) {
            throw new RuntimeException('Only texts and calls are captured.');
        }
        $this->prepare();
        $arrivedAt = $arrivedAt->utc();
        $id = $arrivedAt->format('Ymd\THisu\Z').'-'.bin2hex(random_bytes(8)).'.spool';
        $sealed = $this->encrypter->encryptString(json_encode([
            'v' => 1, 'route' => $route, 'route_key' => $routeKey, 'arrived_at' => $arrivedAt->format('Y-m-d\TH:i:s.u\Z'),
            'headers' => $headers, 'body_b64' => base64_encode($body),
        ], JSON_THROW_ON_ERROR));

        $directory = $this->root.'/'.$folder;
        $temporary = $directory.'/.'.$id.'.tmp';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create a spool entry.');
        }
        try {
            if (! @chmod($temporary, 0640) || @fwrite($stream, $sealed) !== strlen($sealed) || ! fflush($stream) || ! @fsync($stream)) {
                throw new RuntimeException('Cannot write a spool entry.');
            }
        } catch (Throwable $error) {
            fclose($stream);
            @unlink($temporary);
            throw $error;
        }
        fclose($stream);
        if (! @rename($temporary, $directory.'/'.$id)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish a spool entry.');
        }
        $this->syncDirectory($directory);

        return $id;
    }

    public function hasPendingTexts(): bool
    {
        return $this->ids(self::TEXTS) !== [];
    }

    /**
     * Entries in arrival order. An entry that cannot be decrypted or decoded
     * comes back with readable=false so the caller can set it aside.
     *
     * @return list<array{id: string, readable: bool, route?: string, route_key?: ?string, body?: string, headers?: array<string, string>, arrived_at?: CarbonImmutable}>
     */
    public function entries(string $folder): array
    {
        $entries = [];
        foreach ($this->ids($folder) as $id) {
            $entries[] = $this->read($folder, $id);
        }
        $order = fn (array $entry) => [isset($entry['arrived_at']) ? $entry['arrived_at']->format('Y-m-d H:i:s.u') : '', $entry['id']];
        usort($entries, fn (array $a, array $b) => $order($a) <=> $order($b));

        return $entries;
    }

    public function move(string $id, string $from, string $to): void
    {
        $this->assertEntry($id);
        if (! in_array($from, self::FOLDERS, true) || ! in_array($to, self::FOLDERS, true)
            || ! @rename($this->root.'/'.$from.'/'.$id, $this->root.'/'.$to.'/'.$id)) {
            throw new RuntimeException('Cannot move a spool entry.');
        }
        $this->syncDirectory($this->root.'/'.$to);
    }

    /** Deletes entries in $folder that arrived before $cutoff. */
    public function prune(string $folder, CarbonImmutable $cutoff): int
    {
        $limit = $cutoff->utc()->format('Ymd\THisu\Z');
        $deleted = 0;
        foreach ($this->ids($folder) as $id) {
            if (strcmp(substr($id, 0, 22), $limit) < 0 && @unlink($this->root.'/'.$folder.'/'.$id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    private function ids(string $folder): array
    {
        if (! in_array($folder, self::FOLDERS, true) || ! is_dir($this->root.'/'.$folder)) {
            return [];
        }
        $ids = array_values(array_filter(scandir($this->root.'/'.$folder) ?: [], fn (string $name) => preg_match(self::ENTRY, $name) === 1));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function read(string $folder, string $id): array
    {
        try {
            $sealed = @file_get_contents($this->root.'/'.$folder.'/'.$id);
            $data = json_decode($this->encrypter->decryptString((string) $sealed), true, 8, JSON_THROW_ON_ERROR);
            $body = base64_decode((string) ($data['body_b64'] ?? ''), true);
            if (($data['v'] ?? null) !== 1 || ! is_string($data['route'] ?? null) || $body === false
                || ! is_array($data['headers'] ?? null) || ! is_string($data['arrived_at'] ?? null)) {
                throw new RuntimeException('Unexpected spool entry.');
            }

            return [
                'id' => $id, 'readable' => true, 'route' => $data['route'],
                'route_key' => is_string($data['route_key'] ?? null) ? $data['route_key'] : null,
                'body' => $body, 'headers' => array_map('strval', $data['headers']),
                'arrived_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $data['arrived_at'], 'UTC'),
            ];
        } catch (Throwable) {
            return ['id' => $id, 'readable' => false];
        }
    }

    private function prepare(): void
    {
        foreach (['', ...array_map(fn (string $folder) => '/'.$folder, self::FOLDERS)] as $suffix) {
            $directory = $this->root.$suffix;
            if (is_dir($directory)) {
                continue;
            }
            if (! @mkdir($directory, 0770, $suffix === '') && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create the spool directory.');
            }
            @chmod($directory, 02770);
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the spool directory.');
        }
        try {
            if (! @fsync($handle)) {
                throw new RuntimeException('Cannot sync the spool directory.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertEntry(string $id): void
    {
        if (! preg_match(self::ENTRY, $id)) {
            throw new RuntimeException('Invalid spool entry id.');
        }
    }
}
