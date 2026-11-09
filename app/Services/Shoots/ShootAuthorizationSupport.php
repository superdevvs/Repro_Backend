<?php

namespace App\Services\Shoots;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootUnit;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShootAuthorizationSupport
{
    public const VIDEO_TOUR_LINK_KEYS = [
        'video_link', 'video_branded', 'video_mls', 'video_generic',
        'embeds', 'featured_embed_id', 'featured_embed',
    ];

    public function canEditVideoTourLinks(Shoot $shoot, ?User $user, ?ShootUnit $unit = null): bool
    {
        if (! $this->hasRole($user, ['editor']) || ! $this->canViewShootDetails($shoot, $user)) {
            return false;
        }

        $shoot->loadMissing('serviceItems.service');
        $items = $unit ? $shoot->serviceItems->where('shoot_unit_id', $unit->id) : $shoot->serviceItems;
        if ($items->contains(function ($item) use ($user) {
            $intake = $item->service?->uploadIntakeType();

            return ($intake === Service::INTAKE_PHOTO_VIDEO && (string) $item->video_editor_id === (string) $user->id)
                || ((string) $item->editor_id === (string) $user->id
                    && (! $item->video_editor_id || (string) $item->video_editor_id === (string) $user->id)
                    && ($intake === Service::INTAKE_VIDEO || $user->canEditLane('video')));
        })) {
            return true;
        }

        if (! $user->canEditLane('video')) {
            return false;
        }
        return (string) $shoot->editor_id === (string) $user->id
            && (! $unit || $items->isNotEmpty())
            && $items->every(fn ($item) => ! $item->editor_id && ! $item->video_editor_id);
    }

    /** Client display name and Company notes are read-only context for the current video editor. */
    public function canVideoEditorReadClientContext(Shoot $shoot, ?User $user): bool
    {
        if ($user?->role === 'editor' && app(ShootDispatchOverviewReturns::class)->items($shoot, $user)->contains('lane', 'video')) {
            return true;
        }
        // The video checks load raw serviceItems. Keep that relation off the
        // source model so it cannot replace the presenter's sanitized alias.
        $contextShoot = clone $shoot;

        return $this->canEditVideoTourLinks($contextShoot, $user)
            && $this->hasVideoReferenceAssignment($contextShoot, $user);
    }

    private const CLIENT_DELIVERED_STATUSES = [
        Shoot::STATUS_DELIVERED,
        'ready_for_client',
        'admin_verified',
        'ready',
        'workflow_completed',
        'client_delivered',
    ];

    public function hasRole(?User $user, array $roles): bool
    {
        if (! $user) {
            return false;
        }

        $normalizedRole = $this->normalizeRole($user->role ?? '');
        $normalizedAllowed = array_map(fn (string $role) => $this->normalizeRole($role), $roles);

        return in_array($normalizedRole, $normalizedAllowed, true);
    }

    public function canBookOutsideClientServiceGroups(?User $actor): bool
    {
        return $this->hasRole($actor, ['admin', 'superadmin']) || app(ShootManagementAccess::class)->can($actor);
    }

    public function ensureRole(array $roles, ?User $user = null, string $message = 'Forbidden'): void
    {
        $user = $user ?? auth()->user();
        if (! $this->hasRole($user, $roles)) {
            abort(403, $message);
        }
    }

    public function ensureFileBelongsToShoot(Shoot $shoot, ShootFile $file): void
    {
        if ((int) $file->shoot_id !== (int) $shoot->id) {
            abort(404, 'File does not belong to this shoot');
        }
    }

    public function canManageRequestedShoot(Shoot $shoot, ?User $user): bool
    {
        return strtolower((string) ($shoot->workflow_status ?: $shoot->status)) === Shoot::STATUS_REQUESTED
            && app(ShootManagementAccess::class)->can($user, 'manage');
    }

    public function isClientUser(?User $user): bool
    {
        return $this->normalizeRole($user?->role ?? '') === 'client';
    }

    public function ensureClientOwnsShoot(Shoot $shoot, ?User $user = null): void
    {
        $user = $user ?? auth()->user();
        if (! $this->isClientUser($user)) {
            return;
        }

        if (! $this->canClientAccessShoot($shoot, $user)) {
            abort(403, 'Forbidden');
        }
    }

    public function canClientAccessShoot(Shoot $shoot, ?User $user = null): bool
    {
        if ($shoot->isImportDraft()) {
            return false;
        }

        $user = $user ?? auth()->user();
        if (! $this->isClientUser($user)) {
            return false;
        }

        if ((string) $shoot->client_id === (string) $user->id) {
            return true;
        }

        if ($this->canClientAccessLinkedShoot($shoot, $user)) {
            return true;
        }

        if (! $this->isShootDeliveredForClientAccess($shoot)) {
            return false;
        }

        if ($shoot->relationLoaded('ghostUsers')) {
            return $shoot->ghostUsers->contains(fn ($ghostUser) => (string) data_get($ghostUser, 'id') === (string) $user->id);
        }

        return $shoot->ghostUsers()
            ->where('users.id', $user->id)
            ->exists();
    }

    private function canClientAccessLinkedShoot(Shoot $shoot, User $user): bool
    {
        if (! $shoot->client_id) {
            return false;
        }

        return AccountLink::query()
            ->where('main_account_id', $user->id)
            ->where('linked_account_id', $shoot->client_id)
            ->where('status', 'active')
            ->get()
            ->contains(fn (AccountLink $link) => $link->sharesDetail('shoots'));
    }

    public function isShootDeliveredForClientAccess(Shoot $shoot): bool
    {
        if (collect([$shoot->workflow_status, $shoot->status])->contains(fn ($status) => in_array(strtolower((string) $status), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true))) return false;
        if (app(DeliveryPlanService::class)->hasReleasedComponents($shoot)) return true;
        if (app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot)
            && $shoot->serviceItems()->where('delivery_status', 'delivered')->exists()) return true;
        $normalizedStatus = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));

        return in_array($normalizedStatus, self::CLIENT_DELIVERED_STATUSES, true);
    }

    /** Query counterpart of canAccessShootMedia; per-file and release checks still apply. */
    public function scopeAccessibleShootMedia(Builder $query, ?User $user): Builder
    {
        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'])) {
            return $query;
        }
        if ($this->hasRole($user, ['editor'])) {
            return $query->where(function (Builder $scope) use ($user) {
                app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($scope, $user->id);
                if (app(ShootManagementAccess::class)->isRestrictedSalesRep($user)) $scope->orWhere('rep_id', $user->id);
            });
        }
        if ($this->hasRole($user, ['photographer'])) {
            return $query->where(function (Builder $scope) use ($user) {
                app(ShootPhotographerAssignmentService::class)->scopeAssignedTo($scope, $user->id);
                if (app(ShootManagementAccess::class)->isRestrictedSalesRep($user)) $scope->orWhere('rep_id', $user->id);
            });
        }
        if ($this->isClientUser($user)) {
            $clientIds = [$user->id, ...AccountLink::getLinkedClientIdsForOwner((int) $user->id, 'shoots')];

            return $query->where(fn (Builder $scope) => $scope->whereIn('client_id', $clientIds)
                ->orWhere(fn (Builder $ghost) => $ghost->whereHas('ghostUsers', fn (Builder $recipient) => $recipient->where('users.id', $user->id))
                    ->whereNotIn(DB::raw("LOWER(COALESCE(shoots.status, ''))"), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'])
                    ->whereNotIn(DB::raw("LOWER(COALESCE(shoots.workflow_status, ''))"), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'])
                    // A suspended flag cannot be bypassed by an older Delivered flag.
                    ->where(fn (Builder $delivered) => $delivered->whereIn(\Illuminate\Support\Facades\DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), self::CLIENT_DELIVERED_STATUSES)
                        ->orWhere(fn (Builder $active) => $active->whereRaw(ActiveWorkQuery::openSql())
                            ->whereHas('serviceItems', fn (Builder $item) => $item->where('delivery_status', 'delivered')))
                        ->orWhereExists(fn ($receipts) => $receipts->selectRaw('1')->from('shoot_delivery_receipts')
                            ->join('shoot_service as released_line', 'released_line.id', '=', 'shoot_delivery_receipts.shoot_service_id')
                            ->whereColumn('shoot_delivery_receipts.shoot_id', 'shoots.id')->whereNull('revoked_at')
                            ->whereNull('released_line.cancelled_at')->where('released_line.workflow_status', '!=', 'cancelled')
                            ->where('released_line.delivery_status', '!=', 'cancelled')
                            ->whereNotIn(DB::raw("LOWER(COALESCE(NULLIF(shoots.workflow_status, ''), shoots.status, ''))"), ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'])))));
        }

        return $query->whereRaw('1 = 0');
    }

    public function canAccessShootMedia(Shoot $shoot, ?User $user = null): bool
    {
        if ($shoot->isImportDraft()) {
            return $this->hasRole($user ?? auth()->user(), ['admin', 'superadmin']);
        }

        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'salesRep', 'rep', 'representative'])) {
            return true;
        }
        if ($this->canManageCustomerMediaCuration($shoot, $user)) {
            return true;
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->isPhotographerAssignedToShoot($shoot, $user);
        }

        if ($this->hasRole($user, ['editor'])) {
            $assignments = app(ShootEditingAssignmentService::class);

            // Dispatch tasks open the existing overview. They are not a shoot editor row.
            return $assignments->editorHasAssignment($shoot, $user)
                || $assignments->hasDispatchAccess($shoot, $user)
                || count(app(ShootEditorRequestAccess::class)->requests($shoot, $user)) > 0;
        }

        if ($this->isClientUser($user)) {
            return $this->canClientAccessShoot($shoot, $user);
        }

        return false;
    }

    /**
     * Determine whether an actor may create media for this shoot.
     *
     * Read access is intentionally not sufficient here: clients, linked
     * accounts, ghost recipients, and sales representatives can be allowed to
     * view a shoot without ever being allowed to mutate its media collection.
     */
    public function canUploadShootMedia(
        Shoot $shoot,
        ?User $user = null,
        string $uploadType = 'raw',
        ?int $shootServiceId = null
    ): bool {
        if (strtolower(trim($uploadType)) === 'additional') {
            $shoot = Shoot::find($shoot->id);
            if (! $shoot) return false;
        }
        if ($shoot->isImportDraft()) {
            return false;
        }

        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        $normalizedUploadType = strtolower(trim($uploadType));
        if (! app(\App\Services\RolePermissionService::class)->userCan($user, 'media', 'upload')) return false;
        if (! in_array($normalizedUploadType, ['raw', 'edited', 'additional'], true)) {
            return false;
        }

        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($this->hasRole($user, ['photographer'])) {
            if (! in_array($normalizedUploadType, ['raw', 'additional'], true)) {
                return false;
            }

            return $shootServiceId
                ? $this->canPhotographerAccessServiceItem($shoot, $shootServiceId, $user)
                : $this->isPhotographerAssignedToShoot($shoot, $user);
        }

        if (! $this->hasRole($user, ['editor']) || $normalizedUploadType !== 'edited') {
            return false;
        }

        $assignmentService = app(ShootEditingAssignmentService::class);
        if (! $shootServiceId) {
            return $assignmentService->editorHasAssignment($shoot, $user)
                || $assignmentService->hasOpenDispatchAssignment($shoot, $user);
        }

        $serviceItem = $shoot->serviceItems()
            ->with('service')
            ->whereKey($shootServiceId)
            ->first();

        if (! $serviceItem) {
            return false;
        }

        if ($assignmentService->serviceRequiresEditing($serviceItem)) {
            // Legacy top-level editors retain access to all editing-required items.
            if ((string) $shoot->editor_id === (string) $user->id) {
                return true;
            }

            // Photo-lane (and non-bundled) assignments live on editor_id.
            if ((string) ($serviceItem->editor_id ?? '') === (string) $user->id) {
                return true;
            }

            // Bundled photo_video (and video-capable) services assign the video lane
            // on video_editor_id. FE always sends shoot_service_id for edited uploads,
            // so omitting this check 403s video editors (e.g. user 1100 on #145/#377).
            if ((string) ($serviceItem->video_editor_id ?? '') === (string) $user->id) {
                return $serviceItem->service?->supportsVideoIntake() ?? false;
            }
        }

        // Send-to-editing assigns the task, not the shoot or service editor row.
        // A task tied to this line is enough. A task with no line still covers
        // the lines that belong to this shoot, because the files were not split
        // across them. A line on another shoot never reaches this return.
        return $assignmentService->hasOpenDispatchForService($shoot, $user, (int) $serviceItem->id)
            || $assignmentService->hasUnscopedOpenDispatch($shoot, $user);
    }

    public function canViewShootDetails(Shoot $shoot, ?User $user = null): bool
    {
        return $this->canAccessShootMedia($shoot, $user)
            || (! $shoot->isImportDraft() && app(ShootManagementAccess::class)->can($user));
    }

    public function isOnHold(Shoot $shoot): bool
    {
        foreach ([$shoot->status, $shoot->workflow_status] as $status) {
            if (in_array(strtolower((string) $status), ['on_hold', 'hold_on'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Staff need both booking capabilities; assigned contractors retain time-only scheduling. */
    public function canScheduleShoot(Shoot $shoot, ?User $user): bool
    {
        $management = app(ShootManagementAccess::class);
        if ($management->hasBookingRole($user)) {
            return $management->canEditAppointment($shoot, $user) && $management->can($user, 'manage');
        }
        if (! $user || $shoot->isImportDraft()) {
            return false;
        }
        $permissions = app(\App\Services\RolePermissionService::class);
        if ($permissions->userIsDenied($user, 'shoots', 'update') || $permissions->userIsDenied($user, 'shoots', 'manage')) {
            return false;
        }
        return ($this->hasRole($user, ['photographer']) && $this->isPhotographerAssignedToShoot($shoot, $user))
            || ($this->hasRole($user, ['editor']) && app(ShootEditingAssignmentService::class)->editorHasAssignment($shoot, $user));
    }

    /** Hold shoots are still being arranged, so sales can set the appointment. */
    public function canManageHoldShoot(Shoot $shoot, ?User $user): bool
    {
        return app(ShootManagementAccess::class)->can($user)
            && app(ShootManagementAccess::class)->can($user, 'manage')
            && $this->isOnHold($shoot)
            && $this->canViewShootDetails($shoot, $user);
    }

    public function canEditShootAppointment(Shoot $shoot, ?User $user): bool
    {
        return app(ShootManagementAccess::class)->canEditAppointment($shoot, $user);
    }

    public function ensureShootAccess(Shoot $shoot, ?User $user = null): void
    {
        abort_unless($this->canViewShootDetails($shoot, $user), 403, 'Forbidden');
    }

    public function canManageShootOperations(?User $user): bool
    {
        return $this->hasRole($user, ['admin', 'superadmin', 'editing_manager']);
    }

    public function canManageZillowVisibility(Shoot $shoot, ?User $user): bool
    {
        return $user && ($this->canManageShootOperations($user)
            || ($this->hasRole($user, ['client']) && (int) $shoot->client_id === (int) $user->id))
            && $this->canViewShootDetails($shoot, $user);
    }

    /** Request review is an office/sales duty, independent of media workflow writes. */
    public function canReviewShootRequests(?User $user): bool
    {
        return app(ShootManagementAccess::class)->can($user, 'manage');
    }

    public function canApproveReschedule(Shoot $shoot, ?User $user): bool
    {
        return $this->canTriageShootRequests($shoot, $user)
            && $this->canEditShootAppointment($shoot, $user);
    }

    public function canRequestReschedule(Shoot $shoot, ?User $user): bool
    {
        return ! app(ShootManagementAccess::class)->hasBookingRole($user)
            && $this->canSubmitShootRequest($shoot, $user);
    }

    /** Shared by detail, card, and request-queue payloads. */
    public function schedulingCapabilities(Shoot $shoot, ?User $user): array
    {
        $management = app(ShootManagementAccess::class);
        $capabilities = $management->capabilitiesForShoot($shoot, $user);
        $canEdit = $capabilities['canEditAppointment'];
        $canReview = $capabilities['canManageShootActions'] && (! $shoot->isImportDraft()
            || ($this->canManageShootOperations($user) && $this->canViewShootDetails($shoot, $user)));
        $staff = $management->hasBookingRole($user);

        return $capabilities + [
            'canReassignPhotographer' => $canEdit,
            'canScheduleShoot' => $staff ? $canEdit && $capabilities['canManageShootActions'] : $this->canScheduleShoot($shoot, $user),
            'canReviewRequests' => $canReview,
            'canApproveReschedule' => $canEdit && $canReview,
            'canRequestReschedule' => ! $staff && $this->canSubmitShootRequest($shoot, $user),
        ];
    }

    public function canTriageShootRequests(Shoot $shoot, ?User $user): bool
    {
        if ($shoot->isImportDraft()) {
            return $this->canReviewShootRequests($user)
                && $this->canManageShootOperations($user) && $this->canViewShootDetails($shoot, $user);
        }

        return $this->canReviewShootRequests($user);
    }

    public function canViewShootRequests(Shoot $shoot, ?User $user): bool
    {
        return $this->canTriageShootRequests($shoot, $user) || $this->canViewShootDetails($shoot, $user);
    }

    public function scopeAccessibleShootRequests(Builder $query, ?User $user): Builder
    {
        if (! $this->canReviewShootRequests($user)) {
            return $this->scopeAccessibleShootMedia($query, $user);
        }

        // Keep this scope consistent with canTriageShootRequests even when a
        // caller has removed the model's private-import-draft global scope.
        return $this->hasRole($user, ['admin', 'superadmin'])
            ? $query
            : $query->where($query->getModel()->qualifyColumn('status'), '!=', Shoot::STATUS_IMPORT_DRAFT);
    }

    /** Viewing a linked/shared delivery never grants the recipient workflow writes. */
    public function canSubmitShootRequest(Shoot $shoot, ?User $user): bool
    {
        if (app(ShootManagementAccess::class)->hasBookingRole($user)) {
            return $this->canTriageShootRequests($shoot, $user);
        }
        if (! $user || ! $this->canViewShootDetails($shoot, $user)) {
            return false;
        }

        if ($this->isClientUser($user)) {
            return (string) $shoot->client_id === (string) $user->id;
        }

        return $this->hasRole($user, [
            'photographer', 'editor',
        ]);
    }

    public function canResolveShootIssues(Shoot $shoot, ?User $user): bool
    {
        return $this->canAccessShootMedia($shoot, $user)
            && $this->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor']);
    }

    /** Contractor counters must describe the same assigned files as their gallery. */
    public function scopedMediaCounts(Shoot $shoot, ?User $user): ?array
    {
        if (! $this->hasRole($user, ['photographer', 'editor'])) {
            return null;
        }

        $files = ($shoot->relationLoaded('files') ? $shoot->files : $shoot->files()->get())
            ->filter(fn (ShootFile $file) => $this->canInteractWithShootMediaFile($shoot, $file, $user));
        $items = $shoot->serviceItems()->with('service')->get()->filter(function ($item) use ($shoot, $user) {
            if ($this->hasRole($user, ['photographer'])) {
                return $this->canPhotographerAccessServiceItem($shoot, $item->id, $user);
            }

            return (string) $shoot->editor_id === (string) $user->id
                || (string) ($item->editor_id ?? '') === (string) $user->id
                || (string) ($item->video_editor_id ?? '') === (string) $user->id;
        });
        $brackets = app(BracketModeResolver::class);
        $expectedRaw = (int) $items->sum(fn ($item) => $brackets->expectedRawForService($item) ?? 0);
        $expectedFinal = (int) $items->sum(fn ($item) => app(UploadIntakeResolver::class)->contractedPhotoCount($item) ?? 0);
        $raw = $files->filter(fn (ShootFile $file) => ! $file->workflow_stage || $file->workflow_stage === ShootFile::STAGE_TODO)->count();
        $edited = $files->filter(fn (ShootFile $file) => in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)
            && ! $this->isRawCameraFile($file))->count();

        return [
            'raw_photo_count' => $raw,
            'edited_photo_count' => $edited,
            'extra_photo_count' => $files->filter(fn (ShootFile $file) => $file->is_extra || $file->media_type === 'extra')->count(),
            'expected_raw_count' => $expectedRaw,
            'expected_final_count' => $expectedFinal,
            'raw_missing_count' => max(0, $expectedRaw - $raw),
            'edited_missing_count' => max(0, $expectedFinal - $edited),
            'bracket_mode' => $shoot->bracket_mode,
        ];
    }

    public function isRawCameraFile(ShootFile $file): bool
    {
        $rawExtensions = ['raw', 'cr2', 'cr3', 'nef', 'arw', 'dng', 'raf', 'rw2', 'orf', 'pef', 'srw', '3fr', 'fff', 'iiq', 'rwl', 'x3f'];
        $rawMimeFragments = ['canon-raw', 'canon-cr2', 'canon-cr3', 'nikon-nef', 'sony-arw', 'adobe-dng', 'phaseone-iiq', 'raw'];
        $name = strtolower((string) ($file->filename ?? $file->stored_filename ?? ''));
        $mime = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($extension !== '' && in_array($extension, $rawExtensions, true)) {
            return true;
        }

        foreach ($rawMimeFragments as $fragment) {
            if ($mime !== '' && Str::contains($mime, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function isImageMediaFile(ShootFile $file): bool
    {
        $name = strtolower((string) $file->filename);
        $mime = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if ($mime !== '' && Str::startsWith($mime, 'image/')) {
            return true;
        }

        return (bool) preg_match('/\.(jpg|jpeg|png|gif|webp|tiff|tif|heic|heif)$/', $name);
    }

    public function isVideoMediaFile(ShootFile $file): bool
    {
        if (($file->media_type ?? null) === 'video') {
            return true;
        }

        $name = strtolower((string) $file->filename);
        $mime = strtolower((string) ($file->file_type ?? $file->mime_type ?? ''));
        if ($mime !== '' && Str::startsWith($mime, 'video/')) {
            return true;
        }

        return (bool) preg_match('/\.(mp4|mov|avi|mkv|wmv|webm)$/', $name);
    }

    public function isClientManageableEditedFile(ShootFile $file): bool
    {
        if ($file->is_hidden) {
            return false;
        }

        return $this->isClientInteractableEditedFile($file);
    }

    public function isClientInteractableEditedFile(ShootFile $file): bool
    {
        if (! in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)) {
            return false;
        }

        return ! $this->isRawCameraFile($file);
    }

    public function isClientHeroEligibleFile(ShootFile $file): bool
    {
        if (! $this->isClientManageableEditedFile($file) || $file->isBlockedFromDelivery()
            || $file->isIguideOfflinePackage() || strtolower((string) $file->media_type) === 'raw') {
            return false;
        }

        $mediaType = strtolower((string) ($file->media_type ?? ''));
        if (in_array($mediaType, ['extra', 'floorplan'], true)) {
            return false;
        }

        if ($this->isVideoMediaFile($file)) {
            return false;
        }

        return $this->isImageMediaFile($file);
    }

    /** Viewing linked/ghost or globally visible sales shoots is not a curation grant. */
    public function canManageCustomerMediaCuration(Shoot $shoot, ?User $user): bool
    {
        if (! $user || $shoot->isImportDraft()) return false;
        if ($this->isClientUser($user) && (string) $shoot->client_id === (string) $user->id) return true;

        return app(ShootManagementAccess::class)->isRestrictedSalesRep($user) && $shoot->rep_id !== null
            && (string) $shoot->rep_id === (string) $user->id;
    }

    public function isCustomerMediaCurator(?User $user): bool
    {
        return $this->isClientUser($user) || app(ShootManagementAccess::class)->isRestrictedSalesRep($user);
    }

    public function isCustomerCuratableFile(ShootFile $file): bool
    {
        return $this->isClientInteractableEditedFile($file)
            && strtolower((string) $file->media_type) !== 'raw'
            && ! $file->isBlockedFromDelivery() && ! $file->isIguideOfflinePackage();
    }

    public function canManageFileVisibility(Shoot $shoot, ShootFile $file, ?User $user, ?bool $customerCuration = null): bool
    {
        if ((int) $file->shoot_id !== (int) $shoot->id) return false;
        if ($this->hasRole($user, ['photographer', 'editor']) && $this->canInteractWithShootMediaFile($shoot, $file, $user)) return true;
        if ($this->isCustomerMediaCurator($user)) {
            return ($customerCuration ?? $this->canManageCustomerMediaCuration($shoot, $user)) && $this->isCustomerCuratableFile($file);
        }

        return $this->canInteractWithShootMediaFile($shoot, $file, $user);
    }

    public function canSetFileHero(Shoot $shoot, ShootFile $file, ?User $user, ?bool $customerCuration = null): bool
    {
        if ((int) $file->shoot_id !== (int) $shoot->id) return false;
        if ($this->hasRole($user, ['photographer', 'editor']) && $this->canInteractWithShootMediaFile($shoot, $file, $user)) return true;
        if ($this->isCustomerMediaCurator($user)) {
            return ($customerCuration ?? $this->canManageCustomerMediaCuration($shoot, $user)) && $this->isClientHeroEligibleFile($file);
        }

        return $this->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            && $this->canInteractWithShootMediaFile($shoot, $file, $user);
    }

    public function canInteractWithShootMediaFile(Shoot $shoot, ShootFile $file, ?User $user = null): bool
    {
        if ((string) $file->shoot_id !== (string) $shoot->id) {
            return false;
        }
        $user = $user ?? auth()->user();
        if (! $this->canAccessShootMedia($shoot, $user)) {
            return false;
        }

        if ($this->hasRole($user, ['editor'])) {
            return app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot, $file, $user)
                || app(ShootEditorRequestAccess::class)->allowsFile($shoot, $file, $user);
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->canPhotographerAccessFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientManageableEditedFile($file);
        }

        return true;
    }

    /** Read-only reference images for the currently assigned video editor. */
    public function canVideoEditorReadFinalImage(Shoot $shoot, ShootFile $file, ?User $user): bool
    {
        $classifier = app(ShootDownloadAssetClassifier::class);
        return (string) $file->shoot_id === (string) $shoot->id
            && in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)
            && strtolower((string) $file->media_type) !== 'raw'
            && $classifier->isImage($file) && ! $classifier->isVideo($file)
            && ! $this->isRawCameraFile($file) && ! $file->isBlockedFromDelivery()
            && $this->canEditVideoTourLinks($shoot, $user)
            && $this->hasVideoReferenceAssignment($shoot, $user);
    }

    private function hasVideoReferenceAssignment(Shoot $shoot, User $user): bool
    {
        // Older unspecified editors default to both capabilities for existing
        // workflows. That default must not expand photo editors' downloads.
        $explicitVideo = collect($user->metadata['editing_capabilities'] ?? [])
            ->contains(fn ($lane) => in_array(strtolower(trim((string) $lane)), ['video', 'videos'], true));
        return $explicitVideo || $shoot->serviceItems->contains(function ($item) use ($user) {
            $intake = $item->service?->uploadIntakeType();
            return ($intake === Service::INTAKE_PHOTO_VIDEO && (string) $item->video_editor_id === (string) $user->id)
                || ($intake === Service::INTAKE_VIDEO && (string) $item->editor_id === (string) $user->id);
        });
    }

    public function canViewShootMediaFile(Shoot $shoot, ShootFile $file, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        return $this->canVideoEditorReadFinalImage($shoot, $file, $user)
            || $this->canInteractWithShootMediaFile($shoot, $file, $user);
    }

    public function canDownloadShootMediaFile(Shoot $shoot, ShootFile $file, ?User $user = null): bool
    {
        if ((string) $file->shoot_id !== (string) $shoot->id) {
            return false;
        }
        $user = $user ?? auth()->user();
        if (! $this->canDownloadShootMedia($shoot, $user)) {
            return false;
        }
        if ($this->isClientUser($user) && app(DeliveryPlanService::class)->usesComponents($shoot)
            && ! app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file)) {
            return false;
        }
        if ($this->canVideoEditorReadFinalImage($shoot, $file, $user)) {
            return true;
        }
        $contractorDownload = $this->hasRole($user, ['photographer']) ? $this->canPhotographerAccessFile($shoot, $file, $user)
            : ($this->hasRole($user, ['editor']) && ($this->canEditorDownloadRawFile($shoot, $file, $user)
                || app(ShootEditorRequestAccess::class)->allowsFile($shoot, $file, $user)));
        if ($file->is_hidden && $this->isCustomerMediaCurator($user) && ! $contractorDownload) {
            return false;
        }
        if (! $file->is_hidden && $this->canManageCustomerMediaCuration($shoot, $user) && $this->isCustomerCuratableFile($file)) {
            return true;
        }

        // The offline iGUIDE ZIP is an implementation package, not client
        // deliverable media. Non-staff roles may only consume it through the
        // short-lived, package-scoped viewer.
        if ($file->isIguideOfflinePackage()
            && ! $this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return false;
        }

        if ($this->hasRole($user, ['editor'])) {
            return $this->canEditorDownloadRawFile($shoot, $file, $user)
                || app(ShootEditorRequestAccess::class)->allowsFile($shoot, $file, $user);
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->canPhotographerAccessFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientManageableEditedFile($file);
        }

        return true;
    }

    /** Sales visibility across shoots does not grant download rights without assignment. */
    public function canDownloadShootMedia(Shoot $shoot, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $this->canAccessShootMedia($shoot, $user)) {
            return false;
        }

        return ! $this->hasRole($user, ['salesRep'])
            || ($shoot->rep_id !== null && (string) $shoot->rep_id === (string) $user->id);
    }

    public function canEditorDownloadRawFile(Shoot $shoot, ShootFile $file, User $editor): bool
    {
        if (! app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot, $file, $editor)) {
            return false;
        }

        return $file->workflow_stage === ShootFile::STAGE_TODO
            && $file->isRequiredForEditing();
    }

    public function isPhotographerAssignedToShoot(Shoot $shoot, User $photographer): bool
    {
        // Fetch current ownership instead of trusting a stale/filtered relation.
        // A gallery checks this for every file; share the result only within the
        // current HTTP read. Writes and the next request always recheck storage.
        $request = request();
        $readRequest = $request->isMethod('GET') && $request->route() !== null;
        $key = $shoot->id.':'.$photographer->id;
        $assignments = $readRequest ? $request->attributes->get('photographer_shoot_assignments', []) : [];
        if (! array_key_exists($key, $assignments)) {
            $assignments[$key] = app(ShootPhotographerAssignmentService::class)
                ->scopeAssignedTo(Shoot::query()->whereKey($shoot->id), $photographer->id)->exists();
            if ($readRequest) $request->attributes->set('photographer_shoot_assignments', $assignments);
        }

        return $assignments[$key];
    }

    public function canPhotographerAccessServiceItem(Shoot $shoot, ?int $shootServiceId, User $photographer): bool
    {
        if (! $shootServiceId) {
            return $this->isPhotographerAssignedToShoot($shoot, $photographer);
        }

        $serviceItem = $shoot->relationLoaded('serviceItems') ? $shoot->serviceItems->find($shootServiceId) : $shoot->serviceItems()
            ->whereKey($shootServiceId)
            ->first();

        if (! $serviceItem) {
            return false;
        }

        if ($serviceItem->photographer_id) {
            return (string) $serviceItem->photographer_id === (string) $photographer->id;
        }

        return (string) $shoot->photographer_id === (string) $photographer->id;
    }

    public function canPhotographerAccessFile(Shoot $shoot, ShootFile $file, User $photographer): bool
    {
        if (! $file->shoot_service_id) {
            return $this->isPhotographerAssignedToShoot($shoot, $photographer);
        }

        return $this->canPhotographerAccessServiceItem(
            $shoot,
            (int) $file->shoot_service_id,
            $photographer
        );
    }

    protected function normalizeRole(string $role): string
    {
        $normalized = strtolower(str_replace(['-', ' '], ['_', '_'], trim($role)));

        return in_array($normalized, ['salesrep', 'sales_rep', 'rep', 'representative'], true)
            ? 'sales_rep'
            : $normalized;
    }
}
