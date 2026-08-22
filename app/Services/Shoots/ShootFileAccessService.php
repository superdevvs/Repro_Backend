<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootFileAccessService
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ImageProcessingService $imageProcessingService,
        protected MediaStorage $mediaStorage
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

        // R2-first delivery: raw originals and locked/unpaid media are served via
        // short-lived presigned URLs (never the public CDN domain) once reads are
        // flipped. Local remains a secondary fallback while both stores coexist.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            $key = $this->mediaStorage->normalizeKey($file->path);
            if ($key && $this->mediaStorage->existsOnR2($key)) {
                return $this->mediaStorage->temporaryUrl($key);
            }
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
        // When reads are flipped, derived previews live on the R2 CDN; resolve
        // them there without depending on the (possibly pruned) local disk.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            foreach ([$file->web_path ?? null, $file->thumbnail_path ?? null] as $candidate) {
                $key = $this->mediaStorage->normalizeKey($candidate);
                if ($key && $this->mediaStorage->existsOnR2($key)) {
                    return $this->mediaStorage->publicUrl($key);
                }
            }
        }

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

        // When reads are flipped to R2, public/delivered/preview assets are served
        // from the R2 public CDN custom domain. This is the single funnel for all
        // hand-built public URLs across presenter/preview/public-asset services.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            return $this->mediaStorage->publicUrl($path);
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
        $tempPath = null;

        try {
            $sourcePath = $this->findLocalFilePath($file);

            if (
                !$sourcePath
                && ($file->dropbox_path || $file->storage_path)
            ) {
                $tempPath = $this->dropboxService->downloadToTemp($file->dropbox_path ?: $file->storage_path);
                $sourcePath = $tempPath;
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
            // The `grid` rendition (600px) is what every card and tile displays.
            // processImageFromPath() has always produced it, but this repair path
            // dropped it on the floor, so a file rescued here kept serving the
            // 300px thumbnail to a 600px slot — the blur this pipeline exists to
            // avoid.
            if (!empty($generated['grid'])) {
                $updates['grid_path'] = $generated['grid'];
            }
            if (!empty($generated['web'])) {
                $updates['web_path'] = $generated['web'];
            }
            if (!empty($generated['placeholder'])) {
                $updates['placeholder_path'] = $generated['placeholder'];
            }
            if (!empty($updates)) {
                $updates['processed_at'] = now();
                $updates['processing_failed_at'] = null;
                $updates['processing_error'] = null;
                $file->update($updates);
            } else {
                $file->update([
                    'processing_failed_at' => now(),
                    'processing_error' => 'Unable to generate optimized preview.',
                ]);
            }

            return $generated;
        } catch (\Exception $e) {
            Log::warning('generateOptimizedVersions failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
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
