<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ShootActivityLogger;

class ShootAlbumService
{
    public function __construct(
        protected ShootActivityLogger $activityLogger,
        protected ShootAuthorizationSupport $authorizationSupport
    )
    {
    }

    public function createAlbum(Shoot $shoot, User $user, array $validated): ShootMediaAlbum
    {
        $album = ShootMediaAlbum::create([
            'shoot_id' => $shoot->id,
            'shoot_service_id' => $validated['shoot_service_id'] ?? null,
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

    public function listAlbums(Shoot $shoot, ?User $user = null): array
    {
        $albums = $shoot->mediaAlbums()
            ->with(['photographer', 'files'])
            ->get();

        if ($user && $user->role === 'photographer') {
            $albums = $albums
                ->filter(fn (ShootMediaAlbum $album) => $this->authorizationSupport->canPhotographerAccessServiceItem(
                    $shoot,
                    $album->shoot_service_id ? (int) $album->shoot_service_id : null,
                    $user
                ))
                ->values();
        }

        if ($user && $user->role === 'editor') {
            $albums = $albums
                ->filter(function (ShootMediaAlbum $album) use ($shoot, $user) {
                    if (!$album->shoot_service_id) {
                        return app(ShootEditingAssignmentService::class)->editorHasAssignment($shoot, $user);
                    }

                    $serviceItem = $shoot->serviceItems()->whereKey($album->shoot_service_id)->first();
                    if (!$serviceItem) {
                        return false;
                    }

                    if ($serviceItem->editor_id) {
                        return (string) $serviceItem->editor_id === (string) $user->id;
                    }

                    return (string) $shoot->editor_id === (string) $user->id;
                })
                ->values();
        }

        return $albums
            ->map(function (ShootMediaAlbum $album) {
                return [
                    'id' => $album->id,
                    'shoot_service_id' => $album->shoot_service_id,
                    'shootServiceId' => $album->shoot_service_id,
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
