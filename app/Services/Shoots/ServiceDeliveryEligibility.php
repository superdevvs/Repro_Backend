<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\ShootService;
use App\Services\Schedule\ScheduleInstantResolver;
use Illuminate\Support\Collection;

/** Delivery evidence belongs to a booked service, never to every line on an order. */
final class ServiceDeliveryEligibility
{
    /** @return array{service_ids: array<int>, file_ids: array<int>} */
    public function resolve(Shoot $shoot, Collection $files, bool $allowNoMedia = false, ?int $scope = null): array
    {
        if ($shoot->active_work_started_at && ! $shoot->active_work_completed_at
            && in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)),
                ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true)) {
            return ['service_ids' => [], 'file_ids' => []];
        }
        $items = $shoot->serviceItems()->with(['service.category', 'unit'])
            ->where('is_deliverable', true)
            ->where(fn ($query) => $query->whereNull('workflow_status')->orWhere('workflow_status', '!=', ShootService::WORKFLOW_CANCELLED))
            ->where(fn ($query) => $query->whereNull('delivery_status')->orWhere('delivery_status', '!=', ShootService::DELIVERY_CANCELLED))
            ->whereNull('cancelled_at')->get();

        // Unscoped legacy orders remain deliverable; once booked execution
        // identities exist, output must establish which service was completed.
        if ($items->isEmpty()) {
            return ['service_ids' => [], 'file_ids' => $shoot->serviceItems()->exists() ? [] : $files->pluck('id')->all()];
        }

        $files = $this->currentCycleFiles($shoot, $files);

        $editing = app(ShootEditingAssignmentService::class);
        $eligible = $items->filter(function (ShootService $item) use ($shoot, $items, $files, $editing, $allowNoMedia, $scope) {
            if ($scope && (int) $item->id !== $scope) return false;
            if ($item->cancelled_at || ($item->scheduled_at
                && app(ScheduleInstantResolver::class)->forServiceItem($shoot, $item)?->isFuture())) return false;
            if (data_get($item->payout_snapshot, 'billing_correction_only')) return false;
            if ($allowNoMedia) return true;

            $output = $files->filter(fn (ShootFile $file) => (int) $file->shoot_service_id === (int) $item->id
                || (! $file->shoot_service_id && $items->count() === 1));

            $lanes = $editing->serviceEditingLanes($item);
            if (count($lanes) > 1) {
                // A photo/video bundle cannot be finished by just its photos.
                return collect($lanes)->every(function (string $lane) use ($output, $editing, $item, $shoot) {
                    if ($output->contains(fn (ShootFile $file) => $editing->getFileLane($file) === $lane)) return true;
                    $completedAt = $lane === ShootEditingAssignmentService::LANE_VIDEO
                        ? $item->video_editing_completed_at : $item->editing_completed_at;
                    return $completedAt && (! $shoot->active_work_started_at || $completedAt->gte($shoot->active_work_started_at));
                });
            }
            if ($output->isNotEmpty()) return true;

            if ($this->hasReviewedVideoLink($shoot, $item, $lanes)) return true;

            // Provider-only products can be ready without an uploaded image.
            // A stale ready timestamp from a previous delivery is insufficient.
            if ($item->service?->supportsPhotoIntake() || $item->service?->supportsVideoIntake()) return false;
            if ($shoot->active_work_started_at && (! $item->ready_at || $item->ready_at->lt($shoot->active_work_started_at))) return false;
            return app(ShootServiceProgress::class)->forItem($shoot, $item) === 'ready';
        });
        $ids = $eligible->modelKeys();
        $fileIds = $files->filter(fn (ShootFile $file) => (in_array((int) $file->shoot_service_id, $ids, true)
                || (! $file->shoot_service_id && $items->count() === 1 && count($ids) === 1)))->pluck('id')->all();

        return ['service_ids' => $ids, 'file_ids' => $fileIds];
    }

    /** A current replacement can retain its gallery ID; old verified files cannot sign off new work. */
    public function currentCycleFiles(Shoot $shoot, Collection $files): Collection
    {
        if (! $shoot->active_work_started_at || $files->isEmpty()) return $files;
        $published = ShootFileVersion::where('shoot_id', $shoot->id)->where('status', 'published')
            ->where('updated_at', '>=', $shoot->active_work_started_at)
            ->whereIn('published_file_id', $files->pluck('id')->all())->get()->keyBy('published_file_id');
        $cutoffs = $this->submittedRawCutoffs($shoot);
        $items = $shoot->serviceItems()->get();
        $singleItem = $items->count() === 1 ? $items->first()->id : null;
        // A first partial delivery starts Active while another original service
        // is still being edited. Its never-verified output is still current work.
        // A previously delivered line or a genuine additional-work cycle must
        // continue to require fresh evidence after the cycle's cutoff.
        $initialItems = app(ShootActiveWorkLifecycle::class)->initialDeliveryItemIds($shoot);
        return $files->filter(function (ShootFile $file) use ($shoot, $published, $cutoffs, $singleItem, $initialItems) {
            $cutoff = $shoot->active_work_started_at;
            $lineId = $file->shoot_service_id ?: $singleItem;
            if ($file->workflow_stage === ShootFile::STAGE_COMPLETED && ! $file->verified_at
                && in_array((int) $lineId, $initialItems, true)) {
                $cutoff = $shoot->created_at;
            }
            // A later handoff invalidates older edits for that line, without
            // hiding an unsubmitted RAW batch belonging to another artist.
            if ($file->workflow_stage !== ShootFile::STAGE_TODO && $file->media_type !== 'raw') {
                $rawAt = $cutoffs[$lineId] ?? null;
                if ($rawAt && $rawAt->gt($cutoff)) $cutoff = $rawAt;
            }
            return $file->created_at?->gte($cutoff)
                || ($published->has($file->id)
                    && (int) $published->get($file->id)->version === (int) $file->content_version
                    && $published->get($file->id)->updated_at->gte($cutoff));
        });
    }

    private function submittedRawCutoffs(Shoot $shoot): array
    {
        $cycle = $shoot->active_work_started_at->toIso8601String();
        $cutoffs = [];
        $raw = $shoot->files()->where('created_at', '>=', $shoot->active_work_started_at)
            ->where(fn ($query) => $query->where('workflow_stage', ShootFile::STAGE_TODO)->orWhere('media_type', 'raw'))
            ->get(['shoot_service_id', 'metadata', 'content_version', 'created_at']);
        foreach ($raw as $file) {
            $receipt = data_get($file->metadata, 'active_work_raw_submission', []);
            if (! $file->shoot_service_id || ($receipt['cycle'] ?? null) !== $cycle
                || (int) ($receipt['content_version'] ?? 0) !== (int) ($file->content_version ?: 1)) continue;
            $id = (int) $file->shoot_service_id;
            if (! isset($cutoffs[$id]) || $file->created_at->gt($cutoffs[$id])) $cutoffs[$id] = $file->created_at;
        }
        return $cutoffs;
    }

    private function hasReviewedVideoLink(Shoot $shoot, ShootService $item, array $lanes): bool
    {
        if (! $shoot->active_work_started_at || $lanes !== [ShootEditingAssignmentService::LANE_VIDEO]
            || ! $item->editing_completed_at || $item->editing_completed_at->lt($shoot->active_work_started_at)) return false;
        return app(ActiveVideoLinkOutput::class)->hasReviewedOutput($shoot, $item);
    }
}
