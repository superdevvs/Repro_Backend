<?php

namespace App\Services\Shoots;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\ShootNote;
use App\Models\User;
use Illuminate\Support\Collection;

class ShootNotesAccessService
{
    public function __construct(
        private readonly ShootAuthorizationSupport $shootAuthorization,
        private readonly ShootEditingAssignmentService $editingAssignments
    ) {}

    public function canRead(Shoot $shoot, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $role = $this->role($user);
        if (in_array($role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return true;
        }

        if ($role === 'client') {
            return $this->isOwningClient($shoot, $user) || $this->isLinkedShootClient($shoot, $user);
        }

        if ($role === 'photographer') {
            return $this->shootAuthorization->isPhotographerAssignedToShoot($shoot, $user);
        }

        if ($role === 'editor') {
            return $this->editingAssignments->editorHasAssignment($shoot, $user);
        }

        return false;
    }

    public function canCreate(Shoot $shoot, User $user, string $type, string $visibility): bool
    {
        $role = $this->role($user);

        if (in_array($role, ['admin', 'superadmin'], true)) {
            return true;
        }

        if ($role === 'client') {
            return $this->isOwningClient($shoot, $user)
                && $type === ShootNote::TYPE_SHOOT
                && $visibility === ShootNote::VISIBILITY_CLIENT_VISIBLE;
        }

        if ($role === 'photographer' && $this->shootAuthorization->isPhotographerAssignedToShoot($shoot, $user)) {
            return ($type === ShootNote::TYPE_PHOTOGRAPHER && $visibility === ShootNote::VISIBILITY_PHOTOGRAPHER_ONLY)
                || ($type === ShootNote::TYPE_SHOOT && $visibility === ShootNote::VISIBILITY_CLIENT_VISIBLE);
        }

        return false;
    }

    public function canUpdateScalar(Shoot $shoot, User $user, string $field): bool
    {
        $role = $this->role($user);
        if (in_array($role, ['admin', 'superadmin'], true)) {
            return true;
        }

        if ($role === 'client') {
            return $field === 'shoot_notes' && $this->isOwningClient($shoot, $user);
        }

        return $role === 'photographer'
            && $field === 'photographer_notes'
            && $this->shootAuthorization->isPhotographerAssignedToShoot($shoot, $user);
    }

    /** @param Collection<int, ShootNote> $notes */
    public function visibleNotes(Collection $notes, User $user): Collection
    {
        return match ($this->role($user)) {
            'admin', 'superadmin', 'editing_manager' => $notes,
            'client' => $notes->filter(fn (ShootNote $note) =>
                $note->type === ShootNote::TYPE_SHOOT
                && $note->visibility === ShootNote::VISIBILITY_CLIENT_VISIBLE),
            'photographer' => $notes->filter(fn (ShootNote $note) =>
                $note->type === ShootNote::TYPE_PHOTOGRAPHER
                || ($note->type === ShootNote::TYPE_SHOOT
                    && $note->visibility === ShootNote::VISIBILITY_CLIENT_VISIBLE)),
            'editor' => $notes->filter(fn (ShootNote $note) => $note->type === ShootNote::TYPE_EDITING),
            default => collect(),
        };
    }

    public function isOwningClient(Shoot $shoot, User $user): bool
    {
        return $this->role($user) === 'client'
            && (string) $shoot->client_id === (string) $user->id;
    }

    private function isLinkedShootClient(Shoot $shoot, User $user): bool
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

    private function role(User $user): string
    {
        return strtolower(str_replace(['-', ' '], '_', (string) $user->role));
    }
}
