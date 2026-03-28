<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ShootActivityLogger;

class ShootAlbumService
{
    public function __construct(protected ShootActivityLogger $activityLogger)
    {
    }

    public function createAlbum(Shoot $shoot, User $user, array $validated): ShootMediaAlbum
    {
        $album = ShootMediaAlbum::create([
            'shoot_id' => $shoot->id,
            'photographer_id' => $validated['photographer_id'] ?? ($user->role === 'photographer' ? $user->id : null),
            'source' => $validated['source'],
            'folder_path' => $validated['folder_path'] ?? null,
            'is_watermarked' => false,
        ]);

        $this->activityLogger->log(
            $shoot,
            'album_created',
            [
                'album_id' => $album->id,
                'source' => $album->source,
            ],
            $user
        );

        return $album->load('photographer');
    }

    public function listAlbums(Shoot $shoot): array
    {
        return $shoot->mediaAlbums()
            ->with(['photographer', 'files'])
            ->get()
            ->map(function (ShootMediaAlbum $album) {
                return [
                    'id' => $album->id,
                    'source' => $album->source,
                    'folder_path' => $album->folder_path,
                    'cover_image_path' => $album->cover_image_path,
                    'is_watermarked' => $album->is_watermarked,
                    'photographer' => $album->photographer ? [
                        'id' => $album->photographer->id,
                        'name' => $album->photographer->name,
                    ] : null,
                    'file_count' => $album->files->count(),
                    'created_at' => $album->created_at->toIso8601String(),
                ];
            })
            ->all();
    }
}
