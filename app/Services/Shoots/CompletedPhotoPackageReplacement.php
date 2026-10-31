<?php

namespace App\Services\Shoots;

use App\Models\{Service, Shoot, ShootService};

/** A count correction to captured photos keeps the booked execution and its foreign keys. */
class CompletedPhotoPackageReplacement
{
    public function resolve(Shoot $shoot, array $rows): array
    {
        // This annotation is server-derived, never an accepted client instruction.
        foreach ($rows as &$row) unset($row['replaces_shoot_service_id']);
        unset($row);
        if ($shoot->isComplimentaryReshoot() || collect([$shoot->status, $shoot->workflow_status])->contains(
            fn ($status) => in_array($status, ['cancelled', 'canceled', 'declined', 'on_hold', 'hold_on'], true)
        )) return $rows;

        $items = $shoot->serviceItems()->with(['service.category', 'unit'])->get();
        $multiUnit = $shoot->units()->exists();
        $kept = $items->filter(fn ($item) => collect($rows)->contains(fn ($row) => $multiUnit
            ? (int) ($row['shoot_service_id'] ?? 0) === (int) $item->id
            : (int) $row['id'] === (int) $item->service_id));
        $removed = $items->whereNotIn('id', $kept->pluck('id'));
        if ($removed->isEmpty()) return $rows;
        $added = collect($rows)->filter(fn ($row) => ! $items->contains(fn ($item) => $multiUnit
            ? (int) ($row['shoot_service_id'] ?? 0) === (int) $item->id
            : (int) $row['id'] === (int) $item->service_id));
        if ($added->isEmpty()) return $rows;
        $catalog = Service::with('category')->whereIn('id', $added->pluck('id'))->get()->keyBy('id');

        foreach ($added as $index => $row) {
            $target = $catalog->get($row['id']);
            $candidates = $removed->filter(fn ($item) => $this->matches($shoot, $item, $target, $row));
            if ($candidates->count() !== 1) continue;
            $source = $candidates->sole();
            // Never guess between two packages or two apartments in a bulk edit.
            if ($added->filter(fn ($other) => $this->matches($shoot, $source, $catalog->get($other['id']), $other))->count() !== 1) continue;

            $rows[$index] = array_replace($row, $source->only([
                'client_key', 'shoot_unit_id', 'photographer_id', 'editor_id', 'video_editor_id',
                'editing_completed_at', 'video_editing_completed_at', 'workflow_status', 'delivery_status',
                'ready_at', 'delivered_at', 'cancelled_at', 'is_deliverable', 'force_unlock_delivery',
                'unlock_reason', 'unlocked_by', 'bracket_mode',
            ]), [
                'shoot_service_id' => $source->id,
                'replaces_shoot_service_id' => $source->id,
                'scheduled_at' => $source->scheduled_at?->format('Y-m-d H:i:s'),
                'duration_minutes' => app(ShootDurationResolver::class)->forServiceItem($source),
                'created_at' => $source->created_at,
                'contracted_photo_count' => $this->photoCount($target, $source->unit?->sqft ?? $shoot->propertySqft()),
                'photographer_pay' => $target->getPhotographerPayForSqft($source->unit?->sqft ?? $shoot->propertySqft()),
            ]);
        }

        return $rows;
    }

    private function matches(Shoot $shoot, ShootService $source, ?Service $target, array $row): bool
    {
        $original = $source->service;
        if (! $original || ! $target || ! $original->category_id
            || (int) $original->category_id !== (int) $target->category_id
            || (int) $source->shoot_unit_id !== (int) ($row['shoot_unit_id'] ?? 0)
            || (int) $source->quantity !== (int) ($row['quantity'] ?? 1)
            || $original->upload_intake_type !== Service::INTAKE_PHOTO || $target->upload_intake_type !== Service::INTAKE_PHOTO
            || ! $original->requiresPhotographer() || ! $target->requiresPhotographer()
            || (bool) $original->uses_hdr_brackets !== (bool) $target->uses_hdr_brackets
            || $source->cancelled_at || $source->workflow_status === 'cancelled' || $source->delivery_status === 'cancelled') return false;

        $sqft = $source->unit?->sqft ?? $shoot->propertySqft();
        $oldCount = $source->contracted_photo_count ?? $this->photoCount($original, $sqft);
        $newCount = $this->photoCount($target, $sqft);
        if (! $oldCount || ! $newCount || $oldCount === $newCount) return false;

        $captured = $source->ready_at || $source->delivered_at || $source->editing_completed_at
            || in_array($source->workflow_status, ['in_progress', 'ready', 'delivered'], true)
            || in_array($shoot->workflow_status ?: $shoot->status, ['completed', 'uploaded', 'editing', 'review', 'ready', 'delivered', 'ready_for_client', 'admin_verified'], true);
        if (! $captured || ! $source->scheduled_at || $source->scheduled_at->isFuture()) return false;

        // A real reassignment or return visit is not a correction to the old capture.
        if (! empty($row['photographer_id']) && (int) $row['photographer_id'] !== (int) ($source->photographer_id ?? $shoot->photographer_id)) return false;
        if (! empty($row['scheduled_at']) && ! \Carbon\Carbon::parse($row['scheduled_at'])->equalTo($source->scheduled_at)) return false;
        return true;
    }

    private function photoCount(Service $service, ?int $sqft): ?int
    {
        $tierCount = $service->pricing_type === 'variable' && $sqft
            ? $service->sqftRanges()->where('sqft_from', '<=', $sqft)->where('sqft_to', '>=', $sqft)->value('photo_count') : null;
        return $tierCount !== null ? (int) $tierCount : $service->contractedPhotoCount();
    }

    public function persist(Shoot $shoot, array $row): ShootService
    {
        $item = $shoot->serviceItems()->findOrFail($row['replaces_shoot_service_id']);
        // Freeze legacy catalogue-based Studio scopes before the catalogue ID changes.
        foreach (\App\Models\StudioWorkspace::where('shoot_id', $shoot->id)->whereNull('shoot_service_item_ids')->get() as $workspace) {
            $lineIds = \App\Services\Studio\WorkspaceServiceScope::lineIds($workspace);
            if (in_array((int) $item->id, $lineIds, true)) {
                $workspace->shoot_service_item_ids = $lineIds;
                $workspace->saveQuietly();
            }
        }
        $item->fill(collect($row)->only(['price', 'quantity', 'photographer_pay', 'contracted_photo_count'])->all());
        $item->service_id = $row['id'];
        // Preserve the earlier payout basis for review; never rewrite issued payments.
        app(\App\Services\Invoices\PhotographerPayoutBasis::class)->capture($item, $shoot, Service::findOrFail($row['id']));
        $item->saveQuietly();
        return $item;
    }
}
