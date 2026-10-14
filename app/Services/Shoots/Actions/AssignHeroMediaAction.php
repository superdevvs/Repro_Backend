<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Support\Facades\DB;

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
        [$freshFile, $heroImageKey, $changed] = \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $file, $user) {
            // SQLite needs the writer before checking the current file. A
            // concurrent hide/safety change must win over a stale hero request.
            DB::table('shoots')->where('id', $shoot->id)->update(['hero_image' => DB::raw('hero_image')]);
            $freshShoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            $freshFile = $freshShoot->files()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            $access = app(ShootAuthorizationSupport::class);
            abort_unless($access->canSetFileHero($freshShoot, $freshFile, $user), $access->isCustomerMediaCurator($user) ? 422 : 403, 'Choose a visible, safe edited photo for the hero image.');
            $existingCoverId = $freshShoot->files()->where('is_cover', true)->value('id');
            $heroImageKey = $freshFile->web_path ?: $freshFile->thumbnail_path ?: $freshFile->path;
            $changed = (string) $existingCoverId !== (string) $freshFile->id || $freshShoot->hero_image !== $heroImageKey;
            $freshShoot->files()->where('is_cover', true)->update(['is_cover' => false]);
            $freshFile->is_cover = true;
            $freshFile->save();
            $freshShoot->hero_image = $heroImageKey;
            $freshShoot->save();

            return [$freshFile->fresh(), $heroImageKey, $changed];
        }), 'shoot-media.hero');
        $shoot->refresh();
        $heroImageUrl = $this->fileAccess->resolvePublicStorageUrl($heroImageKey);

        $this->support->clearShootFilesCache($shoot, $user);

        if ($changed) {
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
