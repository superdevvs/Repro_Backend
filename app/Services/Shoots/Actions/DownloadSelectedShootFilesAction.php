<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;

class DownloadSelectedShootFilesAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootFileAccessService $fileAccess,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport
    ) {
    }

    public function execute(Request $request, Shoot $shoot, ?User $user)
    {
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($this->shootAuthorizationSupport->hasRole($user, ['editor'])) {
            return response()->json([
                'message' => 'Editors can only download raw files via the raw download endpoint.',
            ], 403);
        }

        $request->validate([
            'file_ids' => 'nullable|array',
            'file_ids.*' => 'integer',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'size' => 'nullable|in:original,small',
        ]);

        $fileIds = $request->input('file_ids', $request->input('ids', []));
        if (!is_array($fileIds) || count($fileIds) === 0) {
            return response()->json(['error' => 'No file IDs provided'], 422);
        }

        $files = $shoot->files()->whereIn('id', $fileIds)->get();
        if ($files->isEmpty()) {
            return response()->json(['error' => 'No files found for selected IDs'], 404);
        }

        foreach ($files as $file) {
            if (!$this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $user)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if ($this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user)) {
                return $this->shootClientReleaseAccessService->downloadLockedResponse();
            }
        }

        $size = $request->input('size', 'original');
        $needsWatermark = false;
        $dropboxEnabled = $this->dropboxService->isEnabled();

        $zipPath = storage_path('app/temp/shoot-' . $shoot->id . '-download-' . time() . '.zip');
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        $addedFiles = 0;
        $tempFiles = [];
        $pendingWatermarks = [];

        foreach ($files as $file) {
            $downloadPath = $this->resolveDownloadPath($file, $size, $needsWatermark, $pendingWatermarks);
            if (!$downloadPath) {
                continue;
            }

            $localPath = $this->fileAccess->resolveLocalPath($downloadPath);
            if (!$localPath && $dropboxEnabled) {
                $downloaded = $this->fileAccess->downloadDropboxPathToTemp($downloadPath, $file->stored_filename ?? $file->filename ?? 'file');
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
            if (!empty($pendingWatermarks)) {
                return response()->json([
                    'error' => 'Watermarked files are being generated. Please retry in a few minutes.',
                    'pending' => $pendingWatermarks,
                ], 409);
            }

            return response()->json(['error' => 'No downloadable files available'], 404);
        }

        return response()->download($zipPath, 'shoot-' . $shoot->id . '-selected.zip')->deleteFileAfterSend(true);
    }

    protected function resolveDownloadPath($file, string $size, bool $needsWatermark, array &$pendingWatermarks): ?string
    {
        if ($needsWatermark) {
            $downloadPath = $size === 'small'
                ? ($file->watermarked_web_path
                    ?? $file->watermarked_thumbnail_path
                    ?? $file->watermarked_placeholder_path)
                : ($file->watermarked_storage_path
                    ?? $file->watermarked_web_path
                    ?? $file->watermarked_thumbnail_path
                    ?? $file->watermarked_placeholder_path);

            if (!$downloadPath && $file->shouldBeWatermarked()) {
                GenerateWatermarkedImageJob::dispatch($file->fresh());
                $pendingWatermarks[] = $file->id;
            }

            return $downloadPath;
        }

        if ($size === 'small') {
            return $file->web_path
                ?? $file->thumbnail_path
                ?? $file->placeholder_path
                ?? $file->storage_path
                ?? $file->path
                ?? $file->dropbox_path;
        }

        return $file->storage_path
            ?? $file->path
            ?? $file->dropbox_path
            ?? $file->web_path
            ?? $file->thumbnail_path;
    }
}
