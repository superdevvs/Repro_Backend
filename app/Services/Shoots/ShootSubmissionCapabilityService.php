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
    public const DELIVERED_STATUSES = ['delivered', 'ready_for_client', 'admin_verified', 'client_delivered', 'workflow_completed', 'finalized', 'delivered_to_client'];

    public function pendingEditorAssignments(Shoot $shoot, User $user): bool
    {
        return app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot)
            ->contains(fn ($assignment) => (int) $assignment['editor_id'] === (int) $user->id && empty($assignment['editing_completed_at']));
    }

    public function editedFiles(Shoot $shoot, ?User $user = null): \Illuminate\Support\Collection
    {
        $files = $shoot->files()->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->where(fn ($query) => $query->whereNull('flag_reason')->orWhere('flag_reason', ''))->get();
        return $user?->role === 'editor'
            ? app(ShootEditingAssignmentService::class)->filterFilesForEditor($files, $shoot, $user)
            : $files;
    }

    public function hasVideoLinkOutput(Shoot $shoot, ?User $user): bool
    {
        if (!$user) return false;
        $assignments = app(ShootEditingAssignmentService::class)->getTrackedServiceAssignments($shoot)->where('lane', ShootEditingAssignmentService::LANE_VIDEO);
        if ($user->role === 'editor') $assignments = $assignments->where('editor_id', (int) $user->id);
        return $assignments->contains(fn ($assignment) => $this->assignmentHasVideoLink($shoot, $assignment));
    }

    public function assignmentHasVideoLink(Shoot $shoot, array $assignment): bool
    {
            if ($assignment['lane'] !== ShootEditingAssignmentService::LANE_VIDEO) return false;
            $links = $assignment['shoot_unit_id'] ? $shoot->units()->find($assignment['shoot_unit_id'])?->tour_links : $shoot->tour_links;
            foreach (['video_link', 'video_branded', 'video_mls', 'video_generic'] as $key) {
                $url = $links[$key] ?? null;
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) return true;
            }
            return false;
    }

    public function canSubmitEdits(Shoot $shoot, ?User $user): bool
    {
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true)) return false;
        $assignments = app(ShootEditingAssignmentService::class);
        if ($user->role === 'editor' && !$assignments->editorHasAssignment($shoot, $user)) return false;
        $files = $this->editedFiles($shoot, $user);
        if ($files->isEmpty() && !$this->hasVideoLinkOutput($shoot, $user)) return false;
        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        if (in_array($status, ['uploaded', 'editing'], true)) {
            return $user->role !== 'editor' || $assignments->getTrackedServiceAssignments($shoot)->isEmpty() || $this->pendingEditorAssignments($shoot, $user);
        }
        if ($user->role === 'editor' && in_array($status, array_merge(['review', 'ready'], self::DELIVERED_STATUSES), true)
            && $this->pendingEditorAssignments($shoot, $user)) return true;
        if ($status === 'review') return $user->role !== 'editor';
        return $status === 'ready' && (!$shoot->editing_completed_at || $files->contains(fn ($file) => $file->created_at > $shoot->editing_completed_at));
    }

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
