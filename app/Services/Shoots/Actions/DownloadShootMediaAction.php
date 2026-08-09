<?php

namespace App\Services\Shoots\Actions;

use App\Models\ShootFile;
use App\Services\Shoots\DeliveryFilenameFormatter;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadShootMediaAction
{
    public function __construct(
        protected ShootFileAccessService $fileAccess,
        protected DeliveryFilenameFormatter $deliveryFilenameFormatter
    ) {
    }

    public function execute(ShootFile $file): ?string
    {
        return $this->fileAccess->resolveFileUrl($file);
    }

    public function downloadResponse(ShootFile $file): BinaryFileResponse|JsonResponse|RedirectResponse
    {
        $filename = $this->deliveryDownloadName($file);

        foreach ([
            $file->storage_path,
            $file->path,
            $file->web_path,
            $file->thumbnail_path,
        ] as $candidate) {
            $localPath = $this->fileAccess->resolveLocalPath($candidate);
            if ($localPath && file_exists($localPath)) {
                return response()->download($localPath, $filename);
            }
        }

        $downloaded = $this->fileAccess->downloadFromDropbox($file);
        if ($downloaded && file_exists($downloaded)) {
            return response()->download($downloaded, $filename)->deleteFileAfterSend(true);
        }

        $url = $this->fileAccess->resolveFileUrl($file);
        if ($url) {
            return redirect()->away($url);
        }

        return response()->json(['message' => 'File not available'], 404);
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
