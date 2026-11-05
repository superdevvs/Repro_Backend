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
        $items = $shoot->serviceItems()->get();
        if ($items->isEmpty()) return ['service_ids' => [], 'file_ids' => $files->filter(fn ($file) => app(DeliveryPlanService::class)->usableOutput($file))->pluck('id')->all()];
        $plan = app(DeliveryPlanService::class)->plan($shoot, null);
        $provided = $files->pluck('id')->all();
        $selected = collect($plan['components'])->filter(fn ($component) => (! $scope || (int) $component['shoot_service_id'] === $scope)
            && $component['status'] === 'ready' && (array_intersect($component['file_ids'], $provided) || in_array('external', $component['methods'], true)));
        $selectedIds = $selected->pluck('id')->all();
        $serviceIds = $items->filter(function ($item) use ($plan, $selectedIds, $scope) {
            if ($scope && (int) $item->id !== $scope) return false;
            $components = collect($plan['components'])->where('shoot_service_id', $item->id);
            return $components->isNotEmpty() && $components->every(fn ($component) => $component['resolved'] || in_array($component['id'], $selectedIds, true));
        })->modelKeys();
        return ['service_ids' => $serviceIds, 'file_ids' => $selected->pluck('file_ids')->flatten()->filter(fn ($id) => in_array($id, $provided, true))->unique()->values()->all()];
    }
    /** A current replacement can retain its gallery ID; old verified files cannot sign off new work. */
    public function currentCycleFiles(Shoot $shoot, Collection $files): Collection
    {
        $reopened = app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot);
        if ((! $reopened && ! app(ShootActiveWorkLifecycle::class)->isInitialPartial($shoot)) || $files->isEmpty()) return $files;
        $baseCutoff = $reopened ? $shoot->active_work_started_at : $shoot->created_at;
        $published = ShootFileVersion::where('shoot_id', $shoot->id)->where('status', 'published')
            ->where('updated_at', '>=', $baseCutoff)
            ->whereIn('published_file_id', $files->pluck('id')->all())->get()->keyBy('published_file_id');
        $cutoffs = $this->submittedRawCutoffs($shoot);
        $items = $shoot->serviceItems()->get();
        $itemsById = $items->keyBy('id');
        $singleItem = $items->count() === 1 ? $items->first()->id : null;
        // A first partial delivery starts Active while another original service
        // is still being edited. Its never-verified output is still current work.
        // A previously delivered line or a genuine additional-work cycle must
        // continue to require fresh evidence after the cycle's cutoff.
        return $files->filter(function (ShootFile $file) use ($shoot, $published, $cutoffs, $singleItem, $baseCutoff, $itemsById) {
            $cutoff = $baseCutoff;
            $lineId = $file->shoot_service_id ?: $singleItem;
            $lineStart = data_get($itemsById->get($lineId)?->delivery_requirements, '0.execution_started_at');
            if ($lineStart && \Carbon\Carbon::parse($lineStart)->gt($cutoff)) $cutoff = \Carbon\Carbon::parse($lineStart);
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
        $cycle = app(DeliveryPlanService::class)->executionKey($shoot);
        $cutoffs = [];
        $items = $shoot->serviceItems()->get()->keyBy('id');
        $raw = $shoot->files()->where('created_at', '>=', $cycle === 'initial' ? $shoot->created_at : $shoot->active_work_started_at)
            ->where(fn ($query) => $query->where('workflow_stage', ShootFile::STAGE_TODO)->orWhere('media_type', 'raw'))
            ->get(['shoot_service_id', 'metadata', 'content_version', 'created_at']);
        foreach ($raw as $file) {
            $receipt = data_get($file->metadata, 'active_work_raw_submission', []);
            $item = $items->get($file->shoot_service_id);
            $execution = $item ? app(DeliveryPlanService::class)->itemExecutionKey($shoot, $item) : $cycle;
            $matches = isset($receipt['execution_key']) ? $receipt['execution_key'] === $execution : $execution === $cycle && ($receipt['cycle'] ?? null) === $cycle;
            if (! $file->shoot_service_id || ! $matches
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
