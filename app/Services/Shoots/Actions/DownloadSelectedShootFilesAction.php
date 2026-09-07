<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\DeliveryFilenameFormatter;
use App\Services\Shoots\DeliveryMediaOrderService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;

class DownloadSelectedShootFilesAction
{
    public function __construct(
        protected ShootFileAccessService $fileAccess,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected DeliveryMediaOrderService $deliveryMediaOrderService,
        protected DeliveryFilenameFormatter $deliveryFilenameFormatter
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

        // Delivery order, so a partial selection keeps the same relative sequence
        // the client sees in the gallery instead of arriving in primary-key order.
        $files = $shoot->files()->whereIn('id', $fileIds)->inDeliveryOrder()->get();
        $files = $this->deliveryMediaOrderService->applyTo($shoot, $files);
        if ($files->isEmpty()) {
            return response()->json(['error' => 'No files found for selected IDs'], 404);
        }

        foreach ($files as $file) {
            if (!$this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $user)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            // Infected files are blocked from download (Req 15.7).
            if ($file->isBlockedFromDelivery()) {
                return response()->json([
                    'error_type' => 'file_infected',
                    'message' => 'A selected file was flagged as infected by a virus scan and cannot be downloaded.',
                ], 403);
            }

            if ($this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user)) {
                return $this->shootClientReleaseAccessService->downloadLockedResponse();
            }
        }

        $size = $request->input('size', 'original');
        $needsWatermark = false;

        $zipPath = storage_path('app/temp/shoot-' . $shoot->id . '-download-' . time() . '.zip');
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Failed to create ZIP file'], 500);
        }

        $addedFiles = 0;
        $pendingWatermarks = [];
        // Positions are 1..n across the selection, so a partial download reads
        // 001, 002, 003 in the selection's delivery order rather than exposing
        // gaps from the files the client did not pick.
        $total = $files->count();
        $position = 1;
        $usedNames = [];

        foreach ($files as $file) {
            $downloadPath = $this->resolveDownloadPath($file, $size, $needsWatermark, $pendingWatermarks);
            if (!$downloadPath) {
                continue;
            }

            $localPath = $this->fileAccess->resolveLocalPath($downloadPath);


            if ($localPath && file_exists($localPath)) {
                $zip->addFile($localPath, $this->deliveryFilenameFormatter->deduplicate(
                    $this->deliveryFilenameFormatter->formatForFile($file, $position, $total, basename($localPath)),
                    $usedNames
                ));
                $addedFiles++;
                $position++;
            }
        }

        $zip->close();



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
                ?? $file->path;
        }

        return $file->storage_path
            ?? $file->path
            ?? $file->web_path
            ?? $file->thumbnail_path;
    }
}
