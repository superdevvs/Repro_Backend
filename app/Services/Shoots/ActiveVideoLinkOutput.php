<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootService};
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A saved video URL must belong to this booked line and this additional-work cycle. */
class ActiveVideoLinkOutput
{
    private const KEYS = ['video_link', 'video_branded', 'video_mls', 'video_generic'];

    public function startCycle(Shoot $shoot): void
    {
        $this->ensureBaseline($shoot);
    }

    /** Called before changing links. Repeated reconciliation must never replace the original baseline. */
    public function ensureBaseline(Shoot $shoot): void
    {
        $cycle = $this->cycle($shoot);
        if (! $cycle || $shoot->active_work_completed_at) return;
        $links = $this->persistedLinks($shoot);
        foreach ($this->pendingVideoItems($shoot) as $item) {
            $state = $this->state($item);
            if (($state['cycle_started_at'] ?? null) === $cycle
                && (int) ($state['shoot_service_id'] ?? 0) === (int) $item->id
                && (int) ($state['shoot_unit_id'] ?? 0) === (int) $item->shoot_unit_id) continue;
            $fingerprint = $this->fingerprint($links[$this->scope($item)] ?? []);
            $this->write($item, [
                'version' => 1,
                'cycle_started_at' => $cycle,
                'shoot_service_id' => (int) $item->id,
                'shoot_unit_id' => $item->shoot_unit_id ? (int) $item->shoot_unit_id : null,
                'baseline_fingerprint' => $fingerprint,
                'observed_fingerprint' => $fingerprint,
                'reviewed_output' => null,
            ]);
        }
    }

    /**
     * Called after the owning link save, in its transaction. An ambiguous shared
     * link needs an explicit booked-service scope; a later unrelated save cannot
     * claim an earlier ambiguous change for a different service.
     */
    public function recordChangedLinks(Shoot $shoot, ?int $shootServiceId = null): void
    {
        $cycle = $this->cycle($shoot);
        if (! $cycle || $shoot->active_work_completed_at) return;
        $this->ensureBaseline($shoot);
        $links = $this->persistedLinks($shoot);
        foreach ($this->pendingVideoItems($shoot)->groupBy(fn ($item) => $this->scope($item)) as $scope => $items) {
            if ($shootServiceId && ! $items->contains('id', $shootServiceId)) continue;
            $current = $this->validLinks($links[$scope] ?? []);
            $fingerprint = $this->fingerprint($current);
            foreach ($items as $item) {
                $state = $this->state($item);
                if (($state['cycle_started_at'] ?? null) !== $cycle
                    || ($state['observed_fingerprint'] ?? null) === $fingerprint) continue;
                $state['observed_fingerprint'] = $fingerprint;
                $state['reviewed_output'] = null;
                $ownsOutput = $shootServiceId ? (int) $item->id === $shootServiceId : $items->count() === 1;
                if ($ownsOutput && $current !== [] && $fingerprint !== ($state['baseline_fingerprint'] ?? null)) {
                    $state['reviewed_output'] = [
                        'shoot_service_id' => (int) $item->id,
                        'shoot_unit_id' => $item->shoot_unit_id ? (int) $item->shoot_unit_id : null,
                        'fingerprint' => $fingerprint,
                        'reviewed_at' => now()->toIso8601String(),
                    ];
                }
                $this->write($item, $state);
            }
        }
    }

    /** Read-only: submission/finalization cannot turn an old link into fresh output. */
    public function hasReviewedOutput(Shoot $shoot, ShootService $item): bool
    {
        if ((int) $item->shoot_id !== (int) $shoot->id || ! $this->cycle($shoot)) return false;
        $state = $this->state($item);
        $receipt = $state['reviewed_output'] ?? null;
        if (($state['version'] ?? null) !== 1 || ($state['cycle_started_at'] ?? null) !== $this->cycle($shoot)
            || (int) ($state['shoot_service_id'] ?? 0) !== (int) $item->id
            || (int) ($state['shoot_unit_id'] ?? 0) !== (int) $item->shoot_unit_id
            || ! is_array($receipt) || (int) ($receipt['shoot_service_id'] ?? 0) !== (int) $item->id
            || (int) ($receipt['shoot_unit_id'] ?? 0) !== (int) $item->shoot_unit_id) return false;
        try {
            $reviewedAt = Carbon::parse($receipt['reviewed_at'] ?? '');
            if (empty($receipt['reviewed_at']) || $reviewedAt->lt($shoot->active_work_started_at)
                || $reviewedAt->gt(now()->addMinute())) return false;
        } catch (\Throwable) {
            return false;
        }
        $links = $item->shoot_unit_id
            ? ($item->unit?->tour_links ?? [])
            : ($shoot->tour_links ?? []);
        $current = $this->validLinks(is_array($links) ? $links : []);
        $fingerprint = $this->fingerprint($current);
        return $current !== [] && ($receipt['fingerprint'] ?? null) === $fingerprint
            && ($state['observed_fingerprint'] ?? null) === $fingerprint
            && ($state['baseline_fingerprint'] ?? null) !== $fingerprint;
    }

    private function pendingVideoItems(Shoot $shoot): Collection
    {
        $editing = app(ShootEditingAssignmentService::class);
        return $shoot->serviceItems()->with(['service.category', 'unit'])->get()
            ->filter(fn (ShootService $item) => $item->is_deliverable && ! $item->cancelled_at
                && $item->workflow_status !== ShootService::WORKFLOW_CANCELLED
                && $item->delivery_status !== ShootService::DELIVERY_CANCELLED
                && ($item->delivery_status !== ShootService::DELIVERY_DELIVERED || $item->workflow_status !== ShootService::WORKFLOW_DELIVERED)
                && ! data_get($item->payout_snapshot, 'billing_correction_only', false)
                && in_array(ShootEditingAssignmentService::LANE_VIDEO, app(DeliveryComponentRequirements::class)->kinds($item), true));
    }

    private function persistedLinks(Shoot $shoot): array
    {
        $decode = static function ($value): array {
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);
            return is_array($decoded) ? $decoded : [];
        };
        $links = ['order' => $decode(DB::table('shoots')->where('id', $shoot->id)->value('tour_links'))];
        foreach ($shoot->units()->get(['id', 'tour_links']) as $unit) $links['unit:'.$unit->id] = $decode($unit->tour_links);
        return $links;
    }

    private function validLinks(array $links): array
    {
        $valid = [];
        foreach (self::KEYS as $key) {
            $url = is_string($links[$key] ?? null) ? trim($links[$key]) : '';
            if (filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) $valid[$key] = $url;
        }
        return $valid;
    }

    private function fingerprint(array $links): string
    {
        return hash('sha256', json_encode($this->validLinks($links), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function cycle(Shoot $shoot): ?string
    {
        return $shoot->active_work_started_at?->toIso8601String();
    }

    private function scope(ShootService $item): string
    {
        return $item->shoot_unit_id ? 'unit:'.$item->shoot_unit_id : 'order';
    }

    private function state(ShootService $item): array
    {
        $value = $item->active_work_link_output;
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function write(ShootService $item, array $state): void
    {
        // No model save: readiness reconciliation must not recurse or change pricing/assignments.
        DB::table('shoot_service')->where('shoot_id', $item->shoot_id)->where('id', $item->id)
            ->update(['active_work_link_output' => json_encode($state, JSON_THROW_ON_ERROR)]);
    }
}
