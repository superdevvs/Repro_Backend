<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Database\Eloquent\Collection;

/** A small published image, never an operational shoot or original-file URL. */
class ExclusiveListingPreview
{
    public function __construct(private PublicTourImage $images) {}

    /** @param Collection<int, Shoot> $shoots */
    public function forShoots(Collection $shoots): array
    {
        $eligible = $shoots->filter(fn (Shoot $shoot) => ! $shoot->units_exists && $this->images->canPublish($shoot));
        // Laravel's per-parent eager limit bounds this read independently of
        // album size. No album arrays or client/payment details enter discovery.
        $eligible->load(['files' => fn ($query) => $query
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(fn ($q) => $q->where('is_hidden', false)->orWhereNull('is_hidden'))
            ->where(fn ($q) => $q->where('scan_status', ShootFile::SCAN_STATUS_CLEAN)->orWhereNull('scan_status'))
            ->where(fn ($q) => $q->whereNull('media_type')->orWhereNotIn('media_type', ['floorplan', 'video', 'iguide']))
            ->where(fn ($q) => $q->whereNotNull('grid_path')->orWhereNotNull('thumbnail_path')->orWhereNotNull('web_path'))
            ->orderByDesc('is_cover')
            ->orderByRaw('CASE WHEN sort_order IS NULL OR sort_order <= 0 THEN 1 ELSE 0 END')
            ->orderBy('sort_order')->orderBy('id')
            ->limit(6)]);

        $previews = [];
        foreach ($eligible as $shoot) {
            foreach ($shoot->files as $file) {
                if (! $this->images->canPublishFile($file)) continue;
                foreach (['grid', 'thumbnail', 'web'] as $rendition) {
                    if ($url = $this->images->url($file, $rendition)) {
                        $previews[$shoot->id] = $url;
                        break 2;
                    }
                }
            }
        }

        return $previews;
    }
}
