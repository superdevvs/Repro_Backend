<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Shoots\ShootMediaMutationSupportService;

class AssignHeroMediaAction
{
    public function __construct(
        protected ShootFileAccessService $fileAccess,
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger
    ) {
    }

    public function execute(Shoot $shoot, ShootFile $file, User $user): array
    {
        $existingCoverId = $shoot->files()->where('is_cover', true)->value('id');
        $previousHeroImage = $shoot->hero_image;

        $shoot->files()->where('is_cover', true)->update(['is_cover' => false]);
        $file->is_cover = true;
        $file->save();

        $freshFile = $file->fresh();
        $heroImageKey = $freshFile->web_path
            ?: $freshFile->thumbnail_path
            ?: $freshFile->path;
        $shoot->hero_image = $heroImageKey;
        $shoot->save();
        $heroImageUrl = $this->fileAccess->resolvePublicStorageUrl($heroImageKey);

        $this->support->clearShootFilesCache($shoot, $user);

        if ((string) $existingCoverId !== (string) $freshFile->id || $previousHeroImage !== $heroImageKey) {
            $this->activityLogger->log(
                $shoot,
                'hero_image_updated',
                [
                    'by' => $user->name,
                    'actor_role' => $user->role,
                    'file_id' => $freshFile->id,
                    'filename' => $freshFile->filename,
                ],
                $user
            );
        }

        return [
            'message' => 'Cover updated',
            'file' => $freshFile,
            'hero_image' => $heroImageUrl,
        ];
    }
}
