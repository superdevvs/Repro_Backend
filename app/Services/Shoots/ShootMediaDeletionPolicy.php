<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;

class ShootMediaDeletionPolicy
{
    public function __construct(private ShootAuthorizationSupport $access) {}

    public function allows(Shoot $shoot, ShootFile $file, ?User $user): bool
    {
        if ($user?->role === 'editor') {
            $shoot->loadMissing(['services.category', 'serviceItems.service']);
        }
        if (! $this->access->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || ! $this->access->canInteractWithShootMediaFile($shoot, $file, $user)) {
            return false;
        }
        if ($user->role !== 'editor') {
            return true;
        }
        if (! in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)
            || $this->access->isRawCameraFile($file)) {
            return false;
        }

        $item = $file->shoot_service_id ? $shoot->serviceItems->find($file->shoot_service_id) : null;
        if ($this->access->isVideoMediaFile($file)) {
            return $this->allowsOwnVideo($shoot, $file, $user, $item);
        }

        // Keep the existing photo-editor submission boundary unchanged.
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));

        return ! $shoot->submitted_for_review_at
            && ! in_array($status, ['pending_review', 'ready_for_review', 'qc', 'review', Shoot::STATUS_READY], true)
            && ! $item?->editing_completed_at;
    }

    private function allowsOwnVideo(Shoot $shoot, ShootFile $file, User $user, ?ShootService $item): bool
    {
        if ((string) $file->uploaded_by !== (string) $user->id
            || $file->workflow_stage !== ShootFile::STAGE_COMPLETED || $file->verified_at
            || ! $this->access->canEditVideoTourLinks($shoot, $user)) {
            return false;
        }

        $statuses = array_map('strtolower', array_filter([$shoot->status, $shoot->workflow_status]));
        if (array_intersect($statuses, ['cancelled', 'declined', 'finalized', 'archived'])
            || ($item && ($item->cancelled_at || $item->workflow_status === ShootService::WORKFLOW_CANCELLED
                || $item->delivery_status === ShootService::DELIVERY_CANCELLED))) {
            return false;
        }

        $bundled = $item?->service?->uploadIntakeType() === Service::INTAKE_PHOTO_VIDEO;
        $laneCompleted = $bundled ? $item->video_editing_completed_at : $item?->editing_completed_at;
        $delivered = (bool) array_intersect($statuses, ['delivered', 'admin_verified', 'client_delivered', 'workflow_completed', 'ready_for_client']);
        if ($item?->delivered_at || $item?->delivery_status === ShootService::DELIVERY_DELIVERED
            || $item?->workflow_status === ShootService::WORKFLOW_DELIVERED) {
            // A bundled row can be delivered while its independent video lane is still open.
            if (! $bundled || $laneCompleted) {
                return false;
            }
        }

        // Review submission alone does not finalize a video. When photos have already
        // been delivered, require a concrete unfinished video lane rather than the
        // legacy shoot-wide editor fallback. Finalized files are verified above.
        return ! $delivered || ($item && ! $laneCompleted);
    }
}
