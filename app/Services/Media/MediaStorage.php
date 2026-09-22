<?php

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single funnel for all shoot-media storage access.
 *
 * Every read/write decision between the private local disk, the historical
 * public disk, and the Cloudflare R2 ("media") disk lives here, gated by the
 * config/media.php feature flags so the phased cutover (dual-write ->
 * read-from-r2 -> r2-only) and instant rollback are driven from one place.
 * Object keys are kept byte-for-byte identical across disks (e.g.
 * shoots/{id}/todo/<file>); the only normalization applied is stripping a
 * historical leading "storage/" prefix that the iGuide/CubiCasa ingest jobs
 * persisted into ShootFile::$path. New writes never go to the public disk.
 */
class MediaStorage
{
    public function __construct(private ?OriginalsStorageGuard $originalsGuard = null)
    {
        $this->originalsGuard ??= new OriginalsStorageGuard();
    }

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

    /** The private local disk used for new writes. */
    public function localDisk(?string $key = null): Filesystem
    {
        return Storage::disk($this->writeDiskName($key));
    }

    /** Historical public disk; reads fall back here until those objects are migrated. */
    public function legacyPublicDisk(): Filesystem
    {
        return Storage::disk($this->legacyPublicDiskName());
    }

    public function localDiskName(?string $key = null): string
    {
        if ($this->usesOriginalsDisk($key)) {
            return (string) config('media.originals_disk', 'media_originals');
        }

        return (string) config('media.local_disk', 'local');
    }

    /** Guard before Laravel constructs the adapter, whose constructor may create its root. */
    public function writeDiskName(?string $key = null): string
    {
        if ($this->usesOriginalsDisk($key)) {
            $this->originalsGuard->assertAvailable();
        }

        return $this->localDiskName($key);
    }

    /** Only known master/original layouts move to HDD; previews and unknown keys stay on NVMe. */
    public function usesOriginalsDisk(?string $key): bool
    {
        if (! (bool) config('media.tiered_storage_enabled', false)) {
            return false;
        }
        $key = $this->normalizeKey($key);

        return $key !== null && OriginalsStoragePolicy::isOriginalKey($key);
    }

    /** HDD, then old private NVMe, then public: retained copies allow a non-destructive cutover. */
    public function localReadDisks(string $key): array
    {
        return $this->availableLocalDisks($this->usesOriginalsDisk($key));
    }

    /** Maintenance scans must not silently skip an unavailable configured drive. */
    public function localScanDisks(): array
    {
        $tiered = (bool) config('media.tiered_storage_enabled', false);
        if ($tiered) {
            $this->originalsGuard->assertAvailable(false);
        }

        return $this->availableLocalDisks($tiered);
    }

    private function availableLocalDisks(bool $includeOriginals): array
    {
        $names = [];
        if ($includeOriginals && $this->originalsGuard->isAvailable()) {
            $names[] = (string) config('media.originals_disk', 'media_originals');
        }
        $names[] = $this->localDiskName();
        if ($this->usesPrivateLocalDisk()) {
            $names[] = $this->legacyPublicDiskName();
        }
        $disks = [];
        foreach (array_unique($names) as $name) {
            $disks[$name] = Storage::disk($name);
        }

        return $disks;
    }

    private function localDiskFor(string $key): ?Filesystem
    {
        foreach ($this->localReadDisks($key) as $disk) {
            if ($disk->exists($key)) {
                return $disk;
            }
        }

        return null;
    }

    public function legacyPublicDiskName(): string
    {
        return (string) config('media.legacy_public_disk', 'public');
    }

    public function usesPrivateLocalDisk(): bool
    {
        return $this->localDiskName() !== $this->legacyPublicDiskName();
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
            $disk = $this->localDisk($key);
            if ($this->usesOriginalsDisk($key)) {
                // Publish large originals atomically; readers keep the old copy until the new write completes.
                $temporaryKey = dirname($key).'/.'.basename($key).'.upload-'.bin2hex(random_bytes(12));
                try {
                    $ok = $disk->put($temporaryKey, $contents, $options);
                    if ($ok) {
                        $this->originalsGuard->assertAvailable();
                        $ok = $disk->move($temporaryKey, $key);
                    }
                } finally {
                    if ($this->originalsGuard->isAvailable() && $disk->exists($temporaryKey)) {
                        $disk->delete($temporaryKey);
                    }
                }
            } else {
                $ok = $disk->put($key, $contents, $options);
            }
        }

        if ($this->dualWriteEnabled() || $this->r2Only()) {
            try {
                // Local writes may consume a non-seekable stream. Re-open the persisted copy for mirroring.
                $remoteOk = is_resource($contents) && ! $this->r2Only()
                    ? ($ok && $this->copyLocalToR2($key))
                    : $this->remoteDisk()->put($key, $contents, $options);
                $ok = $remoteOk && $ok;
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

        $local = $this->localDiskFor($key);
        if ($local === null) {
            return false;
        }

        $stream = null;
        try {
            $stream = $local->readStream($key);
            if ($stream === null) {
                return false;
            }

            return $this->remoteDisk()->writeStream($key, $stream);
        } catch (\Throwable $e) {
            Log::warning('MediaStorage copyLocalToR2 failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function localSize(string $key): ?int
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        return $this->localDiskFor($key)?->size($key);
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

        if (! $this->r2Only()) {
            return $this->localDiskFor($key)?->get($key);
        }

        if ($this->usesPrivateLocalDisk() && $this->legacyPublicDisk()->exists($key)) {
            return $this->legacyPublicDisk()->get($key);
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

        return ! $this->r2Only() && $this->localDiskFor($key) !== null;
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

        if (! $this->r2Only()) {
            // A missing HDD must fail the deletion so its retained copy cannot reappear on remount.
            if ($this->usesOriginalsDisk($key)) {
                $this->originalsGuard->assertAvailable();
            }
            foreach ($this->localReadDisks($key) as $disk) {
                if ($disk->exists($key)) {
                    $ok = $disk->delete($key) && $ok;
                }
            }
        } elseif ($this->usesPrivateLocalDisk() && $this->legacyPublicDisk()->exists($key)) {
            $ok = $this->legacyPublicDisk()->delete($key) && $ok;
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
     * Filesystem that currently holds $key for serving (R2, private local, then public).
     */
    public function diskFor(string $key): ?Filesystem
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            try {
                if ($this->remoteDisk()->exists($key)) {
                    return $this->remoteDisk();
                }
            } catch (\Throwable $e) {
                Log::warning('MediaStorage R2 disk probe failed', ['key' => $key, 'error' => $e->getMessage()]);
            }

            if ($this->r2Only()) {
                return null;
            }
        }

        return $this->localDiskFor($key);
    }

    /** Absolute path when the serving disk is local-filesystem backed. */
    public function absolutePath(string $key): ?string
    {
        $key = $this->normalizeKey($key);
        if ($key === null) {
            return null;
        }

        foreach ($this->localReadDisks($key) as $disk) {
            if (! $disk->exists($key)) {
                continue;
            }

            try {
                return $disk->path($key);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * App-signed URL that streams $key through the authenticated media controller.
     *
     * These URLs are the replacement for `/storage/shoots/...` aliases.
     */
    public function signedAppUrl(string $key, ?int $ttlSeconds = null): string
    {
        $key = $this->normalizeKey($key) ?? '';
        $ttl = $ttlSeconds ?? (int) config('media.signed_url_ttl', 604800);

        return URL::temporarySignedRoute(
            'api.public.shoot-media.file',
            now()->addSeconds(max(1, $ttl)),
            ['path' => $key]
        );
    }

    /**
     * Public (CDN) URL for delivered/watermarked/public-tour assets.
     *
     * Returns the R2 custom-domain URL when reads are flipped, otherwise a
     * short-lived application signed URL to the private local object.
     */
    public function publicUrl(string $key): string
    {
        $key = $this->normalizeKey($key) ?? '';

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            return $this->remoteDisk()->url($key);
        }

        return $this->signedAppUrl($key, (int) config('media.signed_url_ttl', 604800));
    }

    /**
     * Convert a stored path or a historical `/storage/shoots|share-links` URL
     * into the URL the app should hand to browsers. Other http(s) values pass
     * through. Non-media relative paths return null so callers can keep using
     * the public disk for avatars/branding.
     */
    public function servingUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            $pathname = parse_url($path, PHP_URL_PATH) ?: '';
            if (str_starts_with($pathname, '/storage/shoots/')
                || str_starts_with($pathname, '/storage/share-links/')) {
                return $this->publicUrl(ltrim(substr($pathname, strlen('/storage/')), '/'));
            }

            return $path;
        }

        $key = $this->normalizeKey($path);
        if ($key === null) {
            return null;
        }

        if (str_starts_with($key, 'shoots/') || str_starts_with($key, 'share-links/')) {
            return $this->publicUrl($key);
        }

        return null;
    }

    /**
     * Short-lived presigned URL for raw originals and unpaid/locked media.
     */
    public function temporaryUrl(string $key, ?int $ttlSeconds = null): string
    {
        $key = $this->normalizeKey($key) ?? '';
        $ttl = $ttlSeconds ?? (int) config('media.temporary_url_ttl', 900);

        if ($this->readFromR2Enabled() || $this->r2Only()) {
            return $this->remoteDisk()->temporaryUrl($key, now()->addSeconds($ttl));
        }

        return $this->signedAppUrl($key, $ttl);
    }

    /**
     * Stream an object as an HTTP response from whichever store serves reads.
     *
     * Replaces the historical response()->file($localAbsolutePath) pattern that
     * assumed local-filesystem semantics.
     */
    public function streamResponse(string $key, ?string $mimeType = null, array $headers = []): StreamedResponse
    {
        $key = $this->normalizeKey($key) ?? '';
        $disk = $this->diskFor($key);
        if ($disk === null) {
            abort(404);
        }

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
        }, 200, array_merge([
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers));
    }

    public function downloadResponse(string $key, string $filename, array $headers = [])
    {
        $key = $this->normalizeKey($key) ?? '';
        $disk = $this->diskFor($key);
        if ($disk === null) {
            abort(404);
        }

        return $disk->download($key, $filename, array_merge([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers));
    }
}
