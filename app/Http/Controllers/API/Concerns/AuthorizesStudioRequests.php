<?php

namespace App\Http\Controllers\API\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesStudioRequests
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor'];
    protected const STUDIO_PRIVILEGED_ROLES = ['admin', 'superadmin', 'editing_manager'];

    protected function scopeUserId(?Authenticatable $user): ?int
    {
        if ($user === null || in_array($this->userRole($user), self::STUDIO_PRIVILEGED_ROLES, true)) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    protected function scopeTeamId(Authenticatable $user): int
    {
        $directTeamId = $this->userAttribute($user, 'team_id');
        $metadata = $this->userAttribute($user, 'metadata');
        $metadataTeamId = is_array($metadata) ? ($metadata['team_id'] ?? null) : null;
        $teamId = $directTeamId ?? $metadataTeamId ?? $user->getAuthIdentifier();

        return (int) $teamId;
    }

    protected function scopeStudioQuery(
        Builder $query,
        Authenticatable $user,
        string $teamColumn = 'team_id',
        string $ownerColumn = 'created_by'
    ): Builder {
        $query->where($teamColumn, $this->scopeTeamId($user));

        if (($userId = $this->scopeUserId($user)) !== null) {
            $query->where($ownerColumn, $userId);
        }

        return $query;
    }

    protected function authorizeStudioAction(
        ?Authenticatable $user,
        string $action,
        ?Model $record = null,
        array $ownerColumns = ['created_by', 'user_id', 'updated_by']
    ): void {
        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (!in_array($this->userRole($user), self::STUDIO_ROLES, true)) {
            throw new AuthorizationException('This action is not authorized.');
        }

        if ($record === null) {
            return;
        }

        $recordTeamId = $record->getAttribute('team_id');
        if ($recordTeamId !== null && (int) $recordTeamId !== $this->scopeTeamId($user)) {
            throw new AuthorizationException('This action is not authorized.');
        }

        if ($this->scopeUserId($user) !== null && !$this->ownsStudioRecord($user, $record, $ownerColumns)) {
            throw new AuthorizationException('This action is not authorized.');
        }
    }

    protected function ownsStudioRecord(Authenticatable $user, Model $record, array $ownerColumns): bool
    {
        foreach ($ownerColumns as $column) {
            $ownerId = $record->getAttribute($column);
            if ($ownerId !== null) {
                return (int) $ownerId === (int) $user->getAuthIdentifier();
            }
        }

        return false;
    }

    protected function userRole(Authenticatable $user): string
    {
        return (string) ($this->userAttribute($user, 'role') ?? '');
    }

    protected function userAttribute(Authenticatable $user, string $key): mixed
    {
        if ($user instanceof Model) {
            return $user->getAttribute($key);
        }

        return $user->{$key} ?? null;
    }
}
