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
            throw new \RuntimeException('The queued upload source is unavailable.');
        }

        $upload = new UploadedFile(
            $disk->path($this->tempFilePath),
            $this->originalFilename,
            $disk->mimeType($this->tempFilePath) ?: 'application/octet-stream',
            null,
            true
        );
        $serviceId = $this->shootServiceId ?? $this->album->shoot_service_id;
        $method = $this->mediaType === 'edited' ? 'uploadToCompleted' : 'uploadToTodo';
        $file = $media->{$method}($this->shoot, $upload, $this->uploadedBy, null, $this->mediaType, $serviceId);
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
