<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\DeliveryFilenameFormatter;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Shoots\ShootDownloadAssetClassifier;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DownloadShootMediaAction
{
    public function __construct(
        protected ShootFileAccessService $fileAccess,
        protected DeliveryFilenameFormatter $deliveryFilenameFormatter
    ) {
    }

    public function execute(ShootFile $file): ?string
    {
        if (! $this->allowsPreviewFallback($file)) {
            foreach ([$file->storage_path, $file->path] as $candidate) {
                if ($candidate && $this->fileAccess->storedFileExists($candidate)) {
                    $original = clone $file;
                    $original->path = $candidate;
                    $original->url = null;
                    return $this->fileAccess->resolveFileUrl($original);
                }
            }
            if (! $file->url && ! preg_match('/^https?:\/\//i', (string) $file->path)) return null;
        }
        return $this->fileAccess->resolveFileUrl($file);
    }

    public function downloadResponse(ShootFile $file, ?Request $request = null): Response
    {
        $filename = $this->deliveryDownloadName($file);

        $candidates = [
            $file->storage_path,
            $file->path,
        ];
        if ($this->allowsPreviewFallback($file)) {
            $candidates = array_merge($candidates, [$file->web_path, $file->thumbnail_path]);
        }
        foreach ($candidates as $candidate) {
            $localPath = $this->fileAccess->resolveLocalPath($candidate);
            if ($localPath && file_exists($localPath)) {
                return app(MediaStorage::class)->localResponse($localPath, $filename, [], (int) $file->shoot_id);
            }
        }

        $downloaded = $this->fileAccess->downloadFromDropbox($file);
        if ($downloaded && file_exists($downloaded)) {
            return app(MediaStorage::class)->temporaryDownload($downloaded, $filename, [], (int) $file->shoot_id);
        }

        $url = $this->execute($file);
        if ($url) {
            // Credential-bearing fetch clients deliberately reject HTTP redirects.
            // Give them the destination explicitly for a credential-free handoff;
            // native and legacy callers retain the original redirect response.
            if ($request?->prefers(['application/json', 'application/zip']) === 'application/zip') {
                return response()->json(['type' => 'redirect', 'url' => $url]);
            }

            return redirect()->away($url);
        }

        return response()->json(['message' => 'File not available'], 404);
    }

    public function downloadFloorplanJpegResponse(ShootFile $file, int $page): Response
    {
        $classifier = app(ShootDownloadAssetClassifier::class);
        if (! $classifier->isPdf($file) || ! $classifier->isFloorplan($file)) {
            return response()->json(['message' => 'JPG pages are only available for PDF floorplans.'], 422);
        }
        $previews = data_get($file->metadata, 'preview_images', []);
        $path = is_array($previews) ? (array_values($previews)[$page - 1] ?? null) : null;
        // Only server-generated pages belonging to this shoot are eligible.
        if (! is_string($path)
            || ! str_starts_with($path, 'shoots/'.$file->shoot_id.'/floorplans/previews/')
            || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))
            || ! preg_match('/\.jpe?g$/i', $path)) {
            return response()->json(['message' => 'This floorplan JPG page is not available.'], 404);
        }
        $localPath = $this->fileAccess->resolveLocalPath($path);
        $temporary = false;
        if (! $localPath) {
            $localPath = $this->fileAccess->downloadStoredFileToTemp($path);
            $temporary = true;
        }
        if (! $localPath || ! is_file($localPath)) {
            return response()->json(['message' => 'This floorplan JPG page is not available.'], 404);
        }
        $name = pathinfo($this->deliveryDownloadName($file), PATHINFO_FILENAME).'-page-'.$page.'.jpg';
        return $temporary
            ? app(MediaStorage::class)->temporaryDownload($localPath, $name, ['Content-Type' => 'image/jpeg'], (int) $file->shoot_id)
            : app(MediaStorage::class)->localResponse($localPath, $name, ['Content-Type' => 'image/jpeg'], (int) $file->shoot_id);
    }

    private function allowsPreviewFallback(ShootFile $file): bool
    {
        $classifier = app(ShootDownloadAssetClassifier::class);
        return $classifier->isImage($file) && ! $classifier->isVideo($file)
            && ! $classifier->isPdf($file)
            && ! app(ShootAuthorizationSupport::class)->isRawCameraFile($file);
    }

    /**
     * Name the single-file download after its place in the delivery order, so a
     * one-off download drops into the same sequence as a full-set ZIP instead of
     * sorting into an unrelated spot in the client's folder.
     *
     * Falls back to the bare master filename when the position cannot be
     * resolved — a download must never fail over cosmetic naming.
     */
    protected function deliveryDownloadName(ShootFile $file): string
    {
        if ($file->isIguideOfflinePackage()) {
            return basename((string) ($file->filename ?: 'iguide-offline-package.zip'));
        }

        $fallback = basename((string) ($file->path ?: $file->storage_path ?: $file->dropbox_path ?: 'download'));

        try {
            $position = $this->deliveryPosition($file);
            if ($position === null) {
                return $this->deliveryFilenameFormatter->baseNameFor($file, $fallback);
            }

            return $this->deliveryFilenameFormatter->formatForFile(
                $file,
                $position['position'],
                $position['total'],
                $fallback
            );
        } catch (\Throwable) {
            return $this->deliveryFilenameFormatter->baseNameFor($file, $fallback);
        }
    }

    /**
     * @return array{position:int,total:int}|null
     */
    protected function deliveryPosition(ShootFile $file): ?array
    {
        if (!$file->shoot_id) {
            return null;
        }

        // Scoped to the file's own workflow stage group so a delivered photo is
        // numbered against the delivered set the client actually receives, not
        // against raws that were never part of the delivery.
        $isDelivered = in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true);
        $stages = $isDelivered
            ? [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED]
            : [$file->workflow_stage];

        $siblings = ShootFile::query()
            ->where('shoot_id', $file->shoot_id)
            ->where(function ($query) use ($stages, $isDelivered) {
                $query->whereIn('workflow_stage', array_filter($stages, fn ($stage) => $stage !== null));

                // A raw file predating the stage column carries a null stage, and
                // `whereIn(..., [null])` matches nothing — which would silently
                // drop the numbering for the entire legacy raw set.
                if (!$isDelivered && in_array(null, $stages, true)) {
                    $query->orWhereNull('workflow_stage');
                }
            })
            ->inDeliveryOrder()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $index = array_search((int) $file->id, $siblings, true);
        if ($index === false) {
            return null;
        }

        return ['position' => $index + 1, 'total' => count($siblings)];
    }
}
