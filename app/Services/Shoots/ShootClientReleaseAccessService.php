<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
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
