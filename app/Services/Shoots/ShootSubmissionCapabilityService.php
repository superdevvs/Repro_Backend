<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;

/**
 * Server-owned workflow capabilities shared by Resources and Presenter payloads.
 */
class ShootSubmissionCapabilityService
{
    private const SUBMIT_RAW_ALLOWED_STATUSES = [
        'scheduled',
        'booked',
        'raw_upload_pending',
    ];

    public function canSubmitRaw(Shoot $shoot, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (! in_array($role, ['admin', 'superadmin', 'editing_manager', 'photographer'], true)) {
            return false;
        }

        if ($role === 'photographer') {
            $isPrimaryPhotographer = (int) $shoot->photographer_id === (int) $user->id;
            $isServicePhotographer = $shoot->serviceItems()
                ->where('photographer_id', $user->id)
                ->exists();

            if (! $isPrimaryPhotographer && ! $isServicePhotographer) {
                return false;
            }
        }

        $status = strtolower((string) ($shoot->workflow_status ?? $shoot->status ?? ''));
        $hasRawFiles = (int) ($shoot->raw_photo_count ?? 0) > 0
            || $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->exists();

        if (! $hasRawFiles) {
            return false;
        }

        if (in_array($status, self::SUBMIT_RAW_ALLOWED_STATUSES, true)) {
            return true;
        }

        if ($status !== 'uploaded') {
            return false;
        }

        if (! $shoot->photos_uploaded_at) {
            return true;
        }

        return $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->where('created_at', '>', $shoot->photos_uploaded_at)
            ->exists();
    }
}
