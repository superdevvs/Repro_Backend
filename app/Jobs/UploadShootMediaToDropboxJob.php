<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\ShootMediaAlbum;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
use App\Jobs\GenerateWatermarkedImageJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadShootMediaToDropboxJob implements ShouldQueue
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
        public ?string $photographerNote = null
    ) {
        $this->uploadedBy = $uploadedBy ?? auth()->id();
    }

    public function handle(DropboxWorkflowService $dropboxService, ShootActivityLogger $activityLogger): void
    {
        try {
            Log::info('UploadShootMediaToDropboxJob: Starting upload', [
                'shoot_id' => $this->shoot->id,
                'album_id' => $this->album->id,
                'filename' => $this->originalFilename,
                'type' => $this->mediaType,
            ]);

            // Determine Dropbox folder path based on album and type
            $folderPath = $this->determineDropboxPath();

            // Upload to Dropbox
            $dropboxPath = $dropboxService->uploadFile(
                $this->tempFilePath,
                $folderPath . '/' . $this->originalFilename,
                $this->shoot
            );

            // Create shoot_file record
            $shootFile = ShootFile::create([
                'shoot_id' => $this->shoot->id,
                'album_id' => $this->album->id,
                'filename' => $this->originalFilename,
                'stored_filename' => basename($this->tempFilePath),
                'path' => $this->tempFilePath, // Temporary path
                'storage_path' => $dropboxPath,
                'dropbox_path' => $dropboxPath,
                'file_type' => $this->getFileTypeFromExtension($this->originalFilename),
                'mime_type' => Storage::mimeType($this->tempFilePath),
                'file_size' => Storage::size($this->tempFilePath),
                'media_type' => $this->mediaType,
                'uploaded_by' => $this->uploadedBy,
                'uploaded_at' => now(),
                'workflow_stage' => ShootFile::STAGE_TODO,
            ]);

            // Update album cover if this is the first file
            if (!$this->album->cover_image_path) {
                $this->album->cover_image_path = $dropboxPath;
                $this->album->save();
            }

            // Log activity
            $activityLogger->log(
                $this->shoot,
                'media_uploaded',
                [
                    'file_id' => $shootFile->id,
                    'filename' => $this->originalFilename,
                    'type' => $this->mediaType,
                    'album_id' => $this->album->id,
                ],
                $this->uploadedBy ? \App\Models\User::find($this->uploadedBy) : null
            );

            // Create photographer note if provided
            if ($this->photographerNote) {
                $this->shoot->notes()->create([
                    'author_id' => $this->uploadedBy,
                    'type' => 'photographer',
                    'visibility' => 'photographer_only',
                    'content' => $this->photographerNote,
                ]);
            }

            // Clean up temporary file
            if (Storage::exists($this->tempFilePath)) {
                Storage::delete($this->tempFilePath);
            }

            // Dispatch watermarking job if needed (for raw photos)
            if ($this->mediaType === 'raw' && !$this->shoot->bypass_paywall && $this->shoot->payment_status !== 'paid') {
                GenerateWatermarkedImageJob::dispatch($shootFile);
            }

            Log::info('UploadShootMediaToDropboxJob: Upload completed', [
                'shoot_id' => $this->shoot->id,
                'file_id' => $shootFile->id,
            ]);
        } catch (\Exception $e) {
            Log::error('UploadShootMediaToDropboxJob: Upload failed', [
                'shoot_id' => $this->shoot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up temp file on failure
            if (Storage::exists($this->tempFilePath)) {
                Storage::delete($this->tempFilePath);
            }

            throw $e;
        }
    }

    protected function determineDropboxPath(): string
    {
        // Use album folder_path if set, otherwise construct from shoot structure
        if ($this->album->folder_path) {
            return $this->album->folder_path;
        }

        // Construct path: /shoots/{shoot_id}/{type}/{photographer_id}/
        $photographerId = $this->album->photographer_id ?? $this->uploadedBy;
        $typeFolder = match($this->mediaType) {
            'raw' => 'raw',
            'edited' => 'edited',
            'video' => 'video',
            'iguide' => 'iguide',
            default => 'other',
        };

        return "/shoots/{$this->shoot->id}/{$typeFolder}/{$photographerId}/";
    }

    protected function getFileTypeFromExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'heic', 'heif', 'raw', 'cr2', 'cr3', 'nef', 'arw'];
        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'wmv'];
        
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }
        if ($extension === 'zip') {
            return 'archive';
        }
        
        return 'other';
    }
}

