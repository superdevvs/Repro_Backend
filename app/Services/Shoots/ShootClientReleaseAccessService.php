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
        $paymentStatus = $shoot->payment_status;

        if (!$paymentStatus || $paymentStatus === 'pending') {
            $paymentStatus = $this->paymentStatusSupport->calculatePaymentStatus(
                (float) ($shoot->total_paid ?? 0),
                (float) ($shoot->total_quote ?? 0)
            );
        }

        return $paymentStatus;
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
