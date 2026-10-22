<?php

namespace App\Services\Media;

use App\Models\Shoot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Prunes only recognized, regenerable editor ZIPs; never delegates deletion to MediaStorage. */
final class EditorArchiveRetention
{
    public function __construct(private EditorArchiveLifecycle $lifecycle) {}

    public function run(bool $apply = false): array
    {
        $rollout = $this->lifecycle->rollout($apply);
        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'retention_days' => max(14, (int) config('media.editor_archive_retention_days', 14)),
            'grace_until' => $rollout ? gmdate('c', $rollout['protected_until']) : null,
            'grace_required_seconds' => $this->lifecycle->graceSeconds(),
            'eligible_count' => 0, 'eligible_bytes' => 0,
            'deleted_count' => 0, 'deleted_bytes' => 0,
            'decisions' => [], 'totals' => [],
        ];
        // These flags can change which copy is authoritative. Defer that migration
        // policy instead of guessing which local duplicate can be discarded.
        if (config('media.read_from_r2') || config('media.r2_only') || config('media.dual_write')) {
            $this->record($report, '', '', 'remote_storage_enabled');

            return $report;
        }
        $cutoff = now()->subDays($report['retention_days'])->timestamp;
        $seenRoots = [];
        foreach (array_unique([config('media.local_disk', 'local'), config('media.legacy_public_disk', 'public')]) as $disk) {
            if (config("filesystems.disks.{$disk}.driver") !== 'local') {
                $this->record($report, $disk, '', 'non_local_disk');

                continue;
            }
            $configuredRoot = rtrim(Storage::disk($disk)->path(''), '/\\');
            if (! $this->safeDirectory($configuredRoot)) {
                $this->record($report, $disk, '', 'unsafe_or_unreadable_directory');

                continue;
            }
            $root = realpath($configuredRoot);
            if ($root === false || isset($seenRoots[$root])) {
                continue;
            }
            $seenRoots[$root] = true;
            $cache = $root.DIRECTORY_SEPARATOR.'editor-downloads';
            if (! file_exists($cache) && ! is_link($cache)) {
                continue;
            }
            if (! $this->safeDirectory($cache) || ! is_readable($cache)) {
                $this->record($report, $disk, 'editor-downloads', 'unsafe_or_unreadable_directory');

                continue;
            }
            foreach (new \DirectoryIterator($cache) as $directory) {
                if ($directory->isDot()) {
                    continue;
                }
                $id = $directory->getFilename();
                $parent = $cache.DIRECTORY_SEPARATOR.$id;
                if (! preg_match('/\A[1-9][0-9]*\z/D', $id) || ! $this->safeDirectory($parent) || ! is_readable($parent)) {
                    $this->record($report, $disk, 'editor-downloads/'.$id, 'unknown_or_unsafe_directory');

                    continue;
                }
                foreach (new \DirectoryIterator($parent) as $entry) {
                    if ($entry->isDot()) {
                        continue;
                    }
                    $key = 'editor-downloads/'.$id.'/'.$entry->getFilename();
                    if (! EditorArchiveLifecycle::matches($key)) {
                        $this->record($report, $disk, $key, 'unknown_path');

                        continue;
                    }
                    $path = $parent.DIRECTORY_SEPARATOR.$entry->getFilename();
                    $lock = null;
                    try {
                        $lock = $this->lifecycle->cleanupLock($key, $apply);
                        if ($lock === null) {
                            $this->record($report, $disk, $key, 'active_operation');

                            continue;
                        }
                        $stat = $this->safeFile($path, $root);
                        if ($stat === null) {
                            $this->record($report, $disk, $key, 'unsafe_file');

                            continue;
                        }
                        $bytes = $stat['size'];
                        $reason = $this->preserveReason($key, $id, $stat, $cutoff);
                        if ($reason !== null) {
                            $this->record($report, $disk, $key, $reason, $bytes);

                            continue;
                        }
                        $report['eligible_count']++;
                        $report['eligible_bytes'] += $bytes;
                        if (! $rollout || $rollout['protected_until'] >= now()->timestamp) {
                            $this->record($report, $disk, $key, $rollout ? 'rollout_grace' : 'rollout_not_initialized', $bytes);

                            continue;
                        }
                        if (! $apply) {
                            $this->record($report, $disk, $key, 'would_delete', $bytes);

                            continue;
                        }
                        if (! is_writable($parent)) {
                            $this->record($report, $disk, $key, 'parent_not_writable', $bytes);

                            continue;
                        }
                        // Recheck the exact inode and both parent directories after all
                        // metadata/database work. No directory recursion or ZIP reads.
                        $current = $this->safeFile($path, $root);
                        if ($current === null || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev']
                            || $current['mtime'] !== $stat['mtime'] || $current['size'] !== $bytes) {
                            $this->record($report, $disk, $key, 'file_changed', $bytes);

                            continue;
                        }
                        if (! @unlink($path)) {
                            $this->record($report, $disk, $key, 'delete_failed', $bytes);

                            continue;
                        }
                        $report['deleted_count']++;
                        $report['deleted_bytes'] += $bytes;
                        $this->record($report, $disk, $key, 'deleted', $bytes);
                    } catch (\Throwable $exception) {
                        // Missing/unwritable/corrupt usage metadata must preserve the file.
                        $this->record($report, $disk, $key, 'inspection_failed', 0, $exception->getMessage());
                    } finally {
                        $lock?->release();
                    }
                }
            }
        }

        return $report;
    }

    private function preserveReason(string $key, string $id, array $stat, int $cutoff): ?string
    {
        $state = $this->lifecycle->state($key);
        if ($state && $state['protected_until'] >= now()->timestamp) {
            return 'protected_link_or_download';
        }
        if (max($stat['mtime'], $state['last_used_at'] ?? 0) >= $cutoff) {
            return 'recently_used';
        }
        $signature = pathinfo(basename($key), PATHINFO_FILENAME);
        if (Cache::has("editor-raw-zip:{$id}:{$signature}")) {
            return 'queued_or_building';
        }
        $shoot = Shoot::query()->select(['id', 'status', 'workflow_status'])->find($id);
        if (! $shoot) {
            return 'unknown_shoot';
        }
        $terminal = [Shoot::STATUS_DELIVERED, Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED];
        if (! in_array($shoot->status, $terminal, true)
            || ($shoot->workflow_status && ! in_array($shoot->workflow_status, $terminal, true))) {
            return 'active_shoot';
        }

        return null;
    }

    private function safeDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat && ($stat['mode'] & 0170000) === 0040000 && ! is_link($path);
    }

    private function safeFile(string $path, string $root): ?array
    {
        if (! $this->safeDirectory(dirname($path)) || ! $this->safeDirectory(dirname($path, 2))) {
            return null;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            return null;
        }
        $resolved = realpath($path);
        if ($resolved === false || $resolved !== $path || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR.'editor-downloads'.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $stat;
    }

    private function record(array &$report, string $disk, string $key, string $reason, int $bytes = 0, ?string $error = null): void
    {
        $report['decisions'][] = array_filter(compact('disk', 'key', 'reason', 'bytes', 'error'), fn ($value) => $value !== null);
        $report['totals'][$reason] ??= ['count' => 0, 'bytes' => 0];
        $report['totals'][$reason]['count']++;
        $report['totals'][$reason]['bytes'] += $bytes;
    }
}
