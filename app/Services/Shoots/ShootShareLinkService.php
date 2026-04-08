<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
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
        protected DropboxWorkflowService $dropboxService,
        protected ShootActivityLogger $activityLogger,
        protected ShootFileAccessService $fileAccessService
    ) {
    }

    public function generateFilesZipWithDropboxFallback(Shoot $shoot, $files): ?string
    {
        $zipPath = storage_path("app/temp/shoot-{$shoot->id}-raw-" . time() . '.zip');
        $tempFiles = [];
        $dropboxEnabled = $this->dropboxService->isEnabled();

        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception('Failed to create ZIP file');
        }

        $addedFiles = 0;
        foreach ($files as $file) {
            $localPath = $this->fileAccessService->findLocalFilePath($file);
            if ($dropboxEnabled && !$localPath && !empty($file->dropbox_path)) {
                $localPath = $this->fileAccessService->downloadFromDropbox($file);
                if ($localPath) {
                    $tempFiles[] = $localPath;
                }
            }

            if ($localPath && file_exists($localPath)) {
                $filename = $file->original_name ?? $file->filename ?? basename($localPath);
                $zip->addFile($localPath, $filename);
                $addedFiles++;
            }
        }

        $zip->close();

        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }

        if ($addedFiles === 0) {
            @unlink($zipPath);

            return null;
        }

        return $zipPath;
    }

    public function createShootShareLink(
        Shoot $shoot,
        User $user,
        array $fileIds = [],
        string $mediaStage = self::MEDIA_STAGE_RAW
    ): array
    {
        $normalizedMediaStage = $this->normalizeMediaStage($mediaStage);
        $isEditedStage = $normalizedMediaStage === self::MEDIA_STAGE_EDITED;
        $isLaneSpecificStage = in_array($normalizedMediaStage, [
            self::MEDIA_STAGE_RAW_PHOTO,
            self::MEDIA_STAGE_RAW_VIDEO,
        ], true);
        $workflowStages = $isEditedStage
            ? [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]
            : [ShootFile::STAGE_TODO];
        $stageLabel = $isEditedStage ? 'edited' : 'raw';

        $filesQuery = $shoot->files()->whereIn('workflow_stage', $workflowStages);
        if ($normalizedMediaStage === self::MEDIA_STAGE_RAW_VIDEO) {
            $filesQuery->where('media_type', 'video');
        } elseif ($normalizedMediaStage === self::MEDIA_STAGE_RAW_PHOTO) {
            $filesQuery->where(function ($query) {
                $query->whereNull('media_type')
                    ->orWhere('media_type', '!=', 'video');
            });
        }
        if (!empty($fileIds)) {
            $filesQuery->whereIn('id', $fileIds);
        }
        $files = $filesQuery->get();
        $fileCount = $files->count();

        if (!empty($fileIds) && $fileCount === 0) {
            throw new \InvalidArgumentException("No {$stageLabel} files found for selected IDs");
        }

        $dropboxEnabled = $this->dropboxService->isEnabled();
        $folderPath = $dropboxEnabled ? $shoot->getDropboxFolderForType($normalizedMediaStage) : null;
        if ($dropboxEnabled && !$folderPath) {
            $this->dropboxService->createShootFolders($shoot);
            $shoot->refresh();
            $folderPath = $shoot->getDropboxFolderForType($normalizedMediaStage);
        }

        $shareLink = null;
        $shareLinkSourcePath = null;

        try {
            if ($dropboxEnabled && empty($fileIds) && $folderPath && !$isLaneSpecificStage) {
                $shareLink = $this->dropboxService->createSharedLink($folderPath);
                $shareLinkSourcePath = $folderPath;
            }
        } catch (\Exception $dropboxError) {
            Log::warning('Failed to create Dropbox share link, falling back to local ZIP', [
                'error' => $dropboxError->getMessage(),
                'shoot_id' => $shoot->id,
            ]);
        }

        if (!$shareLink) {
            if ($files->isEmpty()) {
                throw new \InvalidArgumentException("No {$stageLabel} files found to share");
            }

            $zipPath = $this->generateFilesZipWithDropboxFallback($shoot, $files);
            if (!$zipPath || !file_exists($zipPath)) {
                throw new \RuntimeException('Failed to generate shareable ZIP file');
            }

            $publicDir = "share-links/{$shoot->id}";
            Storage::disk('public')->makeDirectory($publicDir);
            $zipFilename = 'share-link-' . Str::uuid()->toString() . '.zip';
            $publicPath = $publicDir . '/' . $zipFilename;

            $stream = fopen($zipPath, 'r');
            if ($stream === false) {
                throw new \RuntimeException('Failed to read shareable ZIP file');
            }

            $stored = Storage::disk('public')->put($publicPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($zipPath);

            if (!$stored) {
                throw new \RuntimeException('Failed to store shareable ZIP file');
            }

            $shareLink = Storage::disk('public')->url($publicPath);
            $shareLinkSourcePath = $publicPath;
        }

        if (!$shareLink) {
            throw new \RuntimeException('Could not create share link. Dropbox may be unavailable or the ZIP could not be generated.');
        }

        try {
            $shareLinkRecord = ShootShareLink::create([
                'shoot_id' => $shoot->id,
                'created_by' => $user->id,
                'share_url' => $shareLink,
                'media_stage' => $normalizedMediaStage,
                'dropbox_path' => $shareLinkSourcePath,
                'download_count' => 0,
                'expires_at' => null,
            ]);
            $shareLinkId = $shareLinkRecord->id;
            $expiresAt = $shareLinkRecord->expires_at?->toIso8601String();
        } catch (\Exception $dbError) {
            Log::warning('Could not save share link to database', ['error' => $dbError->getMessage()]);
            $shareLinkId = null;
            $expiresAt = null;
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
            ],
            $user
        );

        $publicShareLink = $shareLinkId
            ? $this->buildPublicShareUrl($shoot->id, $shareLinkId)
            : $shareLink;

        return [
            'share_link' => $publicShareLink,
            'share_link_id' => $shareLinkId,
            'media_stage' => $normalizedMediaStage,
            'file_count' => $fileCount,
            'expires_in_hours' => null,
            'expires_at' => $expiresAt,
        ];
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
                'share_link' => $this->buildPublicShareUrl($shoot->id, $existingLink->id),
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

    protected function normalizeMediaStage(string $mediaStage): string
    {
        return match (strtolower(trim($mediaStage))) {
            self::MEDIA_STAGE_EDITED => self::MEDIA_STAGE_EDITED,
            self::MEDIA_STAGE_RAW_PHOTO => self::MEDIA_STAGE_RAW_PHOTO,
            self::MEDIA_STAGE_RAW_VIDEO => self::MEDIA_STAGE_RAW_VIDEO,
            default => self::MEDIA_STAGE_RAW,
        };
    }

    public function buildPublicShareUrl(int|string $shootId, int|string $shareLinkId): string
    {
        $frontendBaseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$frontendBaseUrl}/{$shootId}/{$shareLinkId}";
    }
}
