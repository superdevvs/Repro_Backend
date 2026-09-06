<?php

namespace App\Services\Shoots;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ShootAuthorizationSupport
{
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
        $normalizedStatus = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));

        return in_array($normalizedStatus, self::CLIENT_DELIVERED_STATUSES, true);
    }

    /** Query counterpart of canAccessShootMedia; per-file and release checks still apply. */
    public function scopeAccessibleShootMedia(Builder $query, ?User $user): Builder
    {
        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return $query;
        }
        if ($this->hasRole($user, ['editor'])) {
            return app(ShootEditingAssignmentService::class)->scopeAssignedToEditor($query, $user->id);
        }
        if ($this->hasRole($user, ['salesRep', 'rep', 'representative'])) {
            return $query->where('rep_id', $user->id);
        }
        if ($this->hasRole($user, ['photographer'])) {
            return $query->where(fn (Builder $scope) => $scope->where('photographer_id', $user->id)
                ->orWhereHas('services', fn (Builder $service) => $service->where('shoot_service.photographer_id', $user->id)));
        }
        if ($this->isClientUser($user)) {
            $clientIds = [$user->id, ...AccountLink::getLinkedClientIdsForOwner((int) $user->id, 'shoots')];

            return $query->where(fn (Builder $scope) => $scope->whereIn('client_id', $clientIds)
                ->orWhere(fn (Builder $ghost) => $ghost->whereHas('ghostUsers', fn (Builder $recipient) => $recipient->where('users.id', $user->id))
                    // Match the policy's workflow-status precedence, including the empty fallback.
                    ->whereIn(\Illuminate\Support\Facades\DB::raw("LOWER(COALESCE(NULLIF(workflow_status, ''), status, ''))"), self::CLIENT_DELIVERED_STATUSES)));
        }

        return $query->whereRaw('1 = 0');
    }

    public function canAccessShootMedia(Shoot $shoot, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($this->hasRole($user, ['salesRep', 'rep', 'representative'])) {
            return (string) $shoot->rep_id === (string) $user->id;
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->isPhotographerAssignedToShoot($shoot, $user);
        }

        if ($this->hasRole($user, ['editor'])) {
            return app(ShootEditingAssignmentService::class)->editorHasAssignment($shoot, $user);
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
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        $normalizedUploadType = strtolower(trim($uploadType));
        if (! in_array($normalizedUploadType, ['raw', 'edited'], true)) {
            return false;
        }

        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($this->hasRole($user, ['photographer'])) {
            if ($normalizedUploadType !== 'raw') {
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
            return $assignmentService->editorHasAssignment($shoot, $user);
        }

        $serviceItem = $shoot->serviceItems()
            ->with('service')
            ->whereKey($shootServiceId)
            ->first();

        if (! $serviceItem || ! ($serviceItem->service?->requiresEditing() ?? true)) {
            return false;
        }

        // Legacy top-level editors retain access to all editing-required items.
        if ((string) $shoot->editor_id === (string) $user->id) {
            return true;
        }

        return (string) $serviceItem->editor_id === (string) $user->id;
    }

    public function canViewShootDetails(Shoot $shoot, ?User $user = null): bool
    {
        return $this->canAccessShootMedia($shoot, $user);
    }

    public function ensureShootAccess(Shoot $shoot, ?User $user = null): void
    {
        abort_unless($this->canViewShootDetails($shoot, $user), 403, 'Forbidden');
    }

    public function canManageShootOperations(?User $user): bool
    {
        return $this->hasRole($user, ['admin', 'superadmin', 'editing_manager']);
    }

    /** Viewing a linked/shared delivery never grants the recipient workflow writes. */
    public function canSubmitShootRequest(Shoot $shoot, ?User $user): bool
    {
        if (! $user || ! $this->canViewShootDetails($shoot, $user)) {
            return false;
        }

        if ($this->isClientUser($user)) {
            return (string) $shoot->client_id === (string) $user->id;
        }

        return $this->hasRole($user, [
            'admin', 'superadmin', 'editing_manager', 'salesRep', 'photographer', 'editor',
        ]);
    }

    public function canResolveShootIssues(Shoot $shoot, ?User $user): bool
    {
        return $this->canViewShootDetails($shoot, $user)
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
                || (string) $item->editor_id === (string) $user->id;
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
        if (! $this->isClientManageableEditedFile($file)) {
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
            return app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot, $file, $user);
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->canPhotographerAccessFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientManageableEditedFile($file);
        }

        return true;
    }

    public function canDownloadShootMediaFile(Shoot $shoot, ShootFile $file, ?User $user = null): bool
    {
        if ((string) $file->shoot_id !== (string) $shoot->id) {
            return false;
        }
        $user = $user ?? auth()->user();
        if (! $this->canAccessShootMedia($shoot, $user)) {
            return false;
        }

        // The offline iGUIDE ZIP is an implementation package, not client
        // deliverable media. Non-staff roles may only consume it through the
        // short-lived, package-scoped viewer.
        if ($file->isIguideOfflinePackage()
            && ! $this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return false;
        }

        if ($this->hasRole($user, ['editor'])) {
            return $this->canEditorDownloadRawFile($shoot, $file, $user);
        }

        if ($this->hasRole($user, ['photographer'])) {
            return $this->canPhotographerAccessFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientManageableEditedFile($file);
        }

        return true;
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
        if ((string) $shoot->photographer_id === (string) $photographer->id) {
            return true;
        }

        if ($shoot->relationLoaded('services')) {
            return collect($shoot->getRelation('services'))->contains(function ($service) use ($photographer) {
                return (string) ($service->pivot?->photographer_id ?? '') === (string) $photographer->id;
            });
        }

        return $shoot->services()
            ->wherePivot('photographer_id', $photographer->id)
            ->exists();
    }

    public function canPhotographerAccessServiceItem(Shoot $shoot, ?int $shootServiceId, User $photographer): bool
    {
        if (! $shootServiceId) {
            return $this->isPhotographerAssignedToShoot($shoot, $photographer);
        }

        $serviceItem = $shoot->serviceItems()
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
