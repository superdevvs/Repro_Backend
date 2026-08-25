<?php

namespace App\Services\LinkPreview;

use App\Services\Media\MediaStorage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Loads the raw bytes behind an image the tour API handed us as a URL.
 *
 * Own media is mapped back to its object key and read without a network round
 * trip. External reads are limited to known image CDNs, never follow redirects,
 * and stream into a hard byte ceiling to prevent SSRF and memory amplification.
 */
class ImageSourceLoader
{
    private const MAX_BYTES = 24 * 1024 * 1024;

    private const HTTP_TIMEOUT = 8;

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(
        private readonly MediaStorage $mediaStorage,
    ) {
    }

    /** @return string|null Raw image bytes, or null when the source cannot be read. */
    public function load(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        $source = trim($source);
        if (array_key_exists($source, $this->memo)) {
            return $this->memo[$source];
        }

        return $this->memo[$source] = $this->read($source);
    }

    private function read(string $source): ?string
    {
        if (! preg_match('#^https?://#i', $source)) {
            return $this->fromStorage($source);
        }

        $key = $this->keyFromOwnUrl($source);
        if ($key !== null) {
            $bytes = $this->fromStorage($key);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return $this->fromHttp($source);
    }

    private function fromStorage(string $path): ?string
    {
        $key = $this->mediaStorage->normalizeKey($path);
        if ($key === null) {
            return null;
        }

        try {
            $bytes = $this->mediaStorage->get($key);
            if ($bytes !== null && $bytes !== '') {
                return $this->withinByteCeiling($bytes, $key);
            }
        } catch (\Throwable) {
            // Fall through to the historical local public disk.
        }

        try {
            if (Storage::disk('public')->exists($key)) {
                $bytes = Storage::disk('public')->get($key);
                if ($bytes !== null && $bytes !== '') {
                    return $this->withinByteCeiling($bytes, $key);
                }
            }
        } catch (\Throwable $exception) {
            Log::debug('LinkPreview: storage read failed', [
                'key' => $key,
                'error' => $exception::class,
            ]);
        }

        return null;
    }

    /**
     * Own storage is trusted, but a delivered original can still be far larger
     * than anything a 1200x630 card needs. Apply the same ceiling as remote
     * reads so one oversized file cannot exhaust the worker's memory.
     */
    private function withinByteCeiling(string $bytes, string $key): ?string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            Log::debug('LinkPreview: storage image exceeds byte ceiling', [
                'key' => $key,
                'bytes' => strlen($bytes),
            ]);

            return null;
        }

        return $bytes;
    }

    /**
     * Map an R2/local public URL back to its canonical object key.
     */
    private function keyFromOwnUrl(string $url): ?string
    {
        $bases = [];

        foreach ([
            fn () => $this->mediaStorage->remoteDisk()->url(''),
            fn () => $this->mediaStorage->localDisk()->url(''),
            fn () => rtrim((string) config('app.url'), '/') . '/storage/',
        ] as $resolver) {
            try {
                $base = $resolver();
                if (is_string($base) && $base !== '') {
                    $bases[] = rtrim($base, '/') . '/';
                }
            } catch (\Throwable) {
                // A disk may not be configured in every environment.
            }
        }

        foreach ($bases as $base) {
            if (stripos($url, $base) === 0) {
                $key = substr($url, strlen($base));
                $key = urldecode(strtok($key, '?') ?: '');

                return $key !== '' ? $key : null;
            }
        }

        return null;
    }

    private function fromHttp(string $url): ?string
    {
        if (! $this->isAllowedRemoteUrl($url)) {
            Log::debug('LinkPreview: blocked external image origin', [
                'source' => $this->safeSourceLabel($url),
            ]);

            return null;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['Accept' => 'image/*'])
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'stream' => true,
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::debug('LinkPreview: image fetch returned non-2xx', [
                    'source' => $this->safeSourceLabel($url),
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_starts_with($contentType, 'image/')) {
                Log::debug('LinkPreview: image fetch returned a non-image', [
                    'source' => $this->safeSourceLabel($url),
                    'content_type' => $contentType,
                ]);

                return null;
            }

            return $this->readLimitedBody($response);
        } catch (\Throwable $exception) {
            // Exception messages from HTTP clients can contain the complete URL
            // (including iGUIDE accessToken query values), so log only its class.
            Log::debug('LinkPreview: image fetch failed', [
                'source' => $this->safeSourceLabel($url),
                'error' => $exception::class,
            ]);

            return null;
        }
    }

    private function readLimitedBody(Response $response): ?string
    {
        $declaredLength = (int) $response->header('Content-Length');
        if ($declaredLength > self::MAX_BYTES) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof()) {
            $remaining = self::MAX_BYTES - strlen($body);
            if ($remaining <= 0) {
                return null;
            }

            $chunk = $stream->read(min(8192, $remaining + 1));
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
            if (strlen($body) > self::MAX_BYTES) {
                return null;
            }
        }

        return $body !== '' ? $body : null;
    }

    private function isAllowedRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        foreach ((array) config('link_preview.remote_image_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if ($host !== ltrim($suffix, '.') && str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif (hash_equals($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    private function safeSourceLabel(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'invalid-host');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if (strlen($path) > 120) {
            $path = substr($path, 0, 117) . '...';
        }

        return strtolower($host) . $path . '#' . substr(hash('sha256', $url), 0, 12);
    }
}
