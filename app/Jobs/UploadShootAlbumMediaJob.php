<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\ShootActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Album intake uses the same local quarantine and processing pipeline as direct uploads. */
class UploadShootAlbumMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 3;

    public function __construct(
        public Shoot $shoot,
        public ShootMediaAlbum $album,
        public string $tempFilePath,
        public string $originalFilename,
        public string $mediaType, // raw, edited, video, iguide
        public ?int $uploadedBy = null,
        public ?string $photographerNote = null,
        public ?int $shootServiceId = null
    ) {
        $this->uploadedBy = $uploadedBy ?? auth()->id();
    }

    public function handle(ShootMediaStorageService $media, ShootActivityLogger $activityLogger): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->tempFilePath)) {
            $attempt = \App\Models\ShootUploadAttempt::where('shoot_id', $this->shoot->id)->where('actor_id', $this->uploadedBy)
                ->where('idempotency_key', 'album-'.hash('sha256', $this->tempFilePath))->where('status', 'completed')->first();
            if ($attempt && \App\Models\ShootFile::where('shoot_id', $this->shoot->id)->where('album_id', $this->album->id)
                ->whereIn('id', $attempt->result_file_ids ?? [])->exists()) return;
            throw new \RuntimeException('The queued upload source is unavailable.');
        }

        $upload = new UploadedFile(
            $disk->path($this->tempFilePath),
            $this->originalFilename,
            $disk->mimeType($this->tempFilePath) ?: 'application/octet-stream',
            null,
            true
        );
        $shoot = $this->shoot->fresh();
        $album = $this->album->fresh();
        $uploader = $this->uploadedBy ? User::find($this->uploadedBy) : null;
        abort_unless($shoot && $album && $uploader && (int) $album->shoot_id === (int) $shoot->id, 403, 'Upload ownership is no longer available.');
        $serviceId = $this->shootServiceId ?? $album->shoot_service_id;
        abort_if($album->shoot_service_id && (int) $album->shoot_service_id !== (int) $serviceId,
            409, 'Album service ownership changed while this upload was queued.');
        // Reuse the same authorization, content validation, cancellation checks,
        // quarantine and idempotency boundary as multipart/chunk/cloud intake.
        $request = \Illuminate\Http\Request::create('/api/shoots/'.$shoot->id.'/upload', 'POST', [
            'upload_type' => $this->mediaType === 'edited' ? 'edited' : 'raw',
            'media_type' => $this->mediaType, 'shoot_service_id' => $serviceId,
            'idempotency_key' => 'album-'.hash('sha256', $this->tempFilePath),
        ], [], ['files' => [$upload]]);
        $request->setUserResolver(fn () => $uploader);
        $result = app(\App\Services\Shoots\Actions\UploadShootFilesAction::class)->execute($request, $shoot, $uploader);
        $fileId = data_get($result, 'payload.uploaded_files.0.id');
        if (! $fileId) throw new \RuntimeException('Album upload was not accepted: '.data_get($result, 'payload.message', 'Review the target service and permissions.'));
        $file = $shoot->files()->findOrFail($fileId);
        $file->forceFill(['album_id' => $this->album->id, 'uploaded_at' => now()])->save();

        $this->album->source = 'local';
        if (! $this->album->cover_image_path) {
            $this->album->cover_image_path = $file->path;
        }
        $this->album->save();

        $uploader = $this->uploadedBy ? User::find($this->uploadedBy) : null;
        $activityLogger->log($this->shoot, 'media_uploaded', [
            'file_id' => $file->id,
            'file_ids' => [$file->id],
            'filename' => $this->originalFilename,
            'type' => $this->mediaType,
            'file_count' => 1,
            'album_id' => $this->album->id,
            'shoot_service_id' => $serviceId,
            'uploaded_by_role' => $uploader?->role,
            'uploaded_by_name' => $uploader?->name,
        ], $uploader);

        if ($this->photographerNote) {
            $this->shoot->notes()->create([
                'author_id' => $this->uploadedBy,
                'type' => 'photographer',
                'visibility' => 'photographer_only',
                'content' => $this->photographerNote,
            ]);
        }

        // Keep the source on failure so the queue can retry it.
        $disk->delete($this->tempFilePath);
    }
}
