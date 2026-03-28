<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\ImageProcessingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootFileAccessService
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ImageProcessingService $imageProcessingService
    ) {
    }

    public function resolveFileUrl(?ShootFile $file, bool $allowDropboxCalls = true): ?string
    {
        if (!$file) {
            return null;
        }

        if ($file->url) {
            return $file->url;
        }

        if ($file->path && Str::startsWith($file->path, 'http')) {
            return $file->path;
        }

        if ($file->path && Storage::disk('public')->exists($file->path)) {
            return $this->resolvePublicStorageUrl($file->path);
        }

        if ($file->path && !Str::startsWith($file->path, 'http') && !$file->dropbox_path) {
            return $this->resolvePublicStorageUrl($file->path);
        }

        if ($file->dropbox_path && $allowDropboxCalls) {
            try {
                return $this->dropboxService->getTemporaryLink($file->dropbox_path);
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox link', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($file->dropbox_path && !$allowDropboxCalls) {
            return url('/api/shoots/' . $file->shoot_id . '/files/' . $file->id . '/preview');
        }

        return null;
    }

    public function resolveOptimizedFileUrl(ShootFile $file): ?string
    {
        if (!empty($file->web_path) && Storage::disk('public')->exists($file->web_path)) {
            return $this->resolvePublicStorageUrl($file->web_path);
        }

        if (!empty($file->thumbnail_path) && Storage::disk('public')->exists($file->thumbnail_path)) {
            return $this->resolvePublicStorageUrl($file->thumbnail_path);
        }

        $generated = $this->generateOptimizedVersions($file);
        if (!empty($generated['web'])) {
            return $this->resolvePublicStorageUrl($generated['web']);
        }
        if (!empty($generated['thumbnail'])) {
            return $this->resolvePublicStorageUrl($generated['thumbnail']);
        }

        return $this->resolveFileUrl($file);
    }

    public function resolvePublicStorageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = Str::after($clean, 'storage/');
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $clean)));

        return $this->makeAbsoluteAppUrl('/storage/' . $encoded);
    }

    protected function makeAbsoluteAppUrl(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');

        if (app()->bound('request') && request()) {
            return rtrim(request()->getSchemeAndHttpHost(), '/') . $normalizedPath;
        }

        return rtrim((string) config('app.url'), '/') . $normalizedPath;
    }

    public function generateOptimizedVersions(ShootFile $file): array
    {
        try {
            $sourcePath = null;

            if ($file->path && !Str::startsWith($file->path, 'http') && Storage::disk('public')->exists($file->path)) {
                $sourcePath = Storage::disk('public')->path($file->path);
            }

            if (!$sourcePath || !file_exists($sourcePath)) {
                return [];
            }

            $fileName = $file->filename ?? ($file->path ? basename($file->path) : null);
            if (!$fileName) {
                return [];
            }

            $generated = $this->imageProcessingService->processImageFromPath(
                $file->shoot_id,
                $fileName,
                $sourcePath
            );

            $updates = [];
            if (!empty($generated['thumbnail'])) {
                $updates['thumbnail_path'] = $generated['thumbnail'];
            }
            if (!empty($generated['web'])) {
                $updates['web_path'] = $generated['web'];
            }
            if (!empty($generated['placeholder'])) {
                $updates['placeholder_path'] = $generated['placeholder'];
            }
            if (!empty($updates)) {
                $updates['processed_at'] = now();
                $file->update($updates);
            }

            return $generated;
        } catch (\Exception $e) {
            Log::warning('generateOptimizedVersions failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function resolveLocalPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return null;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = Str::after($clean, 'storage/');
        }

        if (Storage::disk('public')->exists($clean)) {
            return Storage::disk('public')->path($clean);
        }

        if (Storage::disk('local')->exists($clean)) {
            return Storage::disk('local')->path($clean);
        }

        $storagePath = storage_path('app/' . $clean);
        if (file_exists($storagePath)) {
            return $storagePath;
        }

        $publicPath = storage_path('app/public/' . $clean);
        if (file_exists($publicPath)) {
            return $publicPath;
        }

        return file_exists($path) ? $path : null;
    }

    public function findLocalFilePath(ShootFile $file): ?string
    {
        foreach ([
            $file->storage_path ?? null,
            $file->path ?? null,
        ] as $candidate) {
            if (!$candidate) {
                continue;
            }

            if (file_exists($candidate)) {
                return $candidate;
            }

            $resolved = $this->resolveLocalPath($candidate);
            if ($resolved && file_exists($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    public function downloadFromDropbox(ShootFile $file): ?string
    {
        if (!$this->dropboxService->isEnabled() || empty($file->dropbox_path)) {
            return null;
        }

        return $this->downloadDropboxPathToTemp($file->dropbox_path, $file->filename ?? 'file');
    }

    public function downloadDropboxPathToTemp(string $dropboxPath, string $filename, bool $useContentApi = false): ?string
    {
        try {
            $tempPath = storage_path('app/temp/download-' . uniqid() . '-' . $filename);
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            $contents = $useContentApi
                ? $this->dropboxService->downloadFileContent($dropboxPath)
                : $this->dropboxService->downloadFile($dropboxPath);

            if ($contents) {
                file_put_contents($tempPath, $contents);

                return $tempPath;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to download file from Dropbox', [
                'dropbox_path' => $dropboxPath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
