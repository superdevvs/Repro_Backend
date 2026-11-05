<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ShootClientReleaseAccessService
{
    public function __construct(protected ShootPaymentStatusSupport $paymentStatusSupport)
    {
    }

    public function resolvePaymentStatus(Shoot $shoot): string
    {
        // An explicit zero-dollar quote has no balance to collect, even when
        // its stored status is the complimentary "no_payment_required" value.
        // Keep an unknown (null) quote behind the normal payment gate.
        if ($shoot->total_quote !== null && (float) $shoot->total_quote <= 0.01) {
            return 'paid';
        }

        $paymentStatus = $shoot->payment_status;

        if (!$paymentStatus || $paymentStatus === 'pending') {
            $paymentStatus = $this->paymentStatusSupport->calculatePaymentStatus(
                (float) ($shoot->total_paid ?? 0),
                (float) ($shoot->total_quote ?? 0)
            );
        }

        return $paymentStatus;
    }

    /** Approved component files plus unchanged, grandfathered delivery history. */
    public function isApprovedFile(Shoot $shoot, ShootFile $file): bool
    {
        if ((int) $file->shoot_id !== (int) $shoot->id || $file->workflow_stage !== ShootFile::STAGE_VERIFIED
            || $file->is_hidden || $file->isBlockedFromDelivery() || $file->is_extra || $file->isIguideOfflinePackage()
            || in_array(strtolower((string) $file->media_type), ['raw', 'extra', 'reference', 'additional'], true)
            || data_get($file->metadata, 'reference_only')) return false;
        if (app(DeliveryPlanService::class)->releasedFile($file)) return true;
        if (! $file->shoot_service_id) {
            if ($shoot->active_work_started_at && $shoot->admin_verified_at && $shoot->admin_verified_at->lte($shoot->active_work_started_at)) {
                return $shoot->admin_verified_at && $shoot->admin_verified_at->lte($shoot->active_work_started_at)
                    && $file->created_at && $file->created_at->lte($shoot->active_work_started_at)
                    && (! $file->verified_at || $file->verified_at->lte($shoot->active_work_started_at));
            }
            return ! app(DeliveryPlanService::class)->usesComponents($shoot);
        }
        $item = $file->relationLoaded('serviceItem') ? $file->serviceItem : $file->serviceItem()->first();
        return $item && $item->is_deliverable && (int) $item->shoot_id === (int) $shoot->id && ! $item->cancelled_at
            && $item->workflow_status === ShootService::WORKFLOW_DELIVERED && $item->delivery_status === ShootService::DELIVERY_DELIVERED
            && ! collect($item->delivery_requirements ?? [])->contains(fn ($component) => ($component['kind'] ?? '') !== 'legacy');
    }

    public function isClientReleaseLocked(Shoot $shoot, ?User $user): bool
    {
        if (($user?->role ?? null) !== 'client') {
            return false;
        }

        if ($shoot->bypass_paywall) {
            return false;
        }

        return $this->resolvePaymentStatus($shoot) !== 'paid';
    }

    public function isFileReleaseLocked(Shoot $shoot, ShootFile $file, ?User $user): bool
    {
        if (!$this->isClientReleaseLocked($shoot, $user)) {
            return false;
        }

        if (!$file->shoot_service_id) {
            return true;
        }

        $serviceItem = $file->relationLoaded('serviceItem')
            ? $file->serviceItem
            : $file->serviceItem()->with('shoot')->first();

        if ($serviceItem && app(DeliveryPlanService::class)->releasedFile($file)) {
            $serviceItem->setRelation('shoot', $shoot);
            return ! $serviceItem->is_unlocked_for_delivery;
        }
        return !$serviceItem || !$this->isServiceItemUnlockedForClientDelivery($serviceItem);
    }

    public function isArchiveReleaseLocked(Shoot $shoot, ?int $shootServiceId, ?User $user): bool
    {
        if (($user?->role ?? null) !== 'client') {
            return false;
        }

        if ($shoot->bypass_paywall) {
            return false;
        }

        if ($shootServiceId === null) {
            return $this->resolvePaymentStatus($shoot) !== 'paid';
        }

        $serviceItem = $shoot->serviceItems()->whereKey($shootServiceId)->first();

        if ($serviceItem && in_array((int) $serviceItem->id, app(DeliveryPlanService::class)->releasedServiceIds($shoot), true)) {
            $serviceItem->setRelation('shoot', $shoot);
            return ! $serviceItem->is_unlocked_for_delivery;
        }
        return !$serviceItem || !$this->isServiceItemUnlockedForClientDelivery($serviceItem);
    }

    public function isPublicReleaseLocked(Shoot $shoot): bool
    {
        if ($shoot->bypass_paywall) {
            return false;
        }

        return $this->resolvePaymentStatus($shoot) !== 'paid';
    }

    public function downloadLockedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Payment required to download files for this shoot.',
            'code' => 'payment_required',
        ], 403);
    }

    private function isServiceItemUnlockedForClientDelivery(ShootService $serviceItem): bool
    {
        if ($serviceItem->force_unlock_delivery) {
            return true;
        }

        return $serviceItem->is_unlocked_for_delivery
            && in_array($serviceItem->delivery_status, [
                ShootService::DELIVERY_READY,
                ShootService::DELIVERY_DELIVERED,
            ], true);
    }

    public function buildLockedPublicPayload(Shoot $shoot, string $type): array
    {
        return [
            'locked' => true,
            'message' => 'Payment required to unlock this tour.',
            'type' => $type,
            'shoot' => [
                'id' => $shoot->id,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_date' => optional($shoot->scheduled_date)->toDateString(),
            ],
            'photos' => [],
            'videos' => [],
            'floorplans' => [],
            'iguide_floorplans' => [],
            'matterport_url' => null,
            'iguide_tour_url' => null,
            'iguide_url' => null,
            'tour_links' => [],
            'embeds' => [],
            'show_garage' => false,
        ];
    }
}
