<?php

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single funnel for all shoot-media storage access.
 *
 * Every read/write decision between the local public disk and the Cloudflare
 * R2 ("media") disk lives here, gated by the config/media.php feature flags so
 * the phased cutover (dual-write -> read-from-r2 -> r2-only) and instant
 * rollback are driven from one place. Object keys are kept byte-for-byte
 * identical across disks (e.g. shoots/{id}/todo/<file>); the only normalization
 * applied is stripping a historical leading "storage/" prefix that the
 * iGuide/CubiCasa ingest jobs persisted into ShootFile::$path.
 */
class MediaStorage
{
    /**
     * Normalize a stored path into a canonical relative object key.
     *
     * Strips a leading "storage/" segment (legacy iGuide/CubiCasa ingest) and
     * any leading slashes so the same key resolves identically on both disks.
     */
    public function normalizeKey(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $key = ltrim(trim($path), '/');

        if (str_starts_with($key, 'storage/')) {
            $key = substr($key, strlen('storage/'));
        }

        return $key === '' ? null : $key;
    }

    /** The Cloudflare R2 disk. */
    public function remoteDisk(): Filesystem
    {
        return Storage::disk(config('media.remote_disk', 'media'));
    }

    /** The historical local public disk. */
    public function localDisk(): Filesystem
    {
        return Storage::disk(config('media.local_disk', 'public'));
    }

    public function dualWriteEnabled(): bool
    {
        return (bool) config('media.dual_write', false);
    }

    public function readFromR2Enabled(): bool
    {
        return (bool) config('media.read_from_r2', false);
    }

    public function r2Only(): bool
    {
        return (bool) config('media.r2_only', false);
    }

    /**
     * Persist contents under $key.
     *
     * Honors the rollout flags: always writes R2 when dual-write or r2-only is
     * on, and writes local unless r2-only has retired the local disk.
     */
    public function put(string $key, mixed $contents, array $options = []): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return false;
        }

        $ok = true;

        if (! $this->r2Only()) {
            $ok = $this->localDisk()->put($key, $contents, $options) && $ok;
        }

        if ($this->dualWriteEnabled() || $this->r2Only()) {
            try {
                $ok = $this->remoteDisk()->put($key, $contents, $options) && $ok;
            } catch (\Throwable $e) {
                Log::warning('MediaStorage R2 put failed', ['key' => $key, 'error' => $e->getMessage()]);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Mirror a single local object to R2 under the identical key.
     *
     * Used by the dual-write path and the media:backfill-r2 command. Streams to
     * avoid loading large originals fully into memory.
     */
    public function copyLocalToR2(string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return false;
        }

        $local = $this->localDisk();
        if (! $local->exists($key)) {
            return false;
        }

        try {
            $stream = $local->readStream($key);
            if ($stream === null) {
                return false;
            }

            $this->remoteDisk()->writeStream($key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('MediaStorage copyLocalToR2 failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function localSize(string $key): ?int
    {
        $key = $this->normalizeKey($key);
        if ($key === null || ! $this->localDisk()->exists($key)) {
            return null;
        }

        return $this->localDisk()->size($key);
    }

    public function remoteSize(string $key): ?int
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        try {
            return $this->remoteDisk()->exists($key) ? $this->remoteDisk()->size($key) : null;
        } catch (\Throwable $e) {
            Log::warning('MediaStorage R2 size probe failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Idempotently mirror a local object to R2.
     *
     * Returns one of: 'copied', 'skipped' (already present with matching size),
     * 'missing' (no local source), or 'failed'. Shared by the dual-write sync
     * job and the media:backfill-r2 command.
     */
    public function mirrorToR2(string $key, bool $force = false): string
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return 'missing';
        }

        $localSize = $this->localSize($key);
        if ($localSize === null) {
            return 'missing';
        }

        if (! $force) {
            $remoteSize = $this->remoteSize($key);
            if ($remoteSize !== null && $remoteSize === $localSize) {
                return 'skipped';
            }
        }

        return $this->copyLocalToR2($key) ? 'copied' : 'failed';
    }

    /**
     * Read object contents, preferring R2 when reads are flipped, falling back
     * to local while both stores coexist.
     */
    public function get(string $key): ?string
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            try {
                if ($this->remoteDisk()->exists($key)) {
                    return $this->remoteDisk()->get($key);
                }
            } catch (\Throwable $e) {
                Log::warning('MediaStorage R2 get failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        if (! $this->r2Only() && $this->localDisk()->exists($key)) {
            return $this->localDisk()->get($key);
        }

        return null;
    }

    /**
     * Stream an R2 object down to a local temp file and return its absolute path
     * (or null when absent). Callers own deleting the temp file. Used by the
     * watermark/process/scan/zip pipelines to source originals from R2 when the
     * local copy is gone (post-prune) and Dropbox is disabled.
     */
    public function downloadToTemp(string $key, ?string $suffix = null): ?string
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        try {
            if (! $this->remoteDisk()->exists($key)) {
                return null;
            }

            $stream = $this->remoteDisk()->readStream($key);
            if ($stream === null) {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'r2src_');
            if ($suffix) {
                $renamed = $tmp . $suffix;
                @rename($tmp, $renamed);
                $tmp = $renamed;
            }

            $out = fopen($tmp, 'w');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $tmp;
        } catch (\Throwable $e) {
            Log::warning('MediaStorage downloadToTemp failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Whether the object exists on the disk that currently serves reads. */
    public function exists(string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return false;
        }

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            try {
                if ($this->remoteDisk()->exists($key)) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('MediaStorage R2 exists failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return ! $this->r2Only() && $this->localDisk()->exists($key);
    }

    public function existsOnR2(string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return false;
        }

        try {
            return $this->remoteDisk()->exists($key);
        } catch (\Throwable $e) {
            Log::warning('MediaStorage R2 exists probe failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Delete the object from whichever store(s) currently hold it. */
    public function delete(string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return false;
        }

        $ok = true;

        if (! $this->r2Only() && $this->localDisk()->exists($key)) {
            $ok = $this->localDisk()->delete($key) && $ok;
        }

        if ($this->dualWriteEnabled() || $this->r2Only()) {
            try {
                $ok = $this->remoteDisk()->delete($key) && $ok;
            } catch (\Throwable $e) {
                Log::warning('MediaStorage R2 delete failed', ['key' => $key, 'error' => $e->getMessage()]);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Public (CDN) URL for delivered/watermarked/public-tour assets.
     *
     * Returns the R2 custom-domain URL when reads are flipped, otherwise the
     * local public URL.
     */
    public function publicUrl(string $key): string
    {
        $key = $this->normalizeKey($key) ?? '';

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            return $this->remoteDisk()->url($key);
        }

        return $this->localDisk()->url($key);
    }

    /**
     * Short-lived presigned URL for raw originals and unpaid/locked media.
     *
     * Falls back to the public URL only while R2 reads are not yet enabled.
     */
    public function temporaryUrl(string $key, ?int $ttlSeconds = null): string
    {
        $key = $this->normalizeKey($key) ?? '';
        $ttl = $ttlSeconds ?? (int) config('media.temporary_url_ttl', 900);

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            return $this->remoteDisk()->temporaryUrl($key, now()->addSeconds($ttl));
        }

        return $this->localDisk()->url($key);
    }

    /**
     * Stream an object as an HTTP response from whichever store serves reads.
     *
     * Replaces the historical response()->file($localAbsolutePath) pattern that
     * assumed local-filesystem semantics.
     */
    public function streamResponse(string $key, ?string $mimeType = null): StreamedResponse
    {
        $key = $this->normalizeKey($key) ?? '';

        $disk = ($this->readFromR2Enabled() || $this->r2Only()) && $this->existsOnR2($key)
            ? $this->remoteDisk()
            : $this->localDisk();

        $mime = $mimeType ?: ($disk->mimeType($key) ?: 'application/octet-stream');

        return response()->stream(function () use ($disk, $key) {
            $stream = $disk->readStream($key);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
        ]);
    }
}
