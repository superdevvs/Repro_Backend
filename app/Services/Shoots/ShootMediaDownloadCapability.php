<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootFile, User};

/** One per-file capability shared by detail and media-list responses. */
class ShootMediaDownloadCapability
{
    public function forFile(Shoot $shoot, ShootFile $file, ?User $actor): array
    {
        $access = app(ShootAuthorizationSupport::class);
        $release = app(ShootClientReleaseAccessService::class);
        $allowed = $actor && $access->canDownloadShootMediaFile($shoot, $file, $actor)
            && ! $file->isBlockedFromDelivery() && ! $release->isFileReleaseLocked($shoot, $file, $actor);
        $reason = null;
        if (! $allowed) {
            $reason = match (true) {
                (bool) $file->is_hidden => 'hidden',
                $file->isBlockedFromDelivery() => $file->deliveryScanError()['error_type'],
                $access->isClientUser($actor) && app(DeliveryPlanService::class)->usesComponents($shoot)
                    && ! $release->isApprovedFile($shoot, $file) => 'content_not_finalized',
                $release->isFileReleaseLocked($shoot, $file, $actor) => 'payment_required',
                default => 'forbidden',
            };
        }
        return ['can_download' => (bool) $allowed, 'download_unavailable_reason' => $reason];
    }
}
