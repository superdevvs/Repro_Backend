<?php

namespace App\Services\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** Called only after the route has authorized the requested media. */
class LocalMediaDelivery
{
    public function forDisk(Filesystem $disk, string $key, array $headers): ?Response
    {
        $this->assertSafeKey($key);
        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return null;
        }

        // Compare the selected adapter itself: a retained local copy must never
        // replace a remote object selected by MediaStorage::diskFor().
        foreach ($this->roots() as $root) {
            if (isset($root['disk']) && Storage::disk($root['disk']) === $disk) {
                $this->assertWithinRoot($disk->path($key), $disk->path(''));
                if (! app(MediaStorage::class)->performanceEnabled('download_offload', key: $key)) {
                    return null;
                }

                return $this->response($disk->path($key), $root, $headers, null, $key);
            }
        }

        return null;
    }

    public function forPath(string $path, ?string $filename, array $headers, ?int $shootId): Response
    {
        $headers = $this->headers($headers, $filename);
        $normalized = str_replace('\\', '/', $path);
        abort_if(preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1, 404);

        foreach ($this->roots() as $root) {
            $configured = rtrim(str_replace('\\', '/', $root['path']), '/');
            if (str_starts_with($normalized, $configured.'/')) {
                return $this->response($path, $root, $headers, $shootId, substr($normalized, strlen($configured) + 1));
            }
        }

        // Arbitrary absolute paths must never become a new private file endpoint.
        abort(404);
    }

    public function temporaryDownload(string $source, string $filename, array $headers, ?int $shootId): Response
    {
        abort_unless(is_file($source) && ! is_link($source), 404);
        $directory = $this->temporaryDirectory();
        if (! is_dir($directory) && ! mkdir($directory, 02770, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create temporary download directory.');
        }
        // Keep scheduled cleanup and FPM compatible through the shared group,
        // even when the creating process has a restrictive default umask.
        chmod($directory, 02770);
        $destination = $directory.'/'.bin2hex(random_bytes(24));
        // rename is atomic on the same filesystem. For a cross-device source,
        // copy to a unique unpublished name and only then publish that name.
        if (! @rename($source, $destination)) {
            $partial = $destination.'.partial';
            try {
                if (! copy($source, $partial) || ! rename($partial, $destination)) {
                    throw new \RuntimeException('Cannot publish temporary download.');
                }
                unlink($source);
            } finally {
                if (is_file($partial)) {
                    unlink($partial);
                }
            }
        }
        chmod($destination, 0660);
        touch($destination);

        // Never deleteFileAfterSend: an offloaded response ends before Nginx
        // opens/sends the file. The scheduled command retains it for 24 hours.
        return $this->forPath($destination, $filename, $headers, $shootId);
    }

    public function temporaryDirectory(): string
    {
        return storage_path('app/private/downloads');
    }

    public function pruneTemporaryDownloads(): int
    {
        $directory = $this->temporaryDirectory();
        if (! is_dir($directory)) {
            return 0;
        }
        $count = 0;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && ! $entry->isLink()
                && preg_match('/^[a-f0-9]{48}(?:\.partial)?$/', $entry->getFilename()) === 1
                && $entry->getMTime() < time() - 86400) {
                $count += (int) unlink($entry->getPathname());
            }
        }

        return $count;
    }

    public function headers(array $headers, ?string $filename = null): array
    {
        $headers = array_merge([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers);
        if ($filename !== null && ! isset($headers['Content-Disposition'])) {
            $filename = str_replace(['/', '\\', "\r", "\n", "\0"], '_', $filename);
            $fallback = preg_replace('/[^\x20-\x7E]|%/', '_', Str::ascii($filename)) ?: 'download';
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback);
        }

        return $headers;
    }

    public function assertSafeKey(string $key): void
    {
        abort_if($key === '' || str_contains($key, '\\') || preg_match('/[\x00-\x1F\x7F]/', $key)
            || preg_match('#(^|/)\.\.?(/|$)#', $key), 404);
    }

    private function response(string $path, array $root, array $headers, ?int $shootId, string $key): Response
    {
        $this->assertSafeKey($key);
        [$canonicalRoot, $canonical] = $this->assertWithinRoot($path, $root['path']);
        $headers = $this->headers($headers);

        if (app(MediaStorage::class)->performanceEnabled('download_offload', $shootId, $key)) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($canonical, strlen($canonicalRoot) + 1));
            $headers['X-Accel-Redirect'] = $root['uri'].implode('/', array_map('rawurlencode', explode('/', $relative)));
            // Nginx owns length/range/HEAD once it opens the authorized file.
            unset($headers['Content-Length'], $headers['X-Accel-Buffering']);
            $headers['Content-Type'] ??= mime_content_type($canonical) ?: 'application/octet-stream';

            return response('', 200, $headers);
        }

        return response()->file($canonical, $headers);
    }

    private function assertWithinRoot(string $path, string $root): array
    {
        $canonicalRoot = realpath($root);
        $canonical = realpath($path);
        abort_unless($canonicalRoot !== false && $canonical !== false && is_file($canonical)
            && str_starts_with($canonical, rtrim($canonicalRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR), 404);

        return [$canonicalRoot, $canonical];
    }

    /** Order temporary before private because its root is nested inside private. */
    private function roots(): array
    {
        $roots = [[
            'path' => $this->temporaryDirectory(),
            'uri' => '/_repro-media/downloads/',
        ]];
        foreach ([
            [config('media.local_disk', 'local'), 'private'],
            [config('media.originals_disk', 'media_originals'), 'originals'],
            [config('media.legacy_public_disk', 'public'), 'legacy'],
        ] as [$disk, $alias]) {
            $configuration = config('filesystems.disks.'.$disk, []);
            if (($configuration['driver'] ?? null) === 'local' && ! empty($configuration['root'])) {
                if ($alias === 'originals' && ! app(OriginalsStorageGuard::class)->isAvailable()) {
                    continue;
                }
                $resolved = Storage::disk($disk);
                $path = $resolved instanceof FilesystemAdapter && $resolved->getAdapter() instanceof LocalFilesystemAdapter
                    ? $resolved->path('') : $configuration['root'];
                $roots[] = ['disk' => $disk, 'path' => $path, 'uri' => '/_repro-media/'.$alias.'/'];
            }
        }

        return $roots;
    }
}
