<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DownloadShootMediaZipAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaArchiveService $shootMediaArchiveService,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected ShootFileAccessService $shootFileAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {
    }

    public function execute(Request $request, Shoot $shoot)
    {
        $user = $request->user();

        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($this->shootAuthorizationSupport->hasRole($user, ['editor'])) {
            return response()->json([
                'message' => 'Editors can only download raw files via the raw download endpoint.',
            ], 403);
        }

        if ($this->shootClientReleaseAccessService->isClientReleaseLocked($shoot, $user)) {
            return $this->shootClientReleaseAccessService->downloadLockedResponse();
        }

        return $this->executeArchiveDownload($request, $shoot);
    }

    public function executePublic(Request $request, Shoot $shoot)
    {
        return $this->executeArchiveDownload($request, $shoot);
    }

    protected function executeArchiveDownload(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:raw,edited',
            'size' => 'nullable|in:original,small,medium,large',
        ]);

        $type = $validated['type'] ?? 'raw';
        $requestedSize = $validated['size'] ?? 'original';
        $normalizedRequestedSize = strtolower((string) $requestedSize);
        $resolvedSize = $this->shootMediaArchiveService->normalizeSize($requestedSize);

        if (in_array($normalizedRequestedSize, ['small', 'original'], true)) {
            try {
                $archiveResponse = $this->shootMediaArchiveService->resolveArchiveResponseData(
                    $shoot,
                    $type,
                    $resolvedSize,
                    $request->fullUrl()
                );

                return response()->json($archiveResponse['payload'], $archiveResponse['status']);
            } catch (\RuntimeException $e) {
                return response()->json(['error' => $e->getMessage()], 404);
            } catch (\Exception $e) {
                Log::error('Failed to resolve shoot archive download', [
                    'error' => $e->getMessage(),
                    'shoot_id' => $shoot->id,
                    'type' => $type,
                    'size' => $requestedSize,
                ]);

                return response()->json(['error' => 'Failed to prepare ZIP file'], 500);
            }
        }

        try {
            $zipPath = $this->generateZipFromFiles($shoot, $type, $resolvedSize);

            return response()
                ->download($zipPath, $this->buildZipFilename($shoot, $type, $resolvedSize))
                ->deleteFileAfterSend(true);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::error('Failed to generate ZIP', [
                'error' => $e->getMessage(),
                'shoot_id' => $shoot->id,
                'type' => $type,
                'size' => $requestedSize,
            ]);

            return response()->json(['error' => 'Failed to generate ZIP file'], 500);
        }
    }

    protected function generateZipFromFiles(Shoot $shoot, string $type, string $size): string
    {
        $files = $this->getFilesForType($shoot, $type);
        if ($files->isEmpty()) {
            throw new \RuntimeException('No downloadable files available');
        }

        $zipPath = storage_path('app/temp/shoot-' . $shoot->id . '-' . $type . '-' . $size . '-' . time() . '.zip');
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP file');
        }

        $addedFiles = 0;
        $tempFiles = [];

        foreach ($files as $file) {
            $downloadPath = $this->resolveDownloadPath($file, $size);
            if (!$downloadPath) {
                continue;
            }

            $localPath = $this->shootFileAccessService->resolveLocalPath($downloadPath);
            if (!$localPath && $this->dropboxService->isEnabled() && $downloadPath === $file->dropbox_path) {
                $downloaded = $this->shootFileAccessService->downloadDropboxPathToTemp(
                    $downloadPath,
                    $file->stored_filename ?? $file->filename ?? 'file'
                );

                if ($downloaded) {
                    $localPath = $downloaded;
                    $tempFiles[] = $downloaded;
                }
            }

            if ($localPath && file_exists($localPath)) {
                $zip->addFile($localPath, $file->original_name ?? $file->filename ?? basename($localPath));
                $addedFiles++;
            }
        }

        $zip->close();

        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }

        if ($addedFiles === 0) {
            @unlink($zipPath);
            throw new \RuntimeException('No downloadable files available');
        }

        return $zipPath;
    }

    protected function getFilesForType(Shoot $shoot, string $type): Collection
    {
        return $this->shootMediaArchiveService->getFilesForType($shoot, $type);
    }

    protected function resolveDownloadPath(ShootFile $file, string $size): ?string
    {
        if ($size === 'small') {
            return $file->web_path
                ?? $file->thumbnail_path
                ?? $file->placeholder_path
                ?? $file->storage_path
                ?? $file->path
                ?? ($this->dropboxService->isEnabled() ? $file->dropbox_path : null);
        }

        if ($size === 'medium' || $size === 'large') {
            return $file->web_path
                ?? $file->thumbnail_path
                ?? $file->storage_path
                ?? $file->path
                ?? ($this->dropboxService->isEnabled() ? $file->dropbox_path : null);
        }

        return $file->storage_path
            ?? $file->path
            ?? ($this->dropboxService->isEnabled() ? $file->dropbox_path : null)
            ?? $file->web_path
            ?? $file->thumbnail_path;
    }

    protected function buildZipFilename(Shoot $shoot, string $type, string $size): string
    {
        $suffix = $size !== 'original' ? '-' . $size : '';

        return "shoot-{$shoot->id}-{$type}{$suffix}.zip";
    }
}
