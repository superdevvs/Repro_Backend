<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ShootMediaArchiveService
{
    private const ARCHIVE_DISK = 'public';
    private const LOCK_TTL_SECONDS = 600;
    public const POLL_AFTER_MS = 3000;
    private const DEFAULT_FRONTEND_URL = 'https://reprodashboard.com';
    private const DEFAULT_API_URL = 'https://api.reprodashboard.com';

    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootFileAccessService $shootFileAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {
    }

    public function normalizeSize(?string $size): string
    {
        return strtolower((string) $size) === 'small' ? 'small' : 'original';
    }

    public function resolveArchiveResponseData(
        Shoot $shoot,
        string $type,
        string $size,
        string $statusUrl
    ): array {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->hasDownloadableFiles($shoot, $type, $size)) {
            throw new \RuntimeException('No downloadable files available');
        }

        if ($this->hasFreshArchive($shoot, $type, $size)) {
            return [
                'status' => 200,
                'payload' => [
                    'type' => 'redirect',
                    'url' => $this->getArchiveUrl($shoot, $type, $size),
                ],
            ];
        }

        $this->queueArchiveGeneration($shoot, $type, $size);

        return [
            'status' => 202,
            'payload' => [
                'type' => 'preparing',
                'message' => $this->buildPreparingMessage($size),
                'poll_after_ms' => self::POLL_AFTER_MS,
                'status_url' => $statusUrl,
            ],
        ];
    }

    public function queueArchiveGeneration(Shoot $shoot, string $type, string $size): bool
    {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->hasDownloadableFiles($shoot, $type, $size)) {
            return false;
        }

        if ($this->hasFreshArchive($shoot, $type, $size)) {
            return false;
        }

        if (!$this->acquireGenerationLock($shoot, $type, $size)) {
            return false;
        }

        GenerateShootMediaArchiveJob::dispatch($shoot->id, $type, $size);

        return true;
    }

    public function generateArchive(
        Shoot $shoot,
        string $type,
        string $size,
        bool $lockAlreadyHeld = false
    ): array {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        $lockAcquiredHere = false;
        if (!$lockAlreadyHeld) {
            $lockAcquiredHere = $this->acquireGenerationLock($shoot, $type, $size);

            if (!$lockAcquiredHere) {
                return $this->readManifest($shoot, $type, $size) ?? [];
            }
        }

        try {
            $plan = $this->buildArchivePlan($shoot, $type, $size);
            if ($plan['entries'] === []) {
                $this->writeManifest($shoot, $type, $size, [
                    'type' => $type,
                    'size' => $size,
                    'source_signature' => null,
                    'generated_at' => null,
                    'file_count' => 0,
                    'last_error' => 'No downloadable files available',
                ]);

                throw new \RuntimeException('No downloadable files available');
            }

            $manifest = $this->readManifest($shoot, $type, $size);
            if (
                is_array($manifest)
                && ($manifest['source_signature'] ?? null) === $plan['source_signature']
                && empty($manifest['last_error'])
                && $this->archiveExists($shoot, $type, $size)
            ) {
                return $manifest;
            }

            $archivePath = $this->getArchivePath($shoot, $type, $size);
            Storage::disk(self::ARCHIVE_DISK)->makeDirectory(dirname($archivePath));

            $zipAbsolutePath = Storage::disk(self::ARCHIVE_DISK)->path($archivePath);
            $zip = new \ZipArchive();
            if ($zip->open($zipAbsolutePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create ZIP file');
            }

            $addedFiles = 0;
            $tempFiles = [];

            try {
                foreach ($plan['entries'] as $entry) {
                    $localPath = $this->resolveArchiveEntryLocalPath($entry);
                    if (!$localPath || !file_exists($localPath)) {
                        continue;
                    }

                    $zip->addFile($localPath, $entry['archive_name']);
                    $addedFiles++;

                    if (!empty($entry['temp_path'])) {
                        $tempFiles[] = $entry['temp_path'];
                    }
                }
            } finally {
                $zip->close();

                foreach ($tempFiles as $tempFile) {
                    @unlink($tempFile);
                }
            }

            if ($addedFiles === 0) {
                Storage::disk(self::ARCHIVE_DISK)->delete($archivePath);
                throw new \RuntimeException('No downloadable files available');
            }

            $manifest = [
                'type' => $type,
                'size' => $size,
                'source_signature' => $plan['source_signature'],
                'generated_at' => now()->toIso8601String(),
                'file_count' => $addedFiles,
                'last_error' => null,
            ];

            $this->writeManifest($shoot, $type, $size, $manifest);

            return $manifest;
        } catch (\Throwable $exception) {
            $this->writeManifest($shoot, $type, $size, [
                'type' => $type,
                'size' => $size,
                'source_signature' => $plan['source_signature'],
                'generated_at' => now()->toIso8601String(),
                'file_count' => 0,
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('Shoot media archive generation failed', [
                'shoot_id' => $shoot->id,
                'type' => $type,
                'size' => $size,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if ($lockAlreadyHeld || $lockAcquiredHere) {
                $this->releaseGenerationLock($shoot, $type, $size);
            }
        }
    }

    public function hasFreshArchive(Shoot $shoot, string $type, string $size): bool
    {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        if (!$this->archiveExists($shoot, $type, $size)) {
            return false;
        }

        $manifest = $this->readManifest($shoot, $type, $size);
        if (!is_array($manifest) || !empty($manifest['last_error'])) {
            return false;
        }

        $plan = $this->buildArchivePlan($shoot, $type, $size);

        return $plan['entries'] !== []
            && ($manifest['source_signature'] ?? null) === $plan['source_signature'];
    }

    public function hasDownloadableFiles(Shoot $shoot, string $type, string $size): bool
    {
        return $this->buildArchivePlan($shoot, $type, $size)['entries'] !== [];
    }

    public function getArchivePath(Shoot $shoot, string $type, string $size): string
    {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        return "shoots/{$shoot->id}/archives/{$type}-{$size}.zip";
    }

    public function getManifestPath(Shoot $shoot, string $type, string $size): string
    {
        $type = $this->normalizeType($type);
        $size = $this->normalizeSize($size);

        return "shoots/{$shoot->id}/archives/{$type}-{$size}.json";
    }

    public function getArchiveUrl(Shoot $shoot, string $type, string $size): string
    {
        $archivePath = $this->getArchivePath($shoot, $type, $size);
        $diskUrl = Storage::disk(self::ARCHIVE_DISK)->url($archivePath);

        if (preg_match('/^https?:\/\//i', $diskUrl)) {
            return $diskUrl;
        }

        return $this->shootFileAccessService->resolvePublicStorageUrl($diskUrl) ?? $diskUrl;
    }

    public function buildPublicDownloadUrl(
        Shoot $shoot,
        string $type,
        string $size,
        ?\DateTimeInterface $expiresAt = null
    ): string {
        $signedUrl = $this->buildSignedStatusUrl(
            $shoot,
            $type,
            $size,
            $expiresAt
        );

        return rtrim($this->resolveFrontendUrl(), '/')
            . '/download/media?url='
            . rawurlencode($signedUrl);
    }

    public function buildSignedStatusUrl(
        Shoot $shoot,
        string $type,
        string $size,
        ?\DateTimeInterface $expiresAt = null
    ): string {
        return URL::temporarySignedRoute(
            'api.public.shoot-media.download',
            $expiresAt ?? now()->addDays(7),
            [
                'shoot' => $shoot->id,
                'type' => $this->normalizeType($type),
                'size' => $this->normalizeSize($size),
            ]
        );
    }

    public function getFilesForType(Shoot $shoot, string $type): Collection
    {
        $query = $shoot->files()->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');

        if ($this->normalizeType($type) === 'raw') {
            $query->where(function ($builder) {
                $builder->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->orWhereNull('workflow_stage');
            });
        } else {
            $query->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        }

        $files = $query->get();

        if ($this->normalizeType($type) === 'edited') {
            $files = $files
                ->reject(fn (ShootFile $file) => $this->shootAuthorizationSupport->isRawCameraFile($file))
                ->values();
        }

        return $files;
    }

    protected function buildArchivePlan(Shoot $shoot, string $type, string $size): array
    {
        $entries = [];
        foreach ($this->getFilesForType($shoot, $type) as $file) {
            $sourcePath = $this->resolveDownloadPath($file, $size);
            if (!$sourcePath) {
                continue;
            }

            $entries[] = [
                'file_id' => $file->id,
                'archive_name' => $file->original_name ?? $file->filename ?? basename($sourcePath),
                'source_path' => $sourcePath,
                'updated_at' => optional($file->updated_at)->toIso8601String(),
                'file_size' => $file->file_size,
                'temp_path' => null,
            ];
        }

        return [
            'entries' => $entries,
            'source_signature' => $entries === [] ? null : $this->buildSourceSignature($entries),
        ];
    }

    protected function resolveDownloadPath(ShootFile $file, string $size): ?string
    {
        $candidates = $size === 'small'
            ? [
                $file->web_path,
                $file->thumbnail_path,
                $file->placeholder_path,
                $file->storage_path,
                $file->path,
                $file->dropbox_path,
            ]
            : [
                $file->storage_path,
                $file->path,
                $file->dropbox_path,
            ];

        foreach ($candidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            if ($candidate === $file->dropbox_path) {
                if ($this->dropboxService->isEnabled()) {
                    return $candidate;
                }

                continue;
            }

            if ($this->shootFileAccessService->resolveLocalPath($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveArchiveEntryLocalPath(array &$entry): ?string
    {
        $sourcePath = $entry['source_path'] ?? null;
        if (!is_string($sourcePath) || trim($sourcePath) === '') {
            return null;
        }

        $localPath = $this->shootFileAccessService->resolveLocalPath($sourcePath);
        if ($localPath) {
            return $localPath;
        }

        $filename = basename($entry['archive_name'] ?? 'file');
        $downloaded = $this->shootFileAccessService->downloadDropboxPathToTemp($sourcePath, $filename);
        if ($downloaded) {
            $entry['temp_path'] = $downloaded;
        }

        return $downloaded;
    }

    protected function readManifest(Shoot $shoot, string $type, string $size): ?array
    {
        $manifestPath = $this->getManifestPath($shoot, $type, $size);
        if (!Storage::disk(self::ARCHIVE_DISK)->exists($manifestPath)) {
            return null;
        }

        try {
            $decoded = json_decode((string) Storage::disk(self::ARCHIVE_DISK)->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function writeManifest(Shoot $shoot, string $type, string $size, array $manifest): void
    {
        Storage::disk(self::ARCHIVE_DISK)->put(
            $this->getManifestPath($shoot, $type, $size),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function archiveExists(Shoot $shoot, string $type, string $size): bool
    {
        return Storage::disk(self::ARCHIVE_DISK)->exists($this->getArchivePath($shoot, $type, $size));
    }

    protected function buildPreparingMessage(string $size): string
    {
        return $size === 'small'
            ? 'Preparing your MLS files.'
            : 'Preparing your full-size files.';
    }

    protected function buildSourceSignature(array $entries): string
    {
        return hash('sha256', json_encode(array_map(function (array $entry) {
            return [
                'file_id' => $entry['file_id'],
                'archive_name' => $entry['archive_name'],
                'source_path' => $entry['source_path'],
                'updated_at' => $entry['updated_at'],
                'file_size' => $entry['file_size'],
            ];
        }, $entries), JSON_UNESCAPED_SLASHES));
    }

    protected function acquireGenerationLock(Shoot $shoot, string $type, string $size): bool
    {
        return Cache::add(
            $this->getGenerationLockKey($shoot, $type, $size),
            now()->toIso8601String(),
            now()->addSeconds(self::LOCK_TTL_SECONDS)
        );
    }

    protected function releaseGenerationLock(Shoot $shoot, string $type, string $size): void
    {
        Cache::forget($this->getGenerationLockKey($shoot, $type, $size));
    }

    protected function getGenerationLockKey(Shoot $shoot, string $type, string $size): string
    {
        return 'shoot-media-archive:' . $shoot->id . ':' . $this->normalizeType($type) . ':' . $this->normalizeSize($size);
    }

    protected function normalizeType(?string $type): string
    {
        return strtolower((string) $type) === 'edited' ? 'edited' : 'raw';
    }

    protected function resolveFrontendUrl(): string
    {
        try {
            $frontendUrl = trim((string) config('app.frontend_url', config('app.url', self::DEFAULT_FRONTEND_URL)));
        } catch (\Throwable $exception) {
            return self::DEFAULT_FRONTEND_URL;
        }

        return $frontendUrl !== '' ? $frontendUrl : self::DEFAULT_FRONTEND_URL;
    }

    public function buildAbsoluteApiUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) config('app.url', self::DEFAULT_API_URL), '/') . '/' . ltrim($path, '/');
    }
}
