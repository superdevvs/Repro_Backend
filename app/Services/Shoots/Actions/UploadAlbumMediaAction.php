<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadAlbumMediaAction
{
    public function __construct(
        protected ShootMediaMutationSupportService $support,
        protected ShootAuthorizationSupport $authorizationSupport,
        protected ShootActivityLogger $activityLogger
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:1048576|mimes:jpeg,jpg,png,gif,mp4,mov,avi,raw,cr2,cr3,nef,arw,tiff,bmp,heic,heif,zip',
            'album_id' => 'nullable|exists:shoot_media_albums,id',
            'shoot_service_id' => 'nullable|integer|exists:shoot_service,id',
            'type' => 'required|in:raw,edited,video,iguide,other',
            'photographer_note' => 'nullable|string|max:1000',
        ]);
        $shootServiceId = isset($validated['shoot_service_id']) ? (int) $validated['shoot_service_id'] : null;

        if ($shootServiceId && !$shoot->serviceItems()->whereKey($shootServiceId)->exists()) {
            return [
                'status' => 422,
                'payload' => ['message' => 'Selected service item does not belong to this shoot'],
            ];
        }

        if (
            $user->role === 'photographer'
            && $shootServiceId
            && !$this->authorizationSupport->canPhotographerAccessServiceItem($shoot, $shootServiceId, $user)
        ) {
            return [
                'status' => 403,
                'payload' => ['message' => 'You can only upload media for assigned service items'],
            ];
        }
        if ($user->role === 'photographer' && !$shootServiceId && (string) $shoot->photographer_id !== (string) $user->id) {
            return [
                'status' => 422,
                'payload' => ['message' => 'Select an assigned service item for this upload'],
            ];
        }

        $album = $validated['album_id']
            ? ShootMediaAlbum::findOrFail($validated['album_id'])
            : $this->support->getOrCreateAlbumForType($shoot, $validated['type'], $user, $shootServiceId);

        if ((int) $album->shoot_id !== (int) $shoot->id) {
            return [
                'status' => 422,
                'payload' => ['message' => 'Selected album does not belong to this shoot'],
            ];
        }

        if (
            $user->role === 'photographer'
            && $album->shoot_service_id
            && !$this->authorizationSupport->canPhotographerAccessServiceItem($shoot, (int) $album->shoot_service_id, $user)
        ) {
            return [
                'status' => 403,
                'payload' => ['message' => 'You can only upload media for assigned service items'],
            ];
        }

        if ($shootServiceId && !$album->shoot_service_id) {
            $album->shoot_service_id = $shootServiceId;
            $album->save();
        }

        $uploadedFiles = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            try {
                $tempPath = $file->store('temp/uploads', 'local');

                dispatch(new UploadShootMediaToDropboxJob(
                    $shoot,
                    $album,
                    $tempPath,
                    $file->getClientOriginalName(),
                    $validated['type'],
                    $user->id,
                    $validated['photographer_note'] ?? null,
                    $shootServiceId
                ));

                $uploadedFiles[] = [
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'type' => $validated['type'],
                    'status' => 'queued',
                ];
            } catch (\Exception $e) {
                Log::error('Failed to queue media upload', [
                    'shoot_id' => $shoot->id,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->activityLogger->log(
            $shoot,
            'media_upload_initiated',
            [
                'album_id' => $album->id,
                'shoot_service_id' => $shootServiceId,
                'file_count' => count($uploadedFiles),
                'type' => $validated['type'],
            ],
            $user
        );

        return [
            'status' => 202,
            'payload' => [
                'message' => count($uploadedFiles) . ' file(s) queued for upload',
                'data' => [
                    'uploaded' => $uploadedFiles,
                    'errors' => $errors,
                    'album_id' => $album->id,
                ],
            ],
        ];
    }
}
