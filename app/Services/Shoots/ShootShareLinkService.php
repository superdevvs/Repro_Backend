<?php

namespace App\Services\Shoots;

use App\Jobs\GenerateShootShareLinkZipJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootShareLink;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\ShootActivityLogger;
use App\Services\ShortLinks\ShortLinkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShootShareLinkService
{
    private const MEDIA_STAGE_RAW = 'raw';
    private const MEDIA_STAGE_EDITED = 'edited';
    private const MEDIA_STAGE_RAW_PHOTO = 'raw_photo';
    private const MEDIA_STAGE_RAW_VIDEO = 'raw_video';

    public function __construct(
        protected ShootMediaStorageService $mediaStorageService,
        protected ShootActivityLogger $activityLogger,
        protected ShootFileAccessService $fileAccessService,
        protected DeliveryFilenameFormatter $deliveryFilenameFormatter,
        protected ?ShortLinkService $shortLinks = null
    ) {
        $this->shortLinks ??= app(ShortLinkService::class);
    }

    public function generateFilesZip(Shoot $shoot, $files): ?string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'shoot-share-');
        if ($zipPath === false) {
            throw new \RuntimeException('Failed to create temporary ZIP file');
        }
        $tempFiles = [];
        $zip = new \ZipArchive;
        $opened = false;
        try {
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create ZIP file');
            }
            $opened = true;
            $addedFiles = 0;
            $total = is_countable($files) ? count($files) : collect($files)->count();
            $position = 1;
            $usedNames = [];
            foreach ($files as $file) {
                $localPath = $this->fileAccessService->findLocalFilePath($file);
                if (! $localPath) {
                    $localPath = $this->fileAccessService->downloadStoredFileToTemp($file->path ?: $file->storage_path);
                    if ($localPath) {
                        $tempFiles[] = $localPath;
                    }
                }
                if (! $localPath || ! is_file($localPath)) {
                    throw new \RuntimeException('An archive source is unavailable');
                }
                app(\App\Services\Media\ArchiveCompressionPolicy::class)->addFile($zip, $localPath, $this->deliveryFilenameFormatter->deduplicate(
                    $this->deliveryFilenameFormatter->archivePathForFile($file, $position, $total, basename($localPath)), $usedNames
                ), (int) $shoot->id);
                $addedFiles++;
                $position++;
            }
            $closed = $zip->close();
            $opened = false;
            if ($addedFiles === 0) {
                @unlink($zipPath);
                return null;
            }
            if (! $closed) {
                throw new \RuntimeException('Failed to finish ZIP file');
            }
            return $zipPath;
        } catch (\Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            @unlink($zipPath);
            throw $exception;
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /** @deprecated Use generateFilesZip. */
    public function generateFilesZipWithDropboxFallback(Shoot $shoot, $files): ?string
    {
        return $this->generateFilesZip($shoot, $files);
    }

    /**
     * Select the files that may be shared with an editor for the given media stage.
     *
     * Non-required extras are withheld so they remain hidden from editors,
     * consistent with the editing payload rule (Req 13.2). Required extras
     * (required_for_editing = true) and standard files remain included (Req 13.3).
     *
     * @param  array<int, int|string>  $fileIds
     * @return \Illuminate\Support\Collection<int, ShootFile>
     */
    public function selectEditorShareFiles(Shoot $shoot, string $normalizedMediaStage, array $fileIds = [], ?User $editor = null): \Illuminate\Support\Collection
    {
        $isEditedStage = $normalizedMediaStage === self::MEDIA_STAGE_EDITED;
        $workflowStages = $isEditedStage
            ? [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]
            : [ShootFile::STAGE_TODO];

        $filesQuery = $shoot->files()->whereIn('workflow_stage', $workflowStages);
        if ($normalizedMediaStage === self::MEDIA_STAGE_RAW_VIDEO) {
            $filesQuery->where(function ($query) {
                $query->where('media_type', 'video')
                    ->orWhere('file_type', 'like', 'video/%');
            });
        } elseif ($normalizedMediaStage === self::MEDIA_STAGE_RAW_PHOTO) {
            $filesQuery->where(function ($query) {
                $query->whereNull('media_type')
                    ->orWhere('media_type', '!=', 'video');
            })->where(function ($query) {
                $query->whereNull('file_type')
                    ->orWhere('file_type', 'not like', 'video/%');
            });
        }
        if (!empty($fileIds)) {
            $filesQuery->whereIn('id', $fileIds);
        }

        $files = $filesQuery->inDeliveryOrder()->get()
            ->filter(fn (ShootFile $file) => $file->isRequiredForEditing())
            ->reject(fn (ShootFile $file) => $file->isBlockedFromDelivery() || $file->is_hidden || $file->isIguideOfflinePackage())
            ->values();

        // Lane-scoped editors (video_editor_id vs editor_id) only share their lane.
        if ($editor && $editor->role === 'editor') {
            $files = app(ShootEditingAssignmentService::class)
                ->filterFilesForEditor($files, $shoot, $editor);
        }

        return $files;
    }

    public function createShootShareLink(
        Shoot $shoot,
        User $user,
        array $fileIds = [],
        string $mediaStage = self::MEDIA_STAGE_RAW
    ): array
    {
        $normalizedMediaStage = $this->normalizeMediaStage($mediaStage);
        $normalizedMediaStage = $this->coerceMediaStageForEditor($shoot, $user, $normalizedMediaStage);
        $isEditedStage = $normalizedMediaStage === self::MEDIA_STAGE_EDITED;
        $stageLabel = $isEditedStage ? 'edited' : 'raw';

        $files = $this->selectEditorShareFiles($shoot, $normalizedMediaStage, $fileIds, $user);
        $fileCount = $files->count();

        if (!empty($fileIds) && $fileCount === 0) {
            throw new \App\Exceptions\PublicBusinessRuleException("No {$stageLabel} files found for selected IDs");
        }

        if ($files->isEmpty()) {
            throw new \App\Exceptions\PublicBusinessRuleException("No {$stageLabel} files found to share");
        }

        // Full raw hand-offs reuse the async media-archive pipeline (same ZIP
        // admins/photographers download). That avoids packing multi-GB archives
        // inside the editor's generate-share-link request (nginx 499/502).
        $archiveService = app(ShootMediaArchiveService::class);
        $canReuseRawArchive = $normalizedMediaStage === self::MEDIA_STAGE_RAW
            && $this->fileIdSetsMatch($files, $archiveService->getFilesForType($shoot, 'raw'));

        if ($canReuseRawArchive) {
            $archiveFresh = $archiveService->hasFreshArchive($shoot, 'raw', 'original');
            if (! $archiveFresh) {
                $archiveService->queueArchiveGeneration($shoot, 'raw', 'original');
            }
            $expiresAt = now()->addDays(7);
            $publicArchiveUrl = $archiveService->buildPublicDownloadUrl($shoot, 'raw', 'original', $expiresAt);

            try {
                $shareLinkRecord = ShootShareLink::create([
                    'shoot_id' => $shoot->id,
                    'created_by' => $user->id,
                    'share_url' => $publicArchiveUrl,
                    'media_stage' => $normalizedMediaStage,
                    'dropbox_path' => null,
                    'download_count' => 0,
                    'expires_at' => $expiresAt,
                ]);
                $shareLinkId = $shareLinkRecord->id;
                $expiresAtIso = $shareLinkRecord->expires_at?->toIso8601String();
            } catch (\Exception $dbError) {
                Log::warning('Could not save archive-backed share link to database', ['error' => $dbError->getMessage()]);
                $shareLinkRecord = null;
                $shareLinkId = null;
                $expiresAtIso = $expiresAt->toIso8601String();
            }

            $this->activityLogger->log(
                $shoot,
                'share_link_generated',
                [
                    'editor_id' => $user->id,
                    'editor_name' => $user->name,
                    'file_count' => $fileCount,
                    'media_stage' => $normalizedMediaStage,
                    'expires_in_hours' => null,
                    'archive_backed' => true,
                ],
                $user
            );

            $payload = [
                'share_link' => $shareLinkId ? $this->buildPublicShareUrl($shareLinkRecord) : $publicArchiveUrl,
                'share_link_id' => $shareLinkId,
                'media_stage' => $normalizedMediaStage,
                'file_count' => $fileCount,
                'expires_in_hours' => null,
                'expires_at' => $expiresAtIso,
                'archive_backed' => true,
            ];

            if (! $archiveFresh) {
                $payload['type'] = 'preparing';
                $payload['message'] = 'Preparing your share link.';
                $payload['poll_after_ms'] = 3000;
            }

            return $payload;
        }

        // Selected / lane-specific / edited shares still need a dedicated ZIP.
        // Create the DB row immediately and build the package on the queue so
        // the HTTP request returns before Cloudflare idle-times out.
        $fileIdList = $files->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $lockKey = 'share-link-zip:'.$shoot->id.':'.$normalizedMediaStage.':'.sha1(implode(',', $fileIdList));

        try {
            $shareLinkRecord = ShootShareLink::create([
                'shoot_id' => $shoot->id,
                'created_by' => $user->id,
                'share_url' => route('api.public.share-links.download', ['token' => 'pending']),
                'media_stage' => $normalizedMediaStage,
                'dropbox_path' => null,
                'download_count' => 0,
                'expires_at' => now()->addDays(7),
            ]);
            $shareLinkRecord->share_url = route('api.public.share-links.download', [
                'token' => $shareLinkRecord->public_token,
            ]);
            $shareLinkRecord->save();
            $shareLinkId = $shareLinkRecord->id;
            $expiresAt = $shareLinkRecord->expires_at?->toIso8601String();
        } catch (\Exception $dbError) {
            Log::warning('Could not save share link to database', ['error' => $dbError->getMessage()]);
            throw new \RuntimeException('Could not create share link record.');
        }

        $lockKey .= ':link:'.$shareLinkId;
        if (Cache::add($lockKey, 1, \App\Services\Media\ArchiveQueue::lockSeconds())) {
            try {
                GenerateShootShareLinkZipJob::dispatch(
                    (int) $shareLinkId,
                    (int) $shoot->id,
                    $fileIdList,
                    $normalizedMediaStage,
                    $lockKey
                );
            } catch (\Throwable $exception) {
                Cache::forget($lockKey);
                throw $exception;
            }
        }

        $this->activityLogger->log(
            $shoot,
            'share_link_generated',
            [
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'file_count' => $fileCount,
                'media_stage' => $normalizedMediaStage,
                'expires_in_hours' => null,
                'async' => true,
            ],
            $user
        );

        $ready = is_string($shareLinkRecord->dropbox_path) && $shareLinkRecord->dropbox_path !== '';
        $payload = [
            'share_link' => $this->buildPublicShareUrl($shareLinkRecord),
            'share_link_id' => $shareLinkId,
            'media_stage' => $normalizedMediaStage,
            'file_count' => $fileCount,
            'expires_in_hours' => null,
            'expires_at' => $expiresAt,
        ];

        if (! $ready) {
            $payload['type'] = 'preparing';
            $payload['message'] = 'Preparing your share link.';
            $payload['poll_after_ms'] = 3000;
        }

        return $payload;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $left
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $right
     */
    protected function fileIdSetsMatch($left, $right): bool
    {
        $a = $left->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $b = $right->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $a !== [] && $a === $b;
    }

    public function ensureActiveShootShareLink(
        Shoot $shoot,
        User $user,
        string $mediaStage = self::MEDIA_STAGE_RAW
    ): array {
        $normalizedMediaStage = $this->normalizeMediaStage($mediaStage);

        $existingLink = $shoot->activeShareLinks()
            ->forMediaStage($normalizedMediaStage)
            ->latest('id')
            ->first();

        if ($existingLink) {
            return [
                'share_link' => $this->buildPublicShareUrl($existingLink),
                'share_link_id' => $existingLink->id,
                'media_stage' => $normalizedMediaStage,
                'file_count' => 0,
                'expires_in_hours' => null,
                'expires_at' => $existingLink->expires_at?->toIso8601String(),
                'reused' => true,
            ];
        }

        $payload = $this->createShootShareLink($shoot, $user, [], $normalizedMediaStage);
        $payload['reused'] = false;

        return $payload;
    }

    /**
     * Video-only editors requesting a generic "raw" share package must not receive
     * photo raws (or the full raw archive). Coerce to raw_video / raw_photo from
     * their assigned lanes on this shoot.
     */
    protected function coerceMediaStageForEditor(Shoot $shoot, User $user, string $normalizedMediaStage): string
    {
        if ($normalizedMediaStage !== self::MEDIA_STAGE_RAW || $user->role !== 'editor') {
            return $normalizedMediaStage;
        }

        $lanes = app(ShootEditingAssignmentService::class)->getAssignedLanesForEditor($shoot, $user);
        $hasPhoto = in_array(ShootEditingAssignmentService::LANE_PHOTO, $lanes, true);
        $hasVideo = in_array(ShootEditingAssignmentService::LANE_VIDEO, $lanes, true);

        if ($hasVideo && ! $hasPhoto) {
            return self::MEDIA_STAGE_RAW_VIDEO;
        }
        if ($hasPhoto && ! $hasVideo) {
            return self::MEDIA_STAGE_RAW_PHOTO;
        }

        return $normalizedMediaStage;
    }

    protected function normalizeMediaStage(string $mediaStage): string
    {
        return match (strtolower(trim($mediaStage))) {
            self::MEDIA_STAGE_EDITED => self::MEDIA_STAGE_EDITED,
            self::MEDIA_STAGE_RAW_PHOTO => self::MEDIA_STAGE_RAW_PHOTO,
            self::MEDIA_STAGE_RAW_VIDEO => self::MEDIA_STAGE_RAW_VIDEO,
            default => self::MEDIA_STAGE_RAW,
        };
    }

    public function buildPublicShareUrl(ShootShareLink $shareLink): string
    {
        $frontendBaseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $canonical = "{$frontendBaseUrl}/share/{$shareLink->public_token}";

        return $this->shortLinks->maybeShorten(
            ShortLink::TYPE_SHARE_DOWNLOAD,
            ShortLink::TARGET_SHARE_LINK,
            (int) $shareLink->id,
            $canonical
        );
    }
}
