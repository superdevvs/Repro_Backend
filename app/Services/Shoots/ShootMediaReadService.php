<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Jobs\ProcessImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootMediaReadService
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootFileAccessService $shootFileAccessService,
        protected ImageProcessingService $imageProcessingService,
        protected ShootAuthorizationSupport $authorizationSupport,
        protected ShootPaymentStatusSupport $paymentStatusSupport,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected \App\Services\Media\MediaStorage $mediaStorage
    ) {
    }

    public function previewFileResponse(ShootFile $file, bool $needsWatermark = false)
    {
        if ($needsWatermark) {
            $file = $this->ensureWatermarkedPreviewAvailable($file);
            $watermarkedPreviewPath = $file->watermarked_web_path
                ?? $file->watermarked_thumbnail_path
                ?? $file->watermarked_placeholder_path;

            if ($watermarkedPreviewPath) {
                $response = $this->buildPreviewResponseFromPath($watermarkedPreviewPath);
                if ($response) {
                    return $response;
                }
            }

            if ($file->shouldBeWatermarked()) {
                $this->queueWatermark($file);

                return response()->json([
                    'message' => 'Watermarked preview is being generated. Please retry in a few minutes.',
                    'code' => 'watermark_processing',
                ], 409);
            }

            return response()->json(['message' => 'File not available'], 404);
        }

        // R2-first: the preview route is already access-controlled, so redirect to
        // a short-lived presigned URL (safe for raw + locked media) once reads are
        // flipped. Local file serving remains a secondary fallback.
        if ($file->path && ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only())) {
            $key = $this->mediaStorage->normalizeKey($file->path);
            if ($key && $this->mediaStorage->existsOnR2($key)) {
                return redirect($this->mediaStorage->temporaryUrl($key));
            }
        }

        if ($file->path && Storage::disk('public')->exists($file->path)) {
            $path = Storage::disk('public')->path($file->path);
            $mimeType = mime_content_type($path) ?: 'image/jpeg';

            return response()->file($path, ['Content-Type' => $mimeType]);
        }

        if ($file->url && Str::startsWith($file->url, 'http')) {
            return redirect($file->url);
        }

        if ($file->dropbox_path) {
            try {
                $url = $this->dropboxService->getTemporaryLink($file->dropbox_path);
                if ($url) {
                    return redirect($url);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox preview link', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'File not available'], 404);
    }

    public function getFilesPayload(Shoot $shoot, Request $request): array
    {
        $type = strtolower((string) $request->query('type', ''));
        $user = $request->user();
        $userId = $user ? $user->id : 'guest';
        $userRole = $user ? $user->role : 'guest';
        $filesUpdatedAt = (string) $shoot->files()->max('updated_at');
        $serviceItemsUpdatedAt = (string) $shoot->serviceItems()->max('updated_at');
        $cacheKey = 'shoot_files_' . $shoot->id . '_' . $type . '_' . $userId . '_' . $userRole . '_' . md5(
            implode('|', [
                (string) $shoot->updated_at,
                $filesUpdatedAt,
                $serviceItemsUpdatedAt,
                (string) $shoot->payment_status,
                (string) $shoot->delivery_status,
            ])
        );

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['data' => $cached];
        }

        Log::debug('getFiles called', [
            'shoot_id' => $shoot->id,
            'user_id' => $userId,
            'user_role' => $userRole,
            'type' => $type,
        ]);

        $filesQuery = $shoot->files()->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');
        $isClientUser = $this->authorizationSupport->isClientUser($user);
        if ($isClientUser) {
            $filesQuery->where(function ($query) {
                $query->where('is_hidden', false)->orWhereNull('is_hidden');
            });
        }

        if ($isClientUser) {
            $filesQuery->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        } elseif ($type === 'raw') {
            $filesQuery->where(function ($query) {
                $query->where('workflow_stage', ShootFile::STAGE_TODO)->orWhereNull('workflow_stage');
            });
        } elseif ($type === 'edited') {
            $filesQuery->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        }

        $files = $filesQuery->get();
        if ($type === 'raw') {
            $files = $files->filter(fn (ShootFile $file) => $file->isRequiredForEditing())->values();
        }
        if ($user && $user->role === 'editor') {
            $files = app(ShootEditingAssignmentService::class)->filterFilesForEditor($files, $shoot, $user);
        }
        if ($user && $user->role === 'photographer') {
            $files = $this->filterFilesForPhotographer($files, $shoot, $user);
        }
        if ($type === 'edited') {
            $files = $files
                ->reject(fn (ShootFile $file) => $this->authorizationSupport->isRawCameraFile($file))
                ->values();
        }

        $dropboxUrls = $this->resolveDropboxUrls($files);
        $needsWatermark = $this->needsWatermark($shoot, $user);

        $formattedFiles = $files->map(function (ShootFile $file) use ($shoot, $user, $dropboxUrls, $needsWatermark) {
            $fileNeedsWatermark = $needsWatermark
                && $this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user);

            return $this->formatFile($file, $dropboxUrls, $fileNeedsWatermark);
        })->values()->all();

        Cache::put($cacheKey, $formattedFiles, now()->addSeconds(30));

        return [
            'data' => $formattedFiles,
            'count' => count($formattedFiles),
        ];
    }

    public function listMediaPayload(Shoot $shoot, string $type, ?User $user = null): array
    {
        if ($user && $user->role === 'editor') {
            $filesPayload = $this->getEditorScopedMediaPayload($shoot, $type, $user);

            return [
                'data' => $filesPayload,
                'counts' => [
                    'raw_photo_count' => $shoot->raw_photo_count,
                    'edited_photo_count' => $shoot->edited_photo_count,
                    'extra_photo_count' => $shoot->extra_photo_count,
                    'expected_raw_count' => $shoot->expected_raw_count,
                    'expected_final_count' => $shoot->expected_final_count,
                    'raw_missing_count' => $shoot->raw_missing_count,
                    'edited_missing_count' => $shoot->edited_missing_count,
                    'bracket_mode' => $shoot->bracket_mode,
                ],
            ];
        }

        if ($user && $user->role === 'photographer') {
            $filesPayload = $this->getPhotographerScopedMediaPayload($shoot, $type, $user);

            return [
                'data' => $filesPayload,
                'counts' => [
                    'raw_photo_count' => $shoot->raw_photo_count,
                    'edited_photo_count' => $shoot->edited_photo_count,
                    'extra_photo_count' => $shoot->extra_photo_count,
                    'expected_raw_count' => $shoot->expected_raw_count,
                    'expected_final_count' => $shoot->expected_final_count,
                    'raw_missing_count' => $shoot->raw_missing_count,
                    'edited_missing_count' => $shoot->edited_missing_count,
                    'bracket_mode' => $shoot->bracket_mode,
                ],
            ];
        }

        return [
            'data' => $this->dropboxService->listShootFiles($shoot, $type),
            'counts' => [
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'extra_photo_count' => $shoot->extra_photo_count,
                'expected_raw_count' => $shoot->expected_raw_count,
                'expected_final_count' => $shoot->expected_final_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
                'bracket_mode' => $shoot->bracket_mode,
            ],
        ];
    }

    public function resolveBulkDownloadUrls(Shoot $shoot, array $fileIds): array
    {
        $files = $shoot->files()->whereIn('id', $fileIds)->get();

        return $files->map(fn (ShootFile $file) => $this->shootFileAccessService->resolveFileUrl($file))
            ->filter()
            ->values()
            ->all();
    }

    protected function getEditorScopedMediaPayload(Shoot $shoot, string $type, User $user): array
    {
        $normalizedType = strtolower($type);
        $filesQuery = $shoot->files()->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');

        if ($normalizedType === 'raw') {
            $filesQuery->where(function ($query) {
                $query->where('workflow_stage', ShootFile::STAGE_TODO)->orWhereNull('workflow_stage');
            });
        } elseif ($normalizedType === 'edited') {
            $filesQuery->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        }

        $files = app(ShootEditingAssignmentService::class)->filterFilesForEditor(
            $filesQuery->get(),
            $shoot,
            $user
        );

        if ($normalizedType === 'raw') {
            $files = $files->filter(fn (ShootFile $file) => $file->isRequiredForEditing())->values();
        }

        if ($normalizedType === 'edited') {
            $files = $files
                ->reject(fn (ShootFile $file) => $this->authorizationSupport->isRawCameraFile($file))
                ->values();
        }

        $dropboxUrls = $this->resolveDropboxUrls($files);

        return $files->map(function (ShootFile $file) use ($dropboxUrls) {
            return $this->formatFile($file, $dropboxUrls, false);
        })->values()->all();
    }

    protected function getPhotographerScopedMediaPayload(Shoot $shoot, string $type, User $user): array
    {
        $normalizedType = strtolower($type);
        $filesQuery = $shoot->files()->orderBy('sort_order', 'asc')->orderBy('created_at', 'desc');

        if ($normalizedType === 'raw') {
            $filesQuery->where(function ($query) {
                $query->where('workflow_stage', ShootFile::STAGE_TODO)->orWhereNull('workflow_stage');
            });
        } elseif ($normalizedType === 'edited') {
            $filesQuery->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]);
        }

        $files = $this->filterFilesForPhotographer($filesQuery->get(), $shoot, $user);

        if ($normalizedType === 'raw') {
            $files = $files->filter(fn (ShootFile $file) => $file->isRequiredForEditing())->values();
        }

        if ($normalizedType === 'edited') {
            $files = $files
                ->reject(fn (ShootFile $file) => $this->authorizationSupport->isRawCameraFile($file))
                ->values();
        }

        $dropboxUrls = $this->resolveDropboxUrls($files);

        return $files->map(function (ShootFile $file) use ($dropboxUrls) {
            return $this->formatFile($file, $dropboxUrls, false);
        })->values()->all();
    }

    protected function filterFilesForPhotographer(Collection $files, Shoot $shoot, User $user): Collection
    {
        return $files
            ->filter(fn (ShootFile $file) => $this->authorizationSupport->canPhotographerAccessFile($shoot, $file, $user))
            ->values();
    }

    protected function resolveDropboxUrls($files): array
    {
        $dropboxUrls = [];
        $dropboxFiles = $files->filter(fn (ShootFile $file) => $file->dropbox_path && !$file->url && !$file->path);

        foreach ($dropboxFiles as $file) {
            try {
                $urlCacheKey = 'dropbox_url_' . md5($file->dropbox_path);
                $url = Cache::remember($urlCacheKey, now()->addHours(4), function () use ($file) {
                    return $this->dropboxService->getTemporaryLink($file->dropbox_path);
                });
                if ($url) {
                    $dropboxUrls[$file->id] = $url;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox link', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dropboxUrls;
    }

    protected function needsWatermark(Shoot $shoot, ?User $user): bool
    {
        $isClient = $this->authorizationSupport->isClientUser($user);
        $paymentStatus = $shoot->payment_status;
        if (!$paymentStatus || $paymentStatus === 'pending') {
            $paymentStatus = $this->paymentStatusSupport->calculatePaymentStatus(
                (float) ($shoot->total_paid ?? 0),
                (float) ($shoot->total_quote ?? 0)
            );
        }

        return $isClient && !$shoot->bypass_paywall && $paymentStatus !== 'paid';
    }

    protected function formatFile(ShootFile $file, array $dropboxUrls, bool $needsWatermark): array
    {
        $needsWatermark = $needsWatermark && $file->shouldBeWatermarked();
        $url = null;
        $thumbUrl = null;
        $mediumUrl = null;
        $largeUrl = null;
        $originalUrl = null;
        $webUrl = null;
        $placeholderUrl = null;

        if ($needsWatermark) {
            $file = $this->ensureWatermarkedPreviewAvailable($file);
            $thumbUrl = $this->resolvePreviewPath($file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path);
            $mediumUrl = $this->resolvePreviewPath(
                $file->watermarked_web_path ?? $file->watermarked_thumbnail_path ?? $file->watermarked_placeholder_path
            );
            $webUrl = $mediumUrl;
            $largeUrl = $mediumUrl;
            $url = $mediumUrl ?? $thumbUrl;
            $originalUrl = $url;
            $placeholderUrl = $this->resolvePreviewPath($file->watermarked_placeholder_path);

            if (!$thumbUrl && !$mediumUrl && $file->shouldBeWatermarked()) {
                $this->queueWatermark($file);
            }
        } else {
            if ($this->shouldQueueRawPreviewRefresh($file)) {
                $this->queueRawPreviewRefresh($file);
            }

            if ($this->shouldGenerateOptimizedPreview($file)) {
                $this->shootFileAccessService->generateOptimizedVersions($file);
                $file->refresh();
            }

            $originalUrl = $dropboxUrls[$file->id] ?? $this->shootFileAccessService->resolveFileUrl($file, true);
            if (!$originalUrl && $this->isVideoFile($file)) {
                $originalUrl = url('/api/shoots/' . $file->shoot_id . '/files/' . $file->id . '/preview');
            }
            $thumbUrl = $this->resolvePreviewPath($file->thumbnail_path ?? $file->placeholder_path);
            $webUrl = $this->resolvePreviewPath($file->web_path);
            $mediumUrl = $webUrl;
            $largeUrl = $webUrl;
            $placeholderUrl = $this->resolvePreviewPath($file->placeholder_path);

            if (!$webUrl && $this->shouldGenerateOptimizedPreview($file)) {
                $generated = $this->shootFileAccessService->generateOptimizedVersions($file);
                if (!empty($generated)) {
                    $file->refresh();
                    $thumbUrl = $this->resolvePreviewPath(
                        $generated['thumbnail'] ?? $file->thumbnail_path ?? $file->placeholder_path
                    );
                    $webUrl = $this->resolvePreviewPath($generated['web'] ?? $file->web_path);
                    $mediumUrl = $webUrl;
                    $largeUrl = $webUrl;
                    $placeholderUrl = $this->resolvePreviewPath($generated['placeholder'] ?? $file->placeholder_path);
                }
            }

            $url = $webUrl ?? $thumbUrl ?? $placeholderUrl ?? $originalUrl;

            if (!$thumbUrl) {
                $thumbUrl = $webUrl ?? $placeholderUrl ?? $originalUrl;
            }
        }

        $fileData = [
            'id' => $file->id,
            'shoot_id' => $file->shoot_id,
            'shoot_service_id' => $file->shoot_service_id,
            'shootServiceId' => $file->shoot_service_id,
            'filename' => $file->filename ?? $file->stored_filename ?? 'unknown',
            'stored_filename' => $file->stored_filename,
            'url' => $url,
            'path' => $needsWatermark ? null : $file->path,
            'file_type' => $file->file_type ?? $file->mime_type,
            'fileType' => $file->file_type ?? $file->mime_type,
            'workflow_stage' => $file->workflow_stage,
            'workflowStage' => $file->workflow_stage,
            'is_extra' => $file->isExtra(),
            'isExtra' => $file->isExtra(),
            'required_for_editing' => $file->isRequiredForEditing(),
            'requiredForEditing' => $file->isRequiredForEditing(),
            // Virus-scan state machine (Req 14/15). Surfaced on every file payload so
            // the admin Dashboard can render a scan-status badge and gate the retry
            // control (Req 15.5/15.8). Values are the four canonical scan_status
            // strings (`quarantined`/`clean`/`infected`/`failed`); the frontend
            // maps `quarantined` to a "scanning" label.
            'scan_status' => $file->scan_status,
            'scanStatus' => $file->scan_status,
            'is_cover' => $file->is_cover ?? false,
            'is_favorite' => $file->is_favorite ?? false,
            'file_size' => $file->file_size,
            'fileSize' => $file->file_size,
            'sort_order' => $file->sort_order ?? 0,
            'bracket_group' => $file->bracket_group,
            'sequence' => $file->sequence,
            'is_hidden' => $file->is_hidden ?? false,
            'media_type' => $file->media_type,
            'thumbnail_path' => $needsWatermark ? null : $file->thumbnail_path,
            'web_path' => $needsWatermark ? null : $file->web_path,
            'placeholder_path' => $needsWatermark ? null : $file->placeholder_path,
            'uses_watermark' => $needsWatermark,
            'processed_at' => $file->processed_at,
            'created_at' => $file->created_at?->toIso8601String(),
            'uploaded_at' => $file->uploaded_at?->toIso8601String() ?? $file->created_at?->toIso8601String(),
        ];

        if ($needsWatermark) {
            $fileData['watermarked_storage_path'] = $file->watermarked_storage_path;
            $fileData['watermarked_thumbnail_path'] = $thumbUrl;
            $fileData['watermarked_web_path'] = $webUrl;
            $fileData['watermarked_placeholder_path'] = $placeholderUrl;
        }

        foreach ([
            'thumbnail_url' => $thumbUrl,
            'thumb_url' => $thumbUrl,
            'thumb' => $thumbUrl,
            'web_url' => $webUrl,
            'medium_url' => $mediumUrl,
            'medium' => $mediumUrl,
            'large_url' => $largeUrl,
            'large' => $largeUrl,
            'placeholder_url' => $placeholderUrl,
            'original_url' => $originalUrl,
            'original' => $originalUrl,
        ] as $key => $value) {
            if ($value) {
                $fileData[$key] = $value;
            }
        }

        $comments = $this->extractComments($file);
        $latestComment = !empty($comments) ? $comments[count($comments) - 1] : null;
        if (!empty($comments)) {
            $fileData['comments'] = $comments;
            $fileData['comment_count'] = count($comments);
            $fileData['latest_comment'] = $latestComment;
        }

        if (is_array($file->metadata)) {
            foreach (['width', 'height', 'captured_at'] as $key) {
                if (array_key_exists($key, $file->metadata)) {
                    $fileData[$key] = $file->metadata[$key];
                }
            }
        }

        return $fileData;
    }

    protected function ensureWatermarkedPreviewAvailable(ShootFile $file): ShootFile
    {
        if ($this->hasWatermarkedPreview($file) || !$file->shouldBeWatermarked() || !$this->canGenerateWatermarkedPreview($file)) {
            return $file;
        }

        try {
            $freshFile = $file->fresh();
            if (!$freshFile) {
                return $file;
            }

            $watermarkJob = new GenerateWatermarkedImageJob($freshFile);
            $watermarkJob->handle($this->dropboxService);
            $file->refresh();
        } catch (\Throwable $e) {
            Log::warning('Failed to generate watermark synchronously for shoot media preview', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $file;
    }

    protected function hasWatermarkedPreview(ShootFile $file): bool
    {
        return (bool) (
            $file->watermarked_web_path
            || $file->watermarked_thumbnail_path
            || $file->watermarked_placeholder_path
        );
    }

    protected function canGenerateWatermarkedPreview(ShootFile $file): bool
    {
        $mediaType = strtolower((string) ($file->media_type ?? ''));
        if (in_array($mediaType, ['image', 'photo', 'edited'], true)) {
            return true;
        }

        $mimeType = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if (Str::startsWith($mimeType, 'image/')) {
            return true;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? $file->storage_path ?? ''));

        return (bool) preg_match('/\.(jpg|jpeg|png|webp|gif|tif|tiff|heic|heif)$/i', $filename);
    }

    protected function isVideoFile(ShootFile $file): bool
    {
        $mediaType = strtolower((string) ($file->media_type ?? ''));
        if ($mediaType === 'video') {
            return true;
        }

        $mimeType = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if (str_starts_with($mimeType, 'video/')) {
            return true;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? $file->storage_path ?? ''));

        return (bool) preg_match('/\.(mp4|mov|m4v|avi|mkv|wmv|webm|mpg|mpeg|3gp)(?:$|[?#])/', $filename);
    }

    protected function extractComments(ShootFile $file): array
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $comments = $metadata['comments'] ?? [];
        if (!is_array($comments)) {
            return [];
        }

        return collect($comments)
            ->filter(fn ($comment) => is_array($comment) && trim((string) ($comment['comment'] ?? '')) !== '')
            ->map(fn (array $comment) => [
                'author' => isset($comment['author']) ? (string) $comment['author'] : null,
                'comment' => trim((string) ($comment['comment'] ?? '')),
                'timestamp' => isset($comment['timestamp']) ? (string) $comment['timestamp'] : null,
            ])
            ->values()
            ->all();
    }

    protected function resolvePreviewPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (Str::startsWith($clean, 'storage/')) {
            $clean = substr($clean, 8);
        }

        // Preview/derived assets resolve to the R2 CDN once reads are flipped.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            if ($this->mediaStorage->existsOnR2($clean)) {
                return $this->shootFileAccessService->resolvePublicStorageUrl($clean);
            }
            if ($this->mediaStorage->r2Only()) {
                return null;
            }
        }

        if (Storage::disk('public')->exists($clean)) {
            return $this->shootFileAccessService->resolvePublicStorageUrl($clean);
        }

        try {
            return $this->dropboxService->getTemporaryLink($path);
        } catch (\Exception $e) {
            Log::warning('Failed to resolve preview path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function buildPreviewResponseFromPath(?string $path)
    {
        if (!$path) {
            return null;
        }

        // R2-first: watermarked/derived previews are served from the CDN when reads
        // are flipped (cacheable, safe to expose), falling back to local streaming.
        if ($this->mediaStorage->readFromR2Enabled() || $this->mediaStorage->r2Only()) {
            $key = $this->mediaStorage->normalizeKey($path);
            if ($key && $this->mediaStorage->existsOnR2($key)) {
                return redirect($this->mediaStorage->publicUrl($key));
            }
        }

        $localPath = $this->shootFileAccessService->resolveLocalPath($path);
        if ($localPath && file_exists($localPath)) {
            $mimeType = mime_content_type($localPath) ?: 'image/jpeg';

            return response()->file($localPath, ['Content-Type' => $mimeType]);
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return redirect($path);
        }

        $resolvedUrl = $this->resolvePreviewPath($path);
        if ($resolvedUrl) {
            return redirect($resolvedUrl);
        }

        return null;
    }

    protected function queueWatermark(ShootFile $file): void
    {
        try {
            \App\Jobs\GenerateWatermarkedImageJob::dispatch($file->fresh())->onQueue('watermarks');
        } catch (\Exception $e) {
            Log::warning('Failed to queue watermark job', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function shouldGenerateOptimizedPreview(ShootFile $file): bool
    {
        if (!$this->canGenerateOptimizedPreview($file)) {
            return false;
        }

        if ($file->processing_failed_at?->gt(now()->subMinutes(30))) {
            return false;
        }

        return empty($file->web_path) || empty($file->thumbnail_path);
    }

    protected function canGenerateOptimizedPreview(ShootFile $file): bool
    {
        if ($this->authorizationSupport->isRawCameraFile($file)) {
            return false;
        }

        $mimeType = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if ($mimeType !== '' && !Str::startsWith($mimeType, 'image/')) {
            return false;
        }

        $filename = strtolower((string) ($file->filename ?? $file->stored_filename ?? $file->path ?? ''));
        if ($filename === '') {
            return false;
        }

        return (bool) preg_match('/\.(jpg|jpeg|png|webp|gif|tif|tiff|heic|heif)$/i', $filename);
    }

    protected function shouldQueueRawPreviewRefresh(ShootFile $file): bool
    {
        if (!$this->authorizationSupport->isRawCameraFile($file)) {
            return false;
        }

        if ($file->processing_failed_at?->gt(now()->subMinutes(5))) {
            return false;
        }

        return $this->imageProcessingService->needsPreviewRegeneration($file);
    }

    protected function queueRawPreviewRefresh(ShootFile $file): void
    {
        $cacheKey = 'raw_preview_refresh_' . $file->id;

        if (!Cache::add($cacheKey, true, now()->addMinutes(5))) {
            return;
        }

        try {
            ProcessImageJob::dispatch($file->fresh());
        } catch (\Exception $e) {
            Cache::forget($cacheKey);
            Log::warning('Failed to queue RAW preview refresh', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
