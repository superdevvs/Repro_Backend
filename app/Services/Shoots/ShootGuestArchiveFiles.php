<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootFile};
use Illuminate\Support\Collection;

/** Reuse delivery rendition, ordering, filename and remote staging policies. */
class ShootGuestArchiveFiles extends ShootMediaArchiveService
{
    private ?Collection $selection = null;

    public function selectionPlan(Shoot $shoot, Collection $files, string $size): array
    {
        $this->selection = $files;
        try {
            return $this->assembleArchivePlan($shoot, 'edited', $size, null, null, false);
        } finally {
            $this->selection = null;
        }
    }

    public function getFilesForType(Shoot $shoot, string $type, ?int $shootServiceId = null, ?int $shootUnitId = null): Collection
    {
        return $this->selection ?? throw new \LogicException('An authorized guest selection is required.');
    }

    public function stage(array &$entry): ?string
    {
        return $this->resolveArchiveEntryLocalPath($entry);
    }

    public function mlsPath(ShootFile $file): ?string
    {
        $classifier = app(ShootDownloadAssetClassifier::class);
        return $classifier->isImage($file) && ! $classifier->isVideo($file) && ! $classifier->isPdf($file)
            && is_string($file->web_path) && trim($file->web_path) !== '' ? $file->web_path : null;
    }
}
