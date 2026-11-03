<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

/** A small published image, never an operational shoot or original-file URL. */
class ExclusiveListingPreview
{
    public function __construct(private PublicTourImage $images) {}

    /** @param Collection<int, Shoot> $shoots */
    public function forShoots(Collection $shoots): array
    {
        $eligible = $shoots->filter(fn (Shoot $shoot) => ! $shoot->units_exists && $this->images->canPublish($shoot));
        $active = $eligible->filter(fn (Shoot $shoot) => app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot));
        $activeIds = $active->modelKeys();
        $retainedLines = $active->flatMap(fn (Shoot $shoot) => $this->images->retainedServiceIds($shoot))->all();
        $unscoped = $active->filter(fn (Shoot $shoot) => $this->images->canRetainUnscopedHistory($shoot))->modelKeys();
        // Laravel's per-parent eager limit bounds this read independently of
        // album size. No album arrays or client/payment details enter discovery.
        $eligible->load(['files' => fn ($query) => $query
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(fn ($q) => $q->where('is_hidden', false)->orWhereNull('is_hidden'))
            ->where(fn ($q) => $q->where('scan_status', ShootFile::SCAN_STATUS_CLEAN)->orWhereNull('scan_status'))
            ->where(fn ($q) => $q->whereNull('media_type')->orWhereNotIn('media_type', ['floorplan', 'video', 'iguide']))
            ->when($activeIds !== [], fn (Builder $query) => $query->where(function (Builder $scope) use ($activeIds, $retainedLines, $unscoped) {
                $scope->whereNotIn('shoot_id', $activeIds)->orWhere(function (Builder $history) use ($activeIds, $retainedLines, $unscoped) {
                    $history->whereIn('shoot_id', $activeIds)->where('workflow_stage', ShootFile::STAGE_VERIFIED)
                        ->where(function (Builder $line) use ($retainedLines, $unscoped) {
                            $line->whereIn('shoot_service_id', $retainedLines)->orWhere(fn (Builder $legacy) =>
                                $legacy->whereNull('shoot_service_id')->whereIn('shoot_id', $unscoped)
                                    ->whereRaw('shoot_files.created_at <= (SELECT active_work_started_at FROM shoots WHERE shoots.id = shoot_files.shoot_id)')
                                    ->where(fn (Builder $verified) => $verified->whereNull('verified_at')
                                        ->orWhereRaw('shoot_files.verified_at <= (SELECT active_work_started_at FROM shoots WHERE shoots.id = shoot_files.shoot_id)')));
                        });
                });
            }))
            ->where(fn ($q) => $q->whereNotNull('grid_path')->orWhereNotNull('thumbnail_path')->orWhereNotNull('web_path'))
            ->orderByDesc('is_cover')
            ->orderByRaw('CASE WHEN sort_order IS NULL OR sort_order <= 0 THEN 1 ELSE 0 END')
            ->orderBy('sort_order')->orderBy('id')
            ->limit(6)]);

        $previews = [];
        foreach ($eligible as $shoot) {
            foreach ($shoot->files as $file) {
                if (! $this->images->canPublishFile($file, $shoot)) continue;
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
