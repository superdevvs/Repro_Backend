<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Support\Str;

class ShootAuthorizationSupport
{
    public function hasRole(?User $user, array $roles): bool
    {
        if (!$user) {
            return false;
        }

        $normalizedRole = $this->normalizeRole($user->role ?? '');
        $normalizedAllowed = array_map(fn (string $role) => $this->normalizeRole($role), $roles);

        return in_array($normalizedRole, $normalizedAllowed, true);
    }

    public function ensureRole(array $roles, ?User $user = null, string $message = 'Forbidden'): void
    {
        $user = $user ?? auth()->user();
        if (!$this->hasRole($user, $roles)) {
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
        if (!$this->isClientUser($user)) {
            return;
        }

        if (!$this->canClientAccessShoot($shoot, $user)) {
            abort(403, 'Forbidden');
        }
    }

    public function canClientAccessShoot(Shoot $shoot, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (!$this->isClientUser($user)) {
            return false;
        }

        if ((string) $shoot->client_id === (string) $user->id) {
            return true;
        }

        if (!$this->isShootDeliveredForClientAccess($shoot)) {
            return false;
        }

        if ($shoot->relationLoaded('ghostUsers')) {
            return $shoot->ghostUsers->contains(fn ($ghostUser) => (string) data_get($ghostUser, 'id') === (string) $user->id);
        }

        return $shoot->ghostUsers()
            ->where('users.id', $user->id)
            ->exists();
    }

    public function isShootDeliveredForClientAccess(Shoot $shoot): bool
    {
        $normalizedStatus = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));

        return in_array($normalizedStatus, [
            Shoot::STATUS_DELIVERED,
            'ready_for_client',
            'admin_verified',
            'ready',
            'workflow_completed',
            'client_delivered',
        ], true);
    }

    public function canAccessShootMedia(Shoot $shoot, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (!$user) {
            return false;
        }

        if ($this->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($this->hasRole($user, ['salesRep', 'rep', 'representative'])) {
            return (string) $shoot->rep_id === (string) $user->id;
        }

        if ($this->hasRole($user, ['photographer'])) {
            return (string) $shoot->photographer_id === (string) $user->id;
        }

        if ($this->hasRole($user, ['editor'])) {
            return app(ShootEditingAssignmentService::class)->editorHasAssignment($shoot, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->canClientAccessShoot($shoot, $user);
        }

        return false;
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
        if (!in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)) {
            return false;
        }

        return !$this->isRawCameraFile($file);
    }

    public function isClientHeroEligibleFile(ShootFile $file): bool
    {
        if (!$this->isClientManageableEditedFile($file)) {
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
        $user = $user ?? auth()->user();
        if (!$this->canAccessShootMedia($shoot, $user)) {
            return false;
        }

        if ($this->hasRole($user, ['editor'])) {
            return app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientInteractableEditedFile($file);
        }

        return true;
    }

    public function canDownloadShootMediaFile(Shoot $shoot, ShootFile $file, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (!$this->canAccessShootMedia($shoot, $user)) {
            return false;
        }

        if ($this->hasRole($user, ['editor'])) {
            return $this->canEditorDownloadRawFile($shoot, $file, $user);
        }

        if ($this->isClientUser($user)) {
            return $this->isClientInteractableEditedFile($file);
        }

        return true;
    }

    public function canEditorDownloadRawFile(Shoot $shoot, ShootFile $file, User $editor): bool
    {
        if (!app(ShootEditingAssignmentService::class)->canEditorAccessFile($shoot, $file, $editor)) {
            return false;
        }

        return $file->workflow_stage === ShootFile::STAGE_TODO;
    }

    protected function normalizeRole(string $role): string
    {
        return strtolower(str_replace(['-', ' '], ['_', '_'], trim($role)));
    }
}
