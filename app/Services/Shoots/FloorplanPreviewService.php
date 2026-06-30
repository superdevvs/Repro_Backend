<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Generates web-renderable preview images for floorplan ShootFiles so the UI can
 * show a thumbnail instead of an empty card.
 *
 *  - PDF floorplans: each page is rendered to a JPG via `pdftoppm` (poppler, already
 *    installed on the server). Page 1 becomes thumbnail_path/web_path; every page path
 *    is recorded in metadata.preview_images.
 *  - Image floorplans (jpg/png/webp): if no preview exists, thumbnail_path/web_path are
 *    pointed at the original image so it renders directly.
 *
 * The ORIGINAL file (path/storage_path) is never modified or deleted. Generation is
 * idempotent: deterministic output filenames + a skip when a preview already exists.
 */
class FloorplanPreviewService
{
    /** Seconds before a pdftoppm invocation is aborted. */
    private const PROCESS_TIMEOUT = 120;

    /** Render resolution (DPI) for PDF pages. */
    private const PDF_DPI = 150;

    /** Safety cap on pages rendered per PDF. */
    private const MAX_PAGES = 25;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Ensure a floorplan file has a renderable preview. Returns a summary array.
     *
     * @return array{status:string, thumbnail_path:?string, web_path:?string, preview_images:array<int,string>}
     */
    public function ensurePreview(ShootFile $file, bool $force = false): array
    {
        $result = [
            'status' => 'skipped',
            'thumbnail_path' => $file->thumbnail_path,
            'web_path' => $file->web_path,
            'preview_images' => $this->existingPreviewImages($file),
        ];

        if (strtolower((string) $file->media_type) !== 'floorplan') {
            $result['status'] = 'not_floorplan';
            return $result;
        }

        // Idempotency: already has a usable preview and not forced.
        if (!$force && !empty($file->web_path) && $this->diskHas($file->web_path)) {
            $result['status'] = 'already_present';
            return $result;
        }

        $disk = Storage::disk('public');
        $relativeSource = $this->normalizeDiskPath($file->path ?: $file->storage_path);

        if (!$relativeSource || !$disk->exists($relativeSource)) {
            Log::warning('FloorplanPreviewService: source file not found on public disk', [
                'shoot_file_id' => $file->id,
                'path' => $file->path,
                'storage_path' => $file->storage_path,
            ]);
            $result['status'] = 'source_missing';
            return $result;
        }

        $extension = strtolower(pathinfo((string) $file->filename, PATHINFO_EXTENSION)
            ?: pathinfo($relativeSource, PATHINFO_EXTENSION));
        $isPdf = $extension === 'pdf' || str_contains(strtolower((string) $file->file_type), 'pdf');
        $isImage = in_array($extension, self::IMAGE_EXTENSIONS, true)
            || str_starts_with(strtolower((string) $file->file_type), 'image/');

        // Normalize a generic/bad MIME based on the extension (e.g. CubiCasa downloads
        // arrive as binary/octet-stream). Safe: only when we recognise the extension.
        $this->normalizeMimeIfNeeded($file, $extension, $isPdf);

        if ($isPdf) {
            return $this->generatePdfPreviews($file, $relativeSource, $result);
        }

        if ($isImage) {
            // Point preview fields at the original image so the grid renders it.
            $file->thumbnail_path = $relativeSource;
            $file->web_path = $relativeSource;
            $file->save();

            $result['status'] = 'image_linked';
            $result['thumbnail_path'] = $relativeSource;
            $result['web_path'] = $relativeSource;
            return $result;
        }

        $result['status'] = 'unsupported_type';
        return $result;
    }

    /**
     * @param array{status:string, thumbnail_path:?string, web_path:?string, preview_images:array<int,string>} $result
     * @return array{status:string, thumbnail_path:?string, web_path:?string, preview_images:array<int,string>}
     */
    private function generatePdfPreviews(ShootFile $file, string $relativeSource, array $result): array
    {
        $disk = Storage::disk('public');
        $absoluteSource = $disk->path($relativeSource);

        $baseName = Str::slug(pathinfo((string) ($file->stored_filename ?: $file->filename), PATHINFO_FILENAME) ?: 'floorplan');
        $uniqueBase = sprintf('%s-%d', $baseName, $file->id);
        $previewDir = sprintf('shoots/%d/floorplans/previews', $file->shoot_id);

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/fp_' . Str::random(12);
        if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            Log::error('FloorplanPreviewService: failed to create temp dir', ['dir' => $tmpDir]);
            $result['status'] = 'temp_dir_failed';
            return $result;
        }

        try {
            $tmpPrefix = $tmpDir . '/page';
            // pdftoppm -jpeg -r 150 <src> <tmpPrefix>  =>  <tmpPrefix>-1.jpg, -2.jpg, ...
            $process = new Process([
                'pdftoppm',
                '-jpeg',
                '-r', (string) self::PDF_DPI,
                '-l', (string) self::MAX_PAGES,
                $absoluteSource,
                $tmpPrefix,
            ]);
            $process->setTimeout(self::PROCESS_TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $generated = glob($tmpPrefix . '-*.jpg') ?: [];
            natsort($generated);
            $generated = array_values($generated);

            if (empty($generated)) {
                Log::warning('FloorplanPreviewService: pdftoppm produced no pages', [
                    'shoot_file_id' => $file->id,
                ]);
                $result['status'] = 'no_pages';
                return $result;
            }

            $previewImages = [];
            $pageNum = 0;
            foreach ($generated as $tmpFile) {
                $pageNum++;
                $relativePreview = sprintf('%s/%s-p%d.jpg', $previewDir, $uniqueBase, $pageNum);
                $disk->put($relativePreview, file_get_contents($tmpFile));
                $previewImages[] = $relativePreview;
            }

            $primary = $previewImages[0];
            $metadata = is_array($file->metadata) ? $file->metadata : [];
            $metadata['preview_images'] = $previewImages;
            $metadata['preview_generated_at'] = now()->toIso8601String();
            $metadata['preview_page_count'] = count($previewImages);

            $file->thumbnail_path = $primary;
            $file->web_path = $primary;
            $file->metadata = $metadata;
            $file->save();

            $result['status'] = 'pdf_rendered';
            $result['thumbnail_path'] = $primary;
            $result['web_path'] = $primary;
            $result['preview_images'] = $previewImages;
            return $result;
        } catch (\Throwable $e) {
            Log::error('FloorplanPreviewService: PDF preview generation failed', [
                'shoot_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            $result['status'] = 'pdf_failed';
            return $result;
        } finally {
            $this->cleanupTmpDir($tmpDir);
        }
    }

    private function normalizeMimeIfNeeded(ShootFile $file, string $extension, bool $isPdf): void
    {
        $currentMime = strtolower((string) $file->file_type);
        $isBadMime = $currentMime === '' || $currentMime === 'binary/octet-stream' || $currentMime === 'application/octet-stream';
        if (!$isBadMime) {
            return;
        }

        $resolved = match (true) {
            $isPdf => 'application/pdf',
            in_array($extension, ['jpg', 'jpeg'], true) => 'image/jpeg',
            $extension === 'png' => 'image/png',
            $extension === 'webp' => 'image/webp',
            $extension === 'gif' => 'image/gif',
            default => null,
        };

        if ($resolved !== null && $resolved !== $currentMime) {
            $file->file_type = $resolved;
            if (\Illuminate\Support\Facades\Schema::hasColumn('shoot_files', 'mime_type')) {
                $file->mime_type = $resolved;
            }
            $file->save();
        }
    }

    /**
     * @return array<int,string>
     */
    private function existingPreviewImages(ShootFile $file): array
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $images = $metadata['preview_images'] ?? [];

        return is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
    }

    private function diskHas(?string $path): bool
    {
        $relative = $this->normalizeDiskPath($path);

        return $relative !== null && Storage::disk('public')->exists($relative);
    }

    private function normalizeDiskPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $clean = ltrim($path, '/');
        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                $clean = substr($clean, strlen($prefix));
                break;
            }
        }

        return $clean !== '' ? $clean : null;
    }

    private function cleanupTmpDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
