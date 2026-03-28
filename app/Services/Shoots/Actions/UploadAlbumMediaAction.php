<?php

namespace App\Services\Shoots\Actions;

use App\Jobs\UploadShootMediaToDropboxJob;
use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadAlbumMediaAction
{
    public function __construct(
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger
    ) {
    }

    public function execute(Request $request, Shoot $shoot, User $user): array
    {
        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:1048576|mimes:jpeg,jpg,png,gif,mp4,mov,avi,raw,cr2,cr3,nef,arw,tiff,bmp,heic,heif,zip',
            'album_id' => 'nullable|exists:shoot_media_albums,id',
            'type' => 'required|in:raw,edited,video,iguide,other',
            'photographer_note' => 'nullable|string|max:1000',
        ]);

        $album = $validated['album_id']
            ? ShootMediaAlbum::findOrFail($validated['album_id'])
            : $this->support->getOrCreateAlbumForType($shoot, $validated['type'], $user);

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
                    $validated['photographer_note'] ?? null
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
