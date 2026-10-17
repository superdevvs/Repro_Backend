<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootAdditionalFile, User};

class AdditionalFileAccess
{
    public function __construct(private ShootAuthorizationSupport $authorization) {}

    public function canRead(Shoot $shoot, ShootAdditionalFile $file, ?User $actor): bool
    {
        if ((int) $file->shoot_id !== (int) $shoot->id || ! $actor) return false;
        if ($file->shoot_service_id === null && data_get($file->metadata, 'scope_required')
            && ! $this->authorization->hasRole($actor, ['admin', 'superadmin', 'editing_manager'])) return false;
        if (! $this->authorization->canUploadShootMedia($shoot, $actor, 'additional', $file->shoot_service_id)) return false;
        return $file->shoot_service_id !== null
            || $this->authorization->hasRole($actor, ['admin', 'superadmin', 'editing_manager'])
            || (int) $file->uploaded_by === (int) $actor->id;
    }

    public function present(ShootAdditionalFile $file): array
    {
        return ['id' => $file->id, 'filename' => $file->filename, 'file_size' => $file->file_size,
            'mime_type' => $file->mime_type, 'shoot_service_id' => $file->shoot_service_id,
            'created_at' => $file->created_at?->toIso8601String(),
            'is_archive' => (bool) data_get($file->metadata, 'is_archive', false),
            'note' => data_get($file->metadata, 'note')];
    }
}
