<?php

namespace App\Services\LinkPreview;

use App\Services\Media\MediaStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Persists rendered cards under immutable, content-addressed object keys.
 *
 * The payload fingerprint changes whenever a pixel-affecting input changes, so
 * these files may be cached by browsers and social crawlers for a year. A
 * distributed cache lock prevents two crawler requests from rendering the same
 * card concurrently.
 */
class OgImageService
{
    private const IMMUTABLE_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private readonly MediaStorage $media,
        private readonly OgCardRenderer $renderer,
    ) {
    }

    /**
     * @return array{key: string, url: string, fingerprint: string, contents: string, size: int, cache_hit: bool}
     */
    public function ensure(PreviewPayload $payload): array
    {
        $fingerprint = $payload->fingerprint();
        $key = $this->keyFor($payload->type, $payload->shootId, $fingerprint);

        $cached = $this->readKey($key, $fingerprint, true);
        if ($cached !== null) {
            return $cached;
        }

        $generated = false;
        $lockName = 'link-preview:card:' . $fingerprint;
        $lockSeconds = max(15, (int) config('link_preview.cache.lock_seconds', 60));
        $waitSeconds = max(1, (int) config('link_preview.cache.lock_wait_seconds', 10));

        $lockStore = Cache::store((string) config('link_preview.cache.lock_store', 'file'));

        try {
            // Scoped per fingerprint only. A single global render lock would make
            // distinct cold cards queue behind one another, and because Laravel
            // waits by sleeping in-process, waiting requests would each pin a
            // PHP-FPM worker until the wait elapsed.
            $generated = (bool) $lockStore->lock($lockName, $lockSeconds)->block(
                $waitSeconds,
                function () use ($payload, $key, $fingerprint): bool {
                    // The request that won the race may have finished while we
                    // were waiting for the lock.
                    if ($this->readKey($key, $fingerprint, true) !== null) {
                        return false;
                    }

                    $contents = $this->renderer->render($payload);
                    $this->assertValidCard($contents);

                    $stored = $this->media->put($key, $contents, [
                        'visibility' => 'public',
                        'ContentType' => 'image/jpeg',
                        'CacheControl' => self::IMMUTABLE_CACHE_CONTROL,
                    ]);

                    if (! $stored) {
                        throw new RuntimeException("Unable to persist link preview card [{$key}].");
                    }

                    return true;
                }
            );
        } catch (LockTimeoutException $exception) {
            // A slow remote image may outlive the wait. Serve the winner's
            // object if it appeared; otherwise fail rather than rendering an
            // unlocked duplicate.
            $cached = $this->readKey($key, $fingerprint, true);
            if ($cached !== null) {
                return $cached;
            }

            throw new RuntimeException('Timed out waiting for link preview generation.', 0, $exception);
        }

        $result = $this->readKey($key, $fingerprint, ! $generated);
        if ($result === null) {
            throw new RuntimeException("Generated link preview card is unavailable [{$key}].");
        }

        return $result;
    }

    /**
     * Read an already-generated immutable card without resolving the current
     * shoot. This keeps old fingerprint URLs valid after the shoot changes.
     *
     * @return array{key: string, url: string, fingerprint: string, contents: string, size: int, cache_hit: bool}|null
     */
    public function existing(string $type, ?int $shootId, string $fingerprint): ?array
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $fingerprint)) {
            return null;
        }

        return $this->readKey($this->keyFor($type, $shootId, $fingerprint), $fingerprint, true);
    }

    public function keyFor(string $type, ?int $shootId, string $fingerprint): string
    {
        $prefix = trim((string) config('link_preview.cache.path_prefix', 'og-cards'), '/');
        $scope = $shootId === null ? 'static' : (string) $shootId;
        $safeType = trim((string) preg_replace('/[^a-z0-9-]+/i', '-', $type), '-');

        return "{$prefix}/{$scope}/{$safeType}-{$fingerprint}.jpg";
    }

    public static function immutableCacheControl(): string
    {
        return self::IMMUTABLE_CACHE_CONTROL;
    }

    /**
     * @return array{key: string, url: string, fingerprint: string, contents: string, size: int, cache_hit: bool}|null
     */
    private function readKey(string $key, string $fingerprint, bool $cacheHit): ?array
    {
        if (! $this->media->exists($key)) {
            return null;
        }

        $contents = $this->media->get($key);
        if ($contents === null) {
            return null;
        }

        try {
            $this->assertValidCard($contents);
        } catch (RuntimeException) {
            // Never keep serving a partial write or a file created by an old,
            // incompatible implementation. The lock holder will regenerate it.
            $this->media->delete($key);

            return null;
        }

        return [
            'key' => $key,
            'url' => $this->media->publicUrl($key),
            'fingerprint' => $fingerprint,
            'contents' => $contents,
            'size' => strlen($contents),
            'cache_hit' => $cacheHit,
        ];
    }

    private function assertValidCard(string $contents): void
    {
        $maxBytes = (int) config('link_preview.card.max_bytes', 307200);
        if ($contents === '' || strlen($contents) > $maxBytes) {
            throw new RuntimeException('Rendered link preview exceeds the configured size budget.');
        }

        $dimensions = @getimagesizefromstring($contents);
        if (! is_array($dimensions)
            || ($dimensions['mime'] ?? null) !== 'image/jpeg'
            || (int) ($dimensions[0] ?? 0) !== (int) config('link_preview.card.width', 1200)
            || (int) ($dimensions[1] ?? 0) !== (int) config('link_preview.card.height', 630)) {
            throw new RuntimeException('Rendered link preview is not a valid 1200x630 JPEG.');
        }
    }
}
