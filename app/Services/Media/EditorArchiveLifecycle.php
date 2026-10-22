<?php

namespace App\Services\Media;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Durable usage records and locks; never stored in an expiring application cache. */
final class EditorArchiveLifecycle
{
    public static function matches(string $key): bool
    {
        return preg_match('#\Aeditor-downloads/[1-9][0-9]*/(?:[a-f0-9]{40}|[a-f0-9]{64})\.zip\z#D', $key) === 1;
    }

    public function graceSeconds(): int
    {
        // Bright MLS's URL resolver also accepts editor-downloads keys. Cover every
        // pre-tracking route, including a configured TTL longer than seven days.
        return max(604800, (int) config('media.signed_url_ttl', 604800),
            (int) config('media.temporary_url_ttl', 900),
            (int) config('media.bright_mls_signed_url_ttl', 2592000)) + 86400;
    }

    public function acquire(string $key, int $ttlSeconds = 0): ?EditorArchiveLease
    {
        if (! self::matches($key)) {
            return null;
        }
        $handle = $this->open(hash('sha256', $key).'.lock', true);
        if (! flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \RuntimeException('Cannot protect the editor archive.');
        }
        $lease = new EditorArchiveLease($handle);
        try {
            $this->update(hash('sha256', $key).'.json', function (array $state) use ($key, $ttlSeconds) {
                $this->validateState($key, $state ?: null);
                $timestamp = now()->timestamp;

                return [
                    'key' => $key,
                    'last_used_at' => max($timestamp, (int) ($state['last_used_at'] ?? 0)),
                    // Offloaded Nginx downloads outlive the PHP response; leave a full day after link expiry.
                    'protected_until' => max($timestamp + max(86400, $ttlSeconds + 86400), (int) ($state['protected_until'] ?? 0)),
                ];
            });
        } catch (\Throwable $exception) {
            $lease->release();
            throw $exception;
        }

        return $lease;
    }

    public function withResponse(string $key, callable $response): Response
    {
        $lease = $this->acquire($key);
        try {
            $result = $response();
            if ($lease && $result instanceof StreamedResponse) {
                $callback = $result->getCallback();
                $result->setCallback(function () use ($callback, $lease) {
                    try {
                        if ($callback) {
                            $callback();
                        }
                    } finally {
                        $lease->release();
                    }
                });

                return $result;
            }
            $lease?->release();

            return $result;
        } catch (\Throwable $exception) {
            $lease?->release();
            throw $exception;
        }
    }

    /** Apply initializes a one-time rollout grace. A dry run never creates tracking files. */
    public function rollout(bool $apply): ?array
    {
        $state = ! $apply ? $this->read('rollout.json') : $this->update('rollout.json', fn (array $state) => $state ?: [
            'started_at' => now()->timestamp,
            'protected_until' => now()->timestamp + $this->graceSeconds(),
            'grace_seconds' => $this->graceSeconds(),
        ]);
        if ($state !== null && (! is_int($state['started_at'] ?? null)
            || ! is_int($state['protected_until'] ?? null)
            || ! is_int($state['grace_seconds'] ?? null)
            || $state['started_at'] <= 0 || $state['grace_seconds'] < 691200
            || $state['protected_until'] < $state['started_at'] + $state['grace_seconds'])) {
            throw new \RuntimeException('Invalid editor archive rollout record.');
        }

        return $state;
    }

    /** Nonblocking exclusive lock; do not remove lock files, even after deletion. */
    public function cleanupLock(string $key, bool $apply): ?EditorArchiveLease
    {
        $name = hash('sha256', $key).'.lock';
        $handle = $this->open($name, $apply);
        // A missing lock is free in a dry run. Its result is advisory; apply always
        // creates and takes the real lock, then checks all conditions again.
        if ($handle === null) {
            return new EditorArchiveLease(null);
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new EditorArchiveLease($handle);
    }

    public function state(string $key): ?array
    {
        $state = $this->read(hash('sha256', $key).'.json');
        $this->validateState($key, $state);

        return $state;
    }

    private function validateState(string $key, ?array $state): void
    {
        if ($state !== null && (($state['key'] ?? null) !== $key
            || ! is_int($state['last_used_at'] ?? null) || ! is_int($state['protected_until'] ?? null)
            || $state['last_used_at'] <= 0 || $state['protected_until'] < $state['last_used_at'])) {
            throw new \RuntimeException('Invalid editor archive usage record.');
        }
    }

    private function read(string $name): ?array
    {
        $handle = $this->open($name, false);
        if ($handle === null) {
            return null;
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Cannot read editor archive usage.');
            }
            $raw = stream_get_contents($handle);
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state) || $state === []) {
                throw new \RuntimeException('Invalid editor archive usage.');
            }

            return $state;
        } finally {
            fclose($handle);
        }
    }

    private function update(string $name, callable $change): array
    {
        $handle = $this->open($name, true);
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot record editor archive usage.');
            }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                throw new \RuntimeException('Invalid editor archive usage.');
            }
            $state = $change($state);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (! ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new \RuntimeException('Cannot persist editor archive usage.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new \RuntimeException('Cannot sync editor archive usage.');
            }

            return $state;
        } finally {
            fclose($handle);
        }
    }

    private function open(string $name, bool $create): mixed
    {
        $root = (string) config('media.editor_archive_tracking_root', storage_path('app/private/editor-archive-retention'));
        if (is_link($root)) {
            throw new \RuntimeException('Unsafe editor archive tracking directory.');
        }
        if (! is_dir($root)) {
            if (! $create) {
                return null;
            }
            if (! @mkdir($root, 02770, true) && ! is_dir($root)) {
                throw new \RuntimeException('Cannot create editor archive tracking directory.');
            }
            if (function_exists('posix_geteuid')) {
                @chgrp($root, 'www-data');
            }
            if (! @chmod($root, 02770)) {
                throw new \RuntimeException('Cannot share editor archive tracking directory.');
            }
        }
        $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (! $before && ! $create) {
            return null;
        }
        if ($before && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1)) {
            throw new \RuntimeException('Unsafe editor archive tracking file.');
        }
        $created = false;
        if (! $before) {
            $handle = @fopen($path, 'x+b');
            $created = is_resource($handle);
            if (! $created) {
                $handle = @fopen($path, 'r+b');
            }
        } else {
            $handle = @fopen($path, $create ? 'r+b' : 'rb');
        }
        if (! is_resource($handle)) {
            throw new \RuntimeException('Cannot open editor archive tracking file.');
        }
        clearstatcache(true, $path);
        $actual = fstat($handle);
        $entry = @lstat($path);
        if (! $actual || ! $entry || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1
            || $actual['ino'] !== $entry['ino'] || $actual['dev'] !== $entry['dev']) {
            fclose($handle);
            throw new \RuntimeException('Editor archive tracking file changed.');
        }
        if ($created && ! @chmod($path, 0660)) {
            fclose($handle);
            throw new \RuntimeException('Cannot share editor archive tracking file.');
        }

        return $handle;
    }
}
