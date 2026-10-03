<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateEditorRawZipJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\ArchiveQueue;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class EditorRawArchiveService
{
    public function descriptor(Shoot $shoot, User $user, Collection $files): array
    {
        $entryNames = [];
        $usedNames = [];
        $formatter = app(DeliveryFilenameFormatter::class);
        foreach ($files->values() as $index => $file) {
            $entryNames[] = $formatter->deduplicate($formatter->archivePathForFile($file, $index + 1, $files->count(), basename((string) ($file->storage_path ?: $file->path))), $usedNames);
        }
        $signature = hash('sha256', json_encode([
            'version' => 2,
            'actor' => (int) $user->id,
            'role' => $user->role,
            'order' => app(DeliveryMediaOrderService::class)->orderFingerprint($shoot),
            'entry_names' => $entryNames,
            'files' => $files->map(fn (ShootFile $file) => [
                'id' => $file->id,
                'service' => $file->shoot_service_id,
                'path' => $file->storage_path ?: $file->path,
                'name' => $file->filename,
                'size' => $file->file_size,
                'updated' => $file->updated_at?->format('Y-m-d H:i:s.u'),
                'order' => $file->sort_order,
                'stage' => $file->workflow_stage,
                'scan' => $file->scan_status,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));

        return [
            'file_ids' => $files->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'storage_path' => "editor-downloads/{$shoot->id}/{$signature}.zip",
            'lock_key' => "editor-raw-zip:{$shoot->id}:{$signature}",
        ];
    }

    public function queue(Shoot $shoot, User $user, Collection $files): array
    {
        $descriptor = $this->descriptor($shoot, $user, $files);
        if (! app(MediaStorage::class)->exists($descriptor['storage_path'])
            && Cache::add($descriptor['lock_key'], 1, ArchiveQueue::lockSeconds())) {
            try {
                GenerateEditorRawZipJob::dispatch((int) $shoot->id, (int) $user->id,
                    $descriptor['file_ids'], $descriptor['storage_path'], $descriptor['lock_key']);
            } catch (\Throwable $exception) {
                Cache::forget($descriptor['lock_key']);
                throw $exception;
            }
        }

        return $descriptor;
    }

    public function authorizedFiles(Shoot $shoot, User $user, ?array $fileIds = null): Collection
    {
        if (! app(ShootAuthorizationSupport::class)->canDownloadShootMedia($shoot, $user)) {
            return collect();
        }
        $files = app(ShootMediaArchiveService::class)->getFilesForType($shoot, 'raw');
        if ($fileIds !== null) {
            $files = $files->whereIn('id', $fileIds)->values();
        }
        if (app(ShootAuthorizationSupport::class)->hasRole($user, ['editor'])) {
            $files = app(ShootEditingAssignmentService::class)->filterFilesForEditor($files, $shoot, $user);
        }

        return $files->values();
    }

    /**
     * Migrate an older ID-only cache in the worker, never in the polling request.
     * Its metadata cannot establish freshness alone: verify ordered names, sizes,
     * source timestamps and every source CRC before copying its completed bytes.
     */
    public function reuseLegacyArchive(Shoot $shoot, User $user, Collection $files, string $destination): bool
    {
        $media = app(MediaStorage::class);
        if ($media->readFromR2Enabled() || $media->r2Only()) {
            return false;
        }
        $ids = $files->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $legacyKey = "editor-downloads/{$shoot->id}/".sha1(implode(',', $ids)).'.zip';
        $legacyPath = $media->absolutePath($legacyKey);
        if (! $legacyPath || ! is_file($legacyPath)) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($legacyPath, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles !== $files->count()) {
                return false;
            }
            $generatedAt = filemtime($legacyPath);
            $formatter = app(DeliveryFilenameFormatter::class);
            $access = app(ShootFileAccessService::class);
            $usedNames = [];
            foreach ($files->values() as $index => $file) {
                $source = $access->findLocalFilePath($file);
                if (! $source || ! is_file($source) || $file->updated_at?->getTimestamp() > $generatedAt
                    || filemtime($source) > $generatedAt) {
                    return false;
                }
                $expectedName = $formatter->deduplicate($formatter->archivePathForFile($file, $index + 1, $files->count(), basename($source)), $usedNames);
                $entry = $zip->statIndex($index);
                if (! $entry || $entry['name'] !== $expectedName || (int) $entry['size'] !== filesize($source)
                    || (int) $file->file_size !== filesize($source)
                    || substr(sprintf('%08x', $entry['crc']), -8) !== hash_file('crc32b', $source)) {
                    return false;
                }
            }
        } finally {
            $zip->close();
        }
        $currentShoot = $shoot->fresh();
        $currentUser = $user->fresh();
        if (! $currentShoot || ! $currentUser) {
            return false;
        }
        $currentFiles = $this->authorizedFiles($currentShoot, $currentUser, $ids);
        if ($this->descriptor($currentShoot, $currentUser, $currentFiles)['storage_path'] !== $destination) {
            return false;
        }
        app(\App\Services\Media\MediaArchivePublisher::class)->publish($destination, $legacyPath);

        return true;
    }
}
