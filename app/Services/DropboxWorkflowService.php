<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\DropboxFolder;
use App\Models\OauthToken;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Services\Messaging\AutomationService;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DropboxWorkflowService
{
    protected $tokenService;
    protected $rawThumbnailService;
    protected $dropboxApiUrl = 'https://api.dropboxapi.com/2';
    protected $dropboxContentUrl = 'https://content.dropboxapi.com/2';
    protected $httpOptions;

    public function __construct(DropboxTokenService $tokenService = null, RawThumbnailService $rawThumbnailService = null)
    {
        $this->tokenService = $tokenService ?: new DropboxTokenService();
        $this->rawThumbnailService = $rawThumbnailService ?: new RawThumbnailService();
        
        // Configure HTTP options for development environment
        $this->httpOptions = [
            'verify' => config('app.env') === 'production' ? true : false,
            'timeout' => 60,
        ];
    }

    /**
     * Extract image metadata including dimensions and EXIF data
     */
    protected function extractImageMetadata(UploadedFile $file): array
    {
        $metadata = [];
        $tempPath = $file->getRealPath();
        
        // Try to get image dimensions
        try {
            $imageInfo = @getimagesize($tempPath);
            if ($imageInfo !== false) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['mime'] = $imageInfo['mime'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug('Could not get image size', ['error' => $e->getMessage()]);
        }
        
        // Try to get EXIF data for capture date
        try {
            if (function_exists('exif_read_data') && in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $exif = @exif_read_data($tempPath);
                if ($exif !== false) {
                    // Try different EXIF date fields
                    $dateFields = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];
                    foreach ($dateFields as $field) {
                        if (!empty($exif[$field])) {
                            $metadata['captured_at'] = $exif[$field];
                            break;
                        }
                    }
                    // Store camera info if available
                    if (!empty($exif['Make'])) {
                        $metadata['camera_make'] = $exif['Make'];
                    }
                    if (!empty($exif['Model'])) {
                        $metadata['camera_model'] = $exif['Model'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not read EXIF data', ['error' => $e->getMessage()]);
        }

        $filename = $file->getClientOriginalName();
        if (
            $this->rawThumbnailService->isRawFile($filename)
            || empty($metadata['captured_at'])
            || empty($metadata['width'])
            || empty($metadata['height'])
        ) {
            $exifToolMetadata = $this->extractMetadataWithExifTool($tempPath);
            foreach ($exifToolMetadata as $key => $value) {
                if (!array_key_exists($key, $metadata) || empty($metadata[$key])) {
                    $metadata[$key] = $value;
                }
            }
        }
        
        return $metadata;
    }

    protected function extractMetadataWithExifTool(?string $path): array
    {
        if (!$path || !file_exists($path) || !$this->commandExists('exiftool')) {
            return [];
        }

        $cmd = sprintf(
            'exiftool -j -DateTimeOriginal -CreateDate -ModifyDate -ImageWidth -ImageHeight -Make -Model %s',
            escapeshellarg($path)
        );
        exec($cmd, $output, $code);

        if ($code !== 0 || empty($output)) {
            return [];
        }

        $rows = json_decode(implode("\n", $output), true);
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return [];
        }

        $row = $rows[0];
        $metadata = [];
        $capturedAt = $row['DateTimeOriginal'] ?? $row['CreateDate'] ?? $row['ModifyDate'] ?? null;
        if (is_string($capturedAt) && $capturedAt !== '') {
            $metadata['captured_at'] = $capturedAt;
        }

        if (isset($row['ImageWidth']) && is_numeric($row['ImageWidth'])) {
            $metadata['width'] = (int) $row['ImageWidth'];
        }

        if (isset($row['ImageHeight']) && is_numeric($row['ImageHeight'])) {
            $metadata['height'] = (int) $row['ImageHeight'];
        }

        if (!empty($row['Make']) && is_string($row['Make'])) {
            $metadata['camera_make'] = $row['Make'];
        }

        if (!empty($row['Model']) && is_string($row['Model'])) {
            $metadata['camera_model'] = $row['Model'];
        }

        return $metadata;
    }

    protected function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        exec(sprintf('%s %s', $check, escapeshellarg($command)), $output, $code);

        return $code === 0;
    }

    /**
     * Determine if a file should be processed into thumbnails/web sizes
     */
    protected function shouldProcessFilename(string $filename, ?string $mimeType = null): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp'];

        if (in_array($extension, $imageExtensions, true)) {
            return true;
        }

        if ($this->rawThumbnailService->isRawFile($filename)) {
            return true;
        }

        if ($mimeType && str_starts_with(strtolower($mimeType), 'image/')) {
            return true;
        }

        return false;
    }

    protected function shouldProcessImage(UploadedFile $file): bool
    {
        return $this->shouldProcessFilename($file->getClientOriginalName(), $file->getMimeType());
    }

    /**
     * Determine media type for uploads based on filename/mime and context
     */
    private function resolveMediaType(string $filename, ?string $mimeType, string $fallback, ?string $serviceCategory = null): string
    {
        if ($fallback === 'extra') {
            return 'extra';
        }

        $category = strtolower((string) $serviceCategory);
        if ($category === 'iguide') {
            return 'iguide';
        }
        if ($category === 'video') {
            return 'video';
        }

        // Detect floorplan from filename patterns
        $lowerFilename = strtolower($filename);
        $floorplanPatterns = ['floorplan', 'floor-plan', 'floor_plan', 'fp_', 'fp-', 'layout', 'blueprint'];
        foreach ($floorplanPatterns as $pattern) {
            if (str_contains($lowerFilename, $pattern)) {
                return 'floorplan';
            }
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'wmv'];

        if (in_array($extension, $videoExtensions, true)) {
            return 'video';
        }

        if ($mimeType && str_starts_with(strtolower($mimeType), 'video/')) {
            return 'video';
        }

        return $fallback;
    }

    /**
     * Get a valid access token
     */
    protected function getAccessToken()
    {
        try {
            return $this->tokenService->getValidAccessToken();
        } catch (\Exception $e) {
            Log::error('Failed to get valid Dropbox access token', ['error' => $e->getMessage()]);
            throw new \Exception('Dropbox authentication failed. Please check your token configuration.');
        }
    }

    /**
     * Create folder structure for a shoot using new Photo Editing organization
     */
    public function createShootFolders(Shoot $shoot)
    {
        // Generate property slug if not already set
        if (!$shoot->property_slug) {
            $shoot->property_slug = $shoot->generatePropertySlug();
            $shoot->save();
        }

        $propertySlug = $shoot->property_slug;
        $basePath = "/Photo Editing";
        
        // Create base Photo Editing folder
        $this->createFolderIfNotExists($basePath);
        
        // Create To-Do and Completed base folders
        $todoBasePath = "{$basePath}/To-Do";
        $completedBasePath = "{$basePath}/Completed";
        $archivedBasePath = "{$basePath}/Archived Shoots";
        
        $this->createFolderIfNotExists($todoBasePath);
        $this->createFolderIfNotExists($completedBasePath);
        $this->createFolderIfNotExists($archivedBasePath);
        
        // Create property folder structure: /To-Do/{propertySlug}/raw and /extra
        $todoPropertyPath = "{$todoBasePath}/{$propertySlug}";
        $rawPath = "{$todoPropertyPath}/raw";
        $extraPath = "{$todoPropertyPath}/extra";
        
        $this->createFolderIfNotExists($todoPropertyPath);
        $this->createFolderIfNotExists($rawPath);
        $this->createFolderIfNotExists($extraPath);
        
        // Create Completed folder: /Completed/{propertySlug}-edited
        $completedPath = "{$completedBasePath}/{$propertySlug}-edited";
        $this->createFolderIfNotExists($completedPath);

        // Update shoot with folder paths
        $shoot->dropbox_raw_folder = $rawPath;
        $shoot->dropbox_extra_folder = $extraPath;
        $shoot->dropbox_edited_folder = $completedPath;
        $shoot->save();

        // Create DropboxFolder records for compatibility
        DropboxFolder::updateOrCreate(
            ['shoot_id' => $shoot->id, 'folder_type' => DropboxFolder::TYPE_TODO],
            ['dropbox_path' => $rawPath, 'dropbox_folder_id' => null]
        );

        DropboxFolder::updateOrCreate(
            ['shoot_id' => $shoot->id, 'folder_type' => DropboxFolder::TYPE_COMPLETED],
            ['dropbox_path' => $completedPath, 'dropbox_folder_id' => null]
        );

        Log::info("Created Dropbox folders for shoot", [
            'shoot_id' => $shoot->id,
            'property_slug' => $propertySlug,
            'raw_folder' => $rawPath,
            'extra_folder' => $extraPath,
            'edited_folder' => $completedPath,
        ]);
    }

    /**
     * Upload file to ToDo folder
     */
    public function uploadToTodo(Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null, ?string $mediaTypeOverride = null)
    {
        $mediaType = $mediaTypeOverride
            ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), 'raw', $serviceCategory);

        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO, $mediaType);
    }

    /**
     * Synchronously stream an upload to clamd before it is persisted (QA #14).
     *
     * Returns:
     *   - 'clean'        when clamd reports the file clean.
     *   - 'unavailable'  when scanning is disabled, clamd is unreachable, or the
     *                    scan errored — the caller then falls back to the async
     *                    quarantine+scan path (the file is withheld until clean).
     *
     * @throws \Illuminate\Validation\ValidationException (HTTP 422) when the file
     *         is found to be infected — the upload is rejected and never stored.
     */
    private function scanUploadSynchronously(UploadedFile $file): string
    {
        if (!config('clamav.scan_on_upload', true)) {
            return 'unavailable';
        }

        // Tests run without a clamd daemon; the async dispatch path (and the
        // dedicated ClamAv integration test) covers scanning there.
        if (app()->runningUnitTests()) {
            return 'unavailable';
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return 'unavailable';
        }

        try {
            $result = app(ClamAvClient::class)->scan($path);
        } catch (ClamAvUnavailable $e) {
            Log::warning('Synchronous upload scan skipped — ClamAV unavailable.', [
                'shoot_file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return 'unavailable';
        } catch (\Throwable $e) {
            Log::warning('Synchronous upload scan errored — falling back to async scan.', [
                'shoot_file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return 'unavailable';
        }

        if (!$result->isClean()) {
            Log::warning('Infected upload rejected at pre-store scan.', [
                'shoot_file' => $file->getClientOriginalName(),
                'signature' => $result->signature(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'This file failed our malware scan and was rejected.',
            ]);
        }

        return 'clean';
    }

    /**
     * Store file on local public storage as a fallback when Dropbox fails
     */
    private function storeLocally(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        string $stage,
        ?string $mediaTypeOverride = null
    ): ShootFile
    {
        $storageMediaType = in_array($mediaTypeOverride, ['extra', 'floorplan', 'virtual_staging', 'green_grass', 'twilight', 'drone'], true)
            ? $mediaTypeOverride
            : null;
        $prefix = match ($storageMediaType) {
            'extra' => 'EXTRA_',
            'floorplan' => 'FLOORPLAN_',
            'virtual_staging' => 'VIRTUAL_STAGING_',
            'green_grass' => 'GREEN_GRASS_',
            'twilight' => 'TWILIGHT_',
            'drone' => 'DRONE_',
            default => $stage === ShootFile::STAGE_COMPLETED ? 'COMPLETED_' : 'TODO_',
        };
        $filename = $prefix . str_replace('.', '_', uniqid('', true)) . '_' . $file->getClientOriginalName();
        $dir = match ($storageMediaType) {
            'extra' => "shoots/{$shoot->id}/extra",
            'floorplan' => "shoots/{$shoot->id}/floorplan",
            'virtual_staging' => "shoots/{$shoot->id}/virtual_staging",
            'green_grass' => "shoots/{$shoot->id}/green_grass",
            'twilight' => "shoots/{$shoot->id}/twilight",
            'drone' => "shoots/{$shoot->id}/drone",
            default => "shoots/{$shoot->id}/" . ($stage === ShootFile::STAGE_COMPLETED ? 'completed' : 'todo'),
        };
        $serverPath = $dir . '/' . $filename;
        $defaultMediaType = $storageMediaType
            ?? ($stage === ShootFile::STAGE_COMPLETED ? 'edited' : 'raw');
        $mediaType = $mediaTypeOverride ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), $defaultMediaType);

        $existingFile = ShootFile::where('shoot_id', $shoot->id)
            ->where('filename', $file->getClientOriginalName())
            ->where('workflow_stage', $stage)
            ->first();
        $isReplacement = $existingFile !== null;

        if ($existingFile) {
            $this->deleteLocalStoredAssets($existingFile, preserveDerivedAssets: true);
            Log::info('Replacing duplicate file in place', [
                'shoot_id' => $shoot->id,
                'file_id' => $existingFile->id,
                'filename' => $file->getClientOriginalName(),
                'stage' => $stage,
                'old_stored' => $existingFile->stored_filename,
            ]);
        }

        // Extract image metadata (dimensions, EXIF)
        $metadata = $this->extractImageMetadata($file);

        // Heavy image processing during upload can exhaust PHP memory for large files.
        // Store first, then process after the response/through the queue.
        $thumbnailPath = $existingFile?->thumbnail_path;
        $webPath = $existingFile?->web_path;
        $placeholderPath = $existingFile?->placeholder_path;
        $processedAt = $existingFile?->processed_at;

        // Synchronous pre-store malware scan (QA #14). Stream the upload to clamd
        // BEFORE persisting it: an infected verdict rejects the upload with HTTP 422
        // so malware never lands in storage nor returns a 200. When clamd is
        // unavailable this returns 'unavailable' and we fall back to the async
        // quarantine+scan path below (the file stays withheld from delivery until a
        // clean verdict is recorded by ScanShootFileJob).
        $syncScanVerdict = $this->scanUploadSynchronously($file);

        // Now store the file (this may move the temp file)
        Storage::disk('public')->putFileAs($dir, $file, $filename);

        $shootFile = $existingFile ?: new ShootFile([
            'shoot_id' => $shoot->id,
            'filename' => $file->getClientOriginalName(),
            'workflow_stage' => $stage,
        ]);

        $attributes = [
            'filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'path' => $serverPath,
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'media_type' => $mediaType,
            'uploaded_by' => $userId,
            'workflow_stage' => $stage,
            'dropbox_path' => null,
            'dropbox_file_id' => null,
            'thumbnail_path' => $thumbnailPath,
            'web_path' => $webPath,
            'placeholder_path' => $placeholderPath,
            'processed_at' => $processedAt,
            'processing_failed_at' => null,
            'processing_error' => null,
            'metadata' => !empty($metadata) ? $metadata : null,
        ];

        if (Schema::hasColumn('shoot_files', 'is_extra')) {
            $attributes['is_extra'] = $mediaType === 'extra' || $storageMediaType === 'extra';
        }
        if (Schema::hasColumn('shoot_files', 'required_for_editing')) {
            $attributes['required_for_editing'] = false;
        }
        // Record the synchronous scan verdict so a clean upload is immediately
        // servable; otherwise it stays quarantined until the async scan clears it.
        if (Schema::hasColumn('shoot_files', 'scan_status')) {
            if ($syncScanVerdict === 'clean') {
                $attributes['scan_status'] = ShootFile::SCAN_STATUS_CLEAN;
                $attributes['scan_result'] = 'clean (pre-store scan)';
                $attributes['scanned_at'] = now();
            } else {
                $attributes['scan_status'] = ShootFile::SCAN_STATUS_QUARANTINED;
            }
        }

        $shootFile->fill($attributes);
        $shootFile->save();

        $requiresImageProcessing = $this->shouldProcessImage($file)
            && (
                $isReplacement
                || !$shootFile->processed_at
                || !$shootFile->thumbnail_path
                || !$shootFile->web_path
                || !$shootFile->placeholder_path
            );

        if ($requiresImageProcessing && app()->runningUnitTests()) {
            // Inline image processing for tests so derived asset paths are
            // populated immediately. Real environments rely on the queued
            // ProcessImageJob dispatched by FileScanService::release once the
            // scan verdict is clean (task 13.5/13.6).
            $generatedPaths = app(ImageProcessingService::class)->processImageFromPath(
                $shoot->id,
                $shootFile->filename,
                Storage::disk('public')->path($serverPath)
            );

            if (!empty($generatedPaths)) {
                $shootFile->update([
                    'thumbnail_path' => $generatedPaths['thumbnail'] ?? $shootFile->thumbnail_path,
                    'web_path' => $generatedPaths['web'] ?? $shootFile->web_path,
                    'placeholder_path' => $generatedPaths['placeholder'] ?? $shootFile->placeholder_path,
                    'processed_at' => now(),
                ]);
                $shootFile->refresh();
            }
        }

        // Quarantine + scan (Req 14.1/14.2): the ShootFile is created with
        // scan_status='quarantined' (migration default) and the Scan_Job is
        // enqueued instead of dispatching ProcessImageJob directly.
        // ProcessImageJob is dispatched by FileScanService::release once a
        // clean verdict is recorded by ScanShootFileJob (task 13.5).
        // Downstream gating inside ProcessImageJob/UploadShootMediaToDropboxJob
        // lands in task 13.6.
        //
        // Dispatch is unconditional — every newly uploaded ShootFile gets a
        // Scan_Job, including non-image files (videos, archives, etc.) that
        // would not have triggered ProcessImageJob anyway. This guarantees the
        // wiring contract for every upload entry that calls into storeLocally
        // (FileUploadController::uploadFromPC and UploadShootFilesAction).
        //
        // When the synchronous pre-store scan already cleared the file, release it
        // straight to downstream processing; otherwise enqueue the async scan which
        // releases (or flags) the file once clamd becomes reachable.
        if ($syncScanVerdict === 'clean' && !app()->runningUnitTests()) {
            try {
                app(FileScanService::class)->release($shootFile);
            } catch (\Throwable $e) {
                Log::warning('Post-scan release failed after local upload', [
                    'shoot_id' => $shoot->id,
                    'file_id' => $shootFile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                ScanShootFileJob::dispatch($shootFile->id)->afterResponse();
            } catch (\Throwable $e) {
                Log::warning('Scan job dispatch failed after local upload', [
                    'shoot_id' => $shoot->id,
                    'file_id' => $shootFile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->isEnabled()) {
            try {
                SyncShootFileToDropboxJob::dispatch($shootFile->id);
            } catch (\Throwable $e) {
                Log::warning('Dropbox sync dispatch failed after local upload', [
                    'shoot_id' => $shoot->id,
                    'file_id' => $shootFile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Note: Workflow status transitions are intentionally NOT performed here.
        // All status changes (scheduled -> uploaded, uploaded/editing -> ready) are
        // owned by the explicit FinalizeRawUploadAction / FinalizeEditedUploadAction
        // which are triggered by the user pressing "Submit Raw" / "Submit Edits".
        // Uploading files only places content; submission is a distinct user action.

        Log::info('Stored file locally as Dropbox fallback', [
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'path' => $serverPath,
            'stage' => $stage,
        ]);

        return $shootFile;
    }

    protected function deleteLocalStoredAssets(ShootFile $shootFile, bool $preserveDerivedAssets = false): void
    {
        $attributes = $preserveDerivedAssets
            ? ['path']
            : ['path', 'thumbnail_path', 'web_path', 'placeholder_path'];

        foreach ($attributes as $attribute) {
            $storedPath = $shootFile->{$attribute};
            if ($storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
        }
    }

    /**
     * Move file from ToDo to Completed folder
     */
    public function moveToCompleted(ShootFile $shootFile, $userId)
    {
        $shoot = $shootFile->shoot;
        
        // Locate the Completed folder for this shoot
        $completedFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_COMPLETED)
            ->first();
        
        if (!$completedFolder) {
            // Fallback: mark as completed without Dropbox move, and keep current path
            Log::warning('Completed Dropbox folder not found, marking file as completed locally', [
                'shoot_id' => $shoot->id,
                'file_id' => $shootFile->id,
            ]);

            $shootFile->moveToCompleted($userId);

            // Files moved to completed - status stays as 'uploaded' until admin sends to editing
            return true;
        }

        $newPath = $completedFolder->dropbox_path . '/' . $shootFile->stored_filename;

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/move_v2', [
                    'from_path' => $shootFile->dropbox_path,
                    'to_path' => $newPath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]);

            if ($response->successful()) {
                // Update file record
                $shootFile->dropbox_path = $newPath;
                $shootFile->moveToCompleted($userId);

                // Files moved to completed - status stays as 'uploaded' until admin sends to editing

                Log::info("File moved to Completed folder", [
                    'shoot_id' => $shoot->id,
                    'filename' => $shootFile->filename,
                    'new_path' => $newPath
                ]);

                return true;
            } else {
                Log::error("Failed to move file in Dropbox", $response->json() ?: []);
                throw new \Exception('Failed to move file in Dropbox');
            }
        } catch (\Exception $e) {
            Log::error("Exception moving file in Dropbox", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Copy verified files to server storage (keep in Dropbox).
     *
     * Hardened behavior:
     *  - Local-only files: prefer Storage::copy() (rename within disk — no PHP
     *    memory copy). On any I/O / permission failure, log a warning, leave the
     *    file path on its original `completed/...` location, and still mark the
     *    file STAGE_VERIFIED so the read path keeps working.
     *  - Dropbox-backed files: stream the download to disk via Http::sink so
     *    large videos/raws don't blow up PHP memory.
     *  - This method is now non-throwing for benign local-copy failures so a
     *    single bad file or a permission glitch never blocks finalize.
     */
    public function moveToFinal(ShootFile $shootFile, $userId)
    {
        $shoot = $shootFile->shoot;

        try {
            if (!empty($shootFile->dropbox_path)) {
                $this->downloadAndStoreOnServer($shootFile, $shootFile->dropbox_path);
            } else {
                $this->copyLocalCompletedToFinal($shootFile);
            }

            // Update file record - keep dropbox_path but mark as verified
            $shootFile->workflow_stage = ShootFile::STAGE_VERIFIED;
            $shootFile->save();

            Log::info('File marked verified (final cache attempted)', [
                'shoot_id' => $shoot?->id,
                'shoot_file_id' => $shootFile->id,
                'filename' => $shootFile->filename,
                'dropbox_path' => $shootFile->dropbox_path,
                'server_path' => $shootFile->path,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Last-resort fallback: even if Dropbox download or local copy failed,
            // mark the file as verified so finalize can complete. The file remains
            // accessible via its existing path / dropbox_path through the read
            // services. Caller can re-cache later via CacheShootFinalToLocalJob.
            Log::warning('moveToFinal degraded: marking verified without final cache', [
                'shoot_id' => $shoot?->id,
                'shoot_file_id' => $shootFile->id,
                'filename' => $shootFile->filename,
                'error' => $e->getMessage(),
            ]);

            try {
                $shootFile->workflow_stage = ShootFile::STAGE_VERIFIED;
                $shootFile->save();
            } catch (\Throwable $persistEx) {
                Log::error('Failed to persist STAGE_VERIFIED in moveToFinal fallback', [
                    'shoot_file_id' => $shootFile->id,
                    'error' => $persistEx->getMessage(),
                ]);
                throw $persistEx;
            }

            return false;
        }
    }

    /**
     * Best-effort local-disk copy of a completed file into the per-shoot
     * `final/` directory. Does not throw on permission/mkdir failures — the
     * read path can still serve the file from its original location.
     */
    protected function copyLocalCompletedToFinal(ShootFile $shootFile): void
    {
        $shoot = $shootFile->shoot;
        if (!$shoot) {
            return;
        }

        $currentPath = $shootFile->path;
        if (!$currentPath) {
            return;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($currentPath)) {
            // Source missing locally; nothing to copy. Read path will resolve
            // via existing path / dropbox_path / preview pipelines.
            Log::info('Skipping local-final copy: source missing on disk', [
                'shoot_file_id' => $shootFile->id,
                'path' => $currentPath,
            ]);
            return;
        }

        $serverPath = "shoots/{$shoot->id}/final/{$shootFile->stored_filename}";

        // If already at final/, nothing to do.
        if ($currentPath === $serverPath) {
            return;
        }

        try {
            // Storage::copy is implemented as a streamed copy in flysystem and
            // auto-creates parent directories. Avoids reading whole file into
            // PHP memory.
            $disk->copy($currentPath, $serverPath);
            $shootFile->path = $serverPath;
        } catch (\Throwable $e) {
            // Permission / disk / mkdir errors must NOT block finalize. Keep
            // original path; the file still serves correctly from completed/.
            Log::warning('Local final copy skipped due to I/O error', [
                'shoot_id' => $shoot->id,
                'shoot_file_id' => $shootFile->id,
                'from' => $currentPath,
                'to' => $serverPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download file from Dropbox and store on server using a streamed sink
     * (no whole-file PHP memory buffer). Honors Retry-After on 429 once.
     */
    protected function downloadAndStoreOnServer(ShootFile $shootFile, $dropboxPath)
    {
        $apiArgs = json_encode(['path' => $dropboxPath]);
        $serverPath = "shoots/{$shootFile->shoot_id}/final/{$shootFile->stored_filename}";

        $tmp = tempnam(sys_get_temp_dir(), 'dbx-final-');
        if ($tmp === false) {
            throw new \Exception('Could not allocate temp file for Dropbox download');
        }

        $attempt = 0;
        $maxAttempts = 2;

        try {
            while (true) {
                $attempt++;
                $response = Http::withToken($this->getAccessToken())
                    ->withOptions(array_merge($this->httpOptions, ['timeout' => 300, 'sink' => $tmp]))
                    ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                    ->get($this->dropboxContentUrl . '/files/download');

                if ($response->successful()) {
                    break;
                }

                $shouldRetry = $attempt < $maxAttempts && in_array($response->status(), [429, 500, 502, 503, 504], true);
                if (!$shouldRetry) {
                    $bodyPreview = is_file($tmp) ? @file_get_contents($tmp, false, null, 0, 500) : '';
                    Log::error('Failed to download file from Dropbox', [
                        'shoot_file_id' => $shootFile->id,
                        'status' => $response->status(),
                        'body_preview' => $bodyPreview,
                    ]);
                    throw new \Exception('Failed to download file from Dropbox (HTTP ' . $response->status() . ')');
                }

                $retryAfter = (int) $response->header('Retry-After');
                $sleepSeconds = $retryAfter > 0 ? min($retryAfter, 30) : (int) pow(2, $attempt);
                sleep($sleepSeconds);
            }

            // Move tmp file into the public disk (uses streaming under the hood
            // and auto-creates the destination directory).
            $stream = fopen($tmp, 'rb');
            if ($stream === false) {
                throw new \Exception('Failed to open downloaded temp file for streaming');
            }
            try {
                Storage::disk('public')->put($serverPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $shootFile->path = $serverPath;
            $shootFile->save();

            Log::info('File downloaded and stored on server', [
                'shoot_file_id' => $shootFile->id,
                'dropbox_path' => $dropboxPath,
                'server_path' => $serverPath,
                'attempts' => $attempt,
            ]);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function getTemporaryLink(?string $dropboxPath): ?string
    {
        if (!$dropboxPath) {
            return null;
        }

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/get_temporary_link', [
                    'path' => $dropboxPath,
                ]);

            if ($response->successful()) {
                return $response->json()['link'] ?? null;
            }

            Log::warning('Failed to create Dropbox temporary link', [
                'path' => $dropboxPath,
                'error' => $response->json(),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception creating Dropbox temporary link', [
                'path' => $dropboxPath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Download a file from Dropbox to a temporary local path
     * Used for watermark generation and other processing
     * 
     * @param string|null $dropboxPath The Dropbox path to download
     * @return string|null Local file path or null on failure
     */
    public function downloadToTemp(?string $dropboxPath): ?string
    {
        if (!$dropboxPath) {
            return null;
        }

        try {
            $apiArgs = json_encode(['path' => $dropboxPath]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                ->get($this->dropboxContentUrl . '/files/download');

            if ($response->successful()) {
                // Create temp directory if it doesn't exist
                $tempDir = storage_path('app/temp/dropbox_downloads');
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                // Generate unique filename preserving extension
                $originalFilename = basename($dropboxPath);
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'dropbox_' . time() . '_' . uniqid() . '.' . $extension;
                $localPath = $tempDir . '/' . $filename;

                // Write file content
                file_put_contents($localPath, $response->body());

                Log::info('File downloaded from Dropbox to temp', [
                    'dropbox_path' => $dropboxPath,
                    'local_path' => $localPath,
                    'size' => filesize($localPath),
                ]);

                return $localPath;
            }

            Log::error('Failed to download file from Dropbox', [
                'path' => $dropboxPath,
                'status' => $response->status(),
                'error' => $response->json() ?: $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Exception downloading file from Dropbox to temp', [
                'path' => $dropboxPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload a local file to a specific Dropbox path
     * Used for uploading watermarked images
     * 
     * @param string $localPath Local file path
     * @param string $dropboxPath Target Dropbox path
     * @return string|null The Dropbox path on success, null on failure
     */
    public function uploadFromPath(string $localPath, string $dropboxPath): ?string
    {
        if (!file_exists($localPath)) {
            Log::error('Cannot upload file - local path does not exist', ['path' => $localPath]);
            return null;
        }

        try {
            $fileContent = file_get_contents($localPath);
            
            $apiArgs = json_encode([
                'path' => $dropboxPath,
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => true,
            ]);

            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders([
                    'Dropbox-API-Arg' => $apiArgs,
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($fileContent, 'application/octet-stream')
                ->post($this->dropboxContentUrl . '/files/upload');

            if ($response->successful()) {
                $result = $response->json();
                Log::info('File uploaded to Dropbox', [
                    'local_path' => $localPath,
                    'dropbox_path' => $result['path_display'] ?? $dropboxPath,
                    'size' => $result['size'] ?? filesize($localPath),
                ]);
                return $result['path_display'] ?? $dropboxPath;
            }

            Log::error('Failed to upload file to Dropbox', [
                'local_path' => $localPath,
                'dropbox_path' => $dropboxPath,
                'status' => $response->status(),
                'error' => $response->json() ?: $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Exception uploading file to Dropbox', [
                'local_path' => $localPath,
                'dropbox_path' => $dropboxPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * List files in a specific folder
     */
    public function listFolderFiles($folderPath)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/list_folder', [
                    'path' => $folderPath,
                    'recursive' => false,
                    'include_media_info' => true,
                ]);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error("Failed to list Dropbox folder files", $response->json() ?: []);
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exception listing Dropbox folder files", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate address-based folder name
     */
    private function generateAddressFolderName(Shoot $shoot)
    {
        // Clean and format address for folder name
        $address = $shoot->address;
        $city = $shoot->city;
        $state = $shoot->state;
        
        // Remove special characters and replace spaces with hyphens
        $cleanAddress = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $address);
        $cleanCity = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $city);
        $cleanState = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $state);
        
        // Replace spaces with hyphens and remove multiple hyphens
        $addressPart = preg_replace('/\s+/', '-', trim($cleanAddress));
        $cityPart = preg_replace('/\s+/', '-', trim($cleanCity));
        $statePart = preg_replace('/\s+/', '-', trim($cleanState));
        
        // Combine parts
        $folderName = "{$addressPart}-{$cityPart}-{$statePart}";
        
        // Clean up multiple hyphens and ensure it's not too long
        $folderName = preg_replace('/-+/', '-', $folderName);
        $folderName = substr($folderName, 0, 100); // Limit length
        
        return trim($folderName, '-');
    }

    /**
     * Get service categories based on the shoot's service
     */
    private function getServiceCategories(Shoot $shoot)
    {
        // If service_category is set, use it
        if ($shoot->service_category) {
            return [$shoot->service_category];
        }
        
        // Otherwise, determine from service name
        $serviceName = strtolower($shoot->service->name ?? '');
        
        if (strpos($serviceName, 'iguide') !== false) {
            return ['iGuide'];
        } elseif (strpos($serviceName, 'video') !== false) {
            return ['Video'];
        } else {
            // Default to Photos, but you might want to create all three
            return ['P']; // or return ['P', 'iGuide', 'Video'] to create all
        }
    }

    /**
     * Get category prefix for folder naming
     */
    private function getCategoryPrefix($category)
    {
        switch ($category) {
            case 'P':
                return 'P';
            case 'iGuide':
                return 'iGuide';
            case 'Video':
                return 'Video';
            default:
                return 'P';
        }
    }

    /**
     * Create folder if it doesn't exist
     */
    private function createFolderIfNotExists($path)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode(['path' => $path, 'autorename' => false]))
                ->post($this->dropboxApiUrl . '/files/create_folder_v2');

            if ($response->successful()) {
                Log::info("Created Dropbox folder: {$path}");
                return true;
            } else {
                $error = $response->json();
                // Check if folder already exists
                if (isset($error['error']['.tag']) && $error['error']['.tag'] === 'path' && 
                    isset($error['error']['path']['.tag']) && $error['error']['path']['.tag'] === 'conflict') {
                    Log::info("Dropbox folder already exists: {$path}");
                    return true;
                } else {
                    Log::error("Failed to create Dropbox folder: {$path}", $error ?: []);
                    return false;
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception creating Dropbox folder: {$path}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get service category from file path
     */
    private function getServiceCategoryFromPath($path, $shoot)
    {
        // Extract category from path like "/RealEstatePhotos/ToDo/2025-01-18/P-123-Main-Street-Anytown-ST/file.jpg"
        if (strpos($path, '/P-') !== false) {
            return 'P';
        } elseif (strpos($path, '/iGuide-') !== false) {
            return 'iGuide';
        } elseif (strpos($path, '/Video-') !== false) {
            return 'Video';
        }
        
        // Fallback to shoot's service category or default
        return $shoot->service_category ?? 'P';
    }

    /**
     * Upload file directly to Completed folder (for edited files)
     */
    public function uploadToCompleted(Shoot $shoot, UploadedFile $file, $userId, $serviceCategory = null, ?string $mediaTypeOverride = null)
    {
        $mediaType = $mediaTypeOverride
            ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), 'edited', $serviceCategory);

        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_COMPLETED, $mediaType);
    }

    /**
     * Copy file from user's Dropbox to ToDo folder
     */
    public function copyFromDropboxToTodo(Shoot $shoot, $sourcePath, $filename, $userId, $serviceCategory = null)
    {
        // Find (or create) the ToDo folder for this shoot
        $todoFolder = $shoot->dropboxFolders()
            ->where('folder_type', DropboxFolder::TYPE_TODO)
            ->first();
        
        if (!$todoFolder) {
            $this->createShootFolders($shoot);
            $todoFolder = $shoot->dropboxFolders()
                ->where('folder_type', DropboxFolder::TYPE_TODO)
                ->first();
        }

        if (!$todoFolder) {
            throw new \Exception("ToDo folder not found for category: {$serviceCategory}");
        }

        $newFilename = 'COPIED_TODO_' . str_replace('.', '_', uniqid('', true)) . '_' . $filename;
        $destinationPath = $todoFolder->dropbox_path . '/' . $newFilename;

        try {
            // Copy file within Dropbox
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode([
                    'from_path' => $sourcePath,
                    'to_path' => $destinationPath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]))
                ->post($this->dropboxApiUrl . '/files/copy_v2');

            if ($response->successful()) {
                $fileData = $response->json();
                
                // Get file metadata to determine size and type
                $metadataResponse = Http::withToken($this->getAccessToken())
                    ->withOptions($this->httpOptions)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->withBody(json_encode(['path' => $destinationPath]))
                    ->post($this->dropboxApiUrl . '/files/get_metadata');

                $fileSize = 0;
                $mimeType = 'application/octet-stream';
                
                if ($metadataResponse->successful()) {
                    $metadata = $metadataResponse->json();
                    $fileSize = $metadata['size'] ?? 0;
                    $mimeType = $this->getMimeTypeFromExtension($filename);
                }

                $mediaType = $this->resolveMediaType($filename, $mimeType, 'raw', $serviceCategory);
                
                // Store file record in database
                $shootFile = ShootFile::create([
                    'shoot_id' => $shoot->id,
                    'filename' => $filename,
                    'stored_filename' => $newFilename,
                    'path' => $destinationPath,
                    'file_type' => $mimeType,
                    'file_size' => $fileSize,
                    'media_type' => $mediaType,
                    'uploaded_by' => $userId,
                    'workflow_stage' => ShootFile::STAGE_TODO,
                    'dropbox_path' => $destinationPath,
                    'dropbox_file_id' => $fileData['id'] ?? null
                ]);

                // Quarantine + scan (Req 14.1/14.2): every newly created
                // ShootFile — including non-image files (videos, archives) —
                // enters quarantine and is sent to ScanShootFileJob.
                // ProcessImageJob is dispatched only by FileScanService::release
                // after ScanShootFileJob records a clean verdict (task 13.5).
                ScanShootFileJob::dispatch($shootFile->id)->afterResponse();

                // Workflow status transition is owned by FinalizeRawUploadAction;
                // copying a file into ToDo no longer auto-advances shoot status.

                Log::info("File copied from Dropbox to ToDo folder", [
                    'shoot_id' => $shoot->id,
                    'source_path' => $sourcePath,
                    'destination_path' => $destinationPath,
                    'filename' => $filename
                ]);

                return $shootFile;
            } else {
                Log::error("Failed to copy file in Dropbox", $response->json() ?: []);
                throw new \Exception('Failed to copy file in Dropbox');
            }
        } catch (\Exception $e) {
            Log::error("Exception copying file in Dropbox", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get MIME type from file extension
     */
    private function getMimeTypeFromExtension($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'raw' => 'image/x-canon-raw',
            'cr2' => 'image/x-canon-cr2',
            'cr3' => 'image/x-canon-cr3',
            'nef' => 'image/x-nikon-nef',
            'arw' => 'image/x-sony-arw',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Delete file from Dropbox
     */
    private function deleteFromDropbox($path)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode(['path' => $path]))
                ->post($this->dropboxApiUrl . '/files/delete_v2');

            if ($response->successful()) {
                Log::info("Deleted file from Dropbox: {$path}");
                return true;
            } else {
                Log::error("Failed to delete file from Dropbox: {$path}", $response->json() ?: []);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception deleting file from Dropbox: {$path}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Upload file to Extra folder
     */
    public function uploadToExtra(Shoot $shoot, UploadedFile $file, $userId)
    {
        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO, 'extra');
    }

    /**
     * Archive shoot by copying completed folder to Archived Shoots
     */
    public function archiveShoot(Shoot $shoot, $userId = null)
    {
        if (!$shoot->dropbox_edited_folder) {
            Log::warning('No edited folder to archive', ['shoot_id' => $shoot->id]);
            return false;
        }

        // Generate client slug
        $client = $shoot->client;
        $clientSlug = $client ? strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $client->name)) : 'unknown-client';
        $clientSlug = preg_replace('/-+/', '-', trim($clientSlug, '-'));

        // Create archive path: /Photo Editing/Archived Shoots/{clientSlug}/{propertySlug}-{shootId}
        $basePath = "/Photo Editing/Archived Shoots";
        $clientPath = "{$basePath}/{$clientSlug}";
        $archivePath = "{$clientPath}/{$shoot->property_slug}-{$shoot->id}";

        try {
            // Create client folder if not exists
            $this->createFolderIfNotExists($basePath);
            $this->createFolderIfNotExists($clientPath);

            // Copy the entire completed folder to archive
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/copy_v2', [
                    'from_path' => $shoot->dropbox_edited_folder,
                    'to_path' => $archivePath,
                    'allow_shared_folder' => false,
                    'autorename' => true
                ]);

            if ($response->successful()) {
                // Update shoot with archive folder path
                $shoot->dropbox_archive_folder = $archivePath;
                $shoot->save();

                Log::info("Shoot archived successfully", [
                    'shoot_id' => $shoot->id,
                    'from_path' => $shoot->dropbox_edited_folder,
                    'to_path' => $archivePath
                ]);

                return true;
            } else {
                Log::error("Failed to archive shoot in Dropbox", $response->json() ?: []);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception archiving shoot", ['error' => $e->getMessage(), 'shoot_id' => $shoot->id]);
            return false;
        }
    }

    /**
     * List shoot files by type (raw, edited, extra, archive)
     */
    public function listShootFiles(Shoot $shoot, string $type)
    {
        $folderPath = $shoot->getDropboxFolderForType($type);
        
        if (!$folderPath) {
            Log::warning("No Dropbox folder found for type: {$type}", ['shoot_id' => $shoot->id]);
            return [];
        }

        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/files/list_folder', [
                    'path' => $folderPath,
                    'recursive' => false,
                    'include_media_info' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $entries = $data['entries'] ?? [];

                // Transform entries into our format
                return collect($entries)
                    ->filter(function ($entry) {
                        return $entry['.tag'] === 'file';
                    })
                    ->map(function ($entry) use ($shoot) {
                        return [
                            'id' => $entry['id'] ?? null,
                            'name' => $entry['name'] ?? '',
                            'path' => $entry['path_display'] ?? '',
                            'size' => $entry['size'] ?? 0,
                            'modified' => $entry['client_modified'] ?? $entry['server_modified'] ?? null,
                            'mime_type' => $this->getMimeTypeFromExtension($entry['name'] ?? ''),
                            'thumbnail_link' => null, // Will be fetched on demand
                        ];
                    })
                    ->values()
                    ->toArray();
            } else {
                Log::error("Failed to list Dropbox folder files", $response->json() ?: []);
                return [];
            }
        } catch (\Exception $e) {
            Log::error("Exception listing Dropbox folder files", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get Dropbox shared link for ZIP download
     */
    public function getDropboxZipLink(string $folderPath)
    {
        try {
            // Try to create a shared link for the folder
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/sharing/create_shared_link_with_settings', [
                    'path' => $folderPath,
                    'settings' => [
                        'requested_visibility' => 'public',
                        'audience' => 'public',
                        'access' => 'viewer'
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $url = $data['url'] ?? null;
                
                // Convert to direct download link by replacing dl=0 with dl=1
                if ($url) {
                    $url = str_replace('dl=0', 'dl=1', $url);
                    Log::info("Created Dropbox shared link", ['path' => $folderPath, 'url' => $url]);
                    return $url;
                }
            } else {
                $error = $response->json();
                // If link already exists, try to get it
                if (isset($error['error']['.tag']) && $error['error']['.tag'] === 'shared_link_already_exists') {
                    return $this->getExistingSharedLink($folderPath);
                }
                Log::warning("Failed to create Dropbox shared link", $error ?: []);
            }
        } catch (\Exception $e) {
            Log::error("Exception creating Dropbox shared link", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get existing shared link for a folder
     */
    private function getExistingSharedLink(string $folderPath)
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->post($this->dropboxApiUrl . '/sharing/list_shared_links', [
                    'path' => $folderPath,
                    'direct_only' => true
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $links = $data['links'] ?? [];
                
                if (count($links) > 0) {
                    $url = $links[0]['url'] ?? null;
                    if ($url) {
                        return str_replace('dl=0', 'dl=1', $url);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception getting existing shared link", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Create a shared link for a folder (wrapper for editor share functionality)
     */
    public function createSharedLink(string $folderPath, int $expiresInHours = 72)
    {
        // Use the existing getDropboxZipLink method which creates shared links
        return $this->getDropboxZipLink($folderPath);
    }

    /**
     * Download a file from Dropbox and return its contents
     */
    public function downloadFile(string $dropboxPath): ?string
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders([
                    'Dropbox-API-Arg' => json_encode(['path' => $dropboxPath])
                ])
                ->post('https://content.dropboxapi.com/2/files/download', '');

            if ($response->successful()) {
                return $response->body();
            }
            
            Log::warning('Failed to download file from Dropbox', [
                'path' => $dropboxPath,
                'status' => $response->status()
            ]);
        } catch (\Exception $e) {
            Log::error('Exception downloading file from Dropbox', [
                'path' => $dropboxPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Generate ZIP file on-the-fly from Dropbox files (fallback)
     */
    public function generateZipOnFly(Shoot $shoot, string $type)
    {
        $files = $this->listShootFiles($shoot, $type);
        
        if (empty($files)) {
            throw new \Exception("No files found for type: {$type}");
        }

        // Create a temporary ZIP file
        $zipPath = storage_path("app/temp/shoot-{$shoot->id}-{$type}-" . time() . ".zip");
        
        // Ensure temp directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to create ZIP file");
        }

        foreach ($files as $file) {
            try {
                // Download file from Dropbox
                $apiArgs = json_encode(['path' => $file['path']]);
                $response = Http::withToken($this->getAccessToken())
                    ->withOptions($this->httpOptions)
                    ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                    ->get($this->dropboxContentUrl . '/files/download');

                if ($response->successful()) {
                    $zip->addFromString($file['name'], $response->body());
                }
            } catch (\Exception $e) {
                Log::error("Failed to download file for ZIP", [
                    'file' => $file['path'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $zip->close();

        Log::info("Generated ZIP file on-the-fly", [
            'shoot_id' => $shoot->id,
            'type' => $type,
            'file_count' => count($files),
            'zip_path' => $zipPath
        ]);

        return $zipPath;
    }

    /**
     * Test Dropbox connection
     */
    public function testConnection(): array
    {
        try {
            // Check if Dropbox is enabled
            if (!config('services.dropbox.enabled', false)) {
                return [
                    'success' => false,
                    'message' => 'Dropbox integration is disabled. Enable it in settings to test.',
                ];
            }

            // Check if access token is configured
            $accessToken = config('services.dropbox.access_token');
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'message' => 'No Dropbox access token configured.',
                ];
            }

            // Test the connection by getting account info
            $response = Http::withToken($this->getAccessToken())
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody('null')
                ->post($this->dropboxApiUrl . '/users/get_current_account');

            if ($response->successful()) {
                $accountInfo = $response->json();
                return [
                    'success' => true,
                    'message' => 'Connected to Dropbox as ' . ($accountInfo['name']['display_name'] ?? 'Unknown User'),
                    'account' => [
                        'name' => $accountInfo['name']['display_name'] ?? null,
                        'email' => $accountInfo['email'] ?? null,
                    ],
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to connect to Dropbox: ' . ($response->json()['error_summary'] ?? 'Unknown error'),
            ];

        } catch (\Exception $e) {
            Log::error('Dropbox connection test failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if Dropbox storage is enabled. A valid access token can come from
     * env config OR the DB-backed OauthToken record (refresh-token flow).
     */
    public function isEnabled(): bool
    {
        if (!config('services.dropbox.enabled', false)) {
            return false;
        }

        if (!empty(config('services.dropbox.access_token'))) {
            return true;
        }

        try {
            $hasDbToken = OauthToken::query()
                ->where('provider', 'dropbox')
                ->whereNotNull('access_token')
                ->where('access_token', '!=', '')
                ->exists();
            return $hasDbToken;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Comprehensive Dropbox health check with timings for each probe.
     *
     * Runs token resolution, account info, optional temporary link + streamed
     * download against a provided Dropbox path, and an optional folder listing.
     * Returns structured timings so operators can tell whether slow finalize is
     * caused by auth, Dropbox latency, or local disk write cost.
     */
    public function healthCheck(?string $probePath = null, ?string $probeFolder = null): array
    {
        $report = [
            'overall_success' => false,
            'enabled' => $this->isEnabled(),
            'env' => config('app.env'),
            'verify_ssl' => $this->httpOptions['verify'] ?? null,
            'http_timeout_seconds' => $this->httpOptions['timeout'] ?? null,
            'steps' => [],
        ];

        if (!$report['enabled']) {
            $report['steps'][] = [
                'name' => 'enabled_check',
                'success' => false,
                'message' => 'Dropbox integration is disabled or access_token is empty.',
            ];
            return $report;
        }

        // Step 1: resolve access token
        $tokenStart = microtime(true);
        try {
            $accessToken = $this->getAccessToken();
            $report['steps'][] = [
                'name' => 'resolve_access_token',
                'success' => (bool) $accessToken,
                'duration_ms' => (int) round((microtime(true) - $tokenStart) * 1000),
                'token_preview' => $accessToken ? substr($accessToken, 0, 6) . '…' : null,
            ];
            if (!$accessToken) {
                return $report;
            }
        } catch (\Throwable $e) {
            $report['steps'][] = [
                'name' => 'resolve_access_token',
                'success' => false,
                'duration_ms' => (int) round((microtime(true) - $tokenStart) * 1000),
                'error' => $e->getMessage(),
            ];
            return $report;
        }

        // Step 2: users/get_current_account
        $accountStart = microtime(true);
        try {
            $response = Http::withToken($accessToken)
                ->withOptions($this->httpOptions)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody('null')
                ->post($this->dropboxApiUrl . '/users/get_current_account');

            $step = [
                'name' => 'account_info',
                'success' => $response->successful(),
                'duration_ms' => (int) round((microtime(true) - $accountStart) * 1000),
                'status_code' => $response->status(),
            ];
            if ($response->successful()) {
                $data = $response->json() ?: [];
                $step['account_email'] = $data['email'] ?? null;
                $step['account_name'] = $data['name']['display_name'] ?? null;
                $step['team_member'] = !empty($data['team']);
            } else {
                $step['error'] = ($response->json()['error_summary'] ?? null) ?: $response->body();
            }
            $report['steps'][] = $step;

            if (!$response->successful()) {
                return $report;
            }
        } catch (\Throwable $e) {
            $report['steps'][] = [
                'name' => 'account_info',
                'success' => false,
                'duration_ms' => (int) round((microtime(true) - $accountStart) * 1000),
                'error' => $e->getMessage(),
            ];
            return $report;
        }

        // Step 3 (optional): probe folder listing
        if ($probeFolder) {
            $listStart = microtime(true);
            try {
                $response = Http::withToken($accessToken)
                    ->withOptions($this->httpOptions)
                    ->post($this->dropboxApiUrl . '/files/list_folder', [
                        'path' => $probeFolder,
                        'recursive' => false,
                        'limit' => 10,
                    ]);

                $step = [
                    'name' => 'list_folder',
                    'success' => $response->successful(),
                    'duration_ms' => (int) round((microtime(true) - $listStart) * 1000),
                    'status_code' => $response->status(),
                    'path' => $probeFolder,
                ];
                if ($response->successful()) {
                    $entries = $response->json()['entries'] ?? [];
                    $step['entry_count'] = count($entries);
                } else {
                    $step['error'] = ($response->json()['error_summary'] ?? null) ?: $response->body();
                }
                $report['steps'][] = $step;
            } catch (\Throwable $e) {
                $report['steps'][] = [
                    'name' => 'list_folder',
                    'success' => false,
                    'duration_ms' => (int) round((microtime(true) - $listStart) * 1000),
                    'error' => $e->getMessage(),
                    'path' => $probeFolder,
                ];
            }
        }

        // Step 4 (optional): probe temporary link + streamed download
        if ($probePath) {
            // get_temporary_link
            $linkStart = microtime(true);
            $tempUrl = null;
            try {
                $response = Http::withToken($accessToken)
                    ->withOptions($this->httpOptions)
                    ->post($this->dropboxApiUrl . '/files/get_temporary_link', [
                        'path' => $probePath,
                    ]);
                $step = [
                    'name' => 'get_temporary_link',
                    'success' => $response->successful(),
                    'duration_ms' => (int) round((microtime(true) - $linkStart) * 1000),
                    'status_code' => $response->status(),
                    'path' => $probePath,
                ];
                if ($response->successful()) {
                    $tempUrl = $response->json()['link'] ?? null;
                    $step['link_present'] = (bool) $tempUrl;
                } else {
                    $step['error'] = ($response->json()['error_summary'] ?? null) ?: $response->body();
                }
                $report['steps'][] = $step;
            } catch (\Throwable $e) {
                $report['steps'][] = [
                    'name' => 'get_temporary_link',
                    'success' => false,
                    'duration_ms' => (int) round((microtime(true) - $linkStart) * 1000),
                    'error' => $e->getMessage(),
                    'path' => $probePath,
                ];
            }

            // streamed download via content API (no local write, just measure throughput)
            $downloadStart = microtime(true);
            try {
                $apiArgs = json_encode(['path' => $probePath]);
                $response = Http::withToken($accessToken)
                    ->withOptions(array_merge($this->httpOptions, ['timeout' => 120]))
                    ->withHeaders(['Dropbox-API-Arg' => $apiArgs])
                    ->get($this->dropboxContentUrl . '/files/download');

                $step = [
                    'name' => 'download_probe',
                    'success' => $response->successful(),
                    'duration_ms' => (int) round((microtime(true) - $downloadStart) * 1000),
                    'status_code' => $response->status(),
                    'path' => $probePath,
                ];
                if ($response->successful()) {
                    $bytes = strlen($response->body());
                    $step['bytes'] = $bytes;
                    $step['megabytes'] = round($bytes / 1048576, 2);
                    $durationSec = max(microtime(true) - $downloadStart, 0.001);
                    $step['throughput_mb_per_sec'] = round(($bytes / 1048576) / $durationSec, 2);
                } else {
                    $step['error'] = ($response->json()['error_summary'] ?? null) ?: substr($response->body(), 0, 500);
                }
                $report['steps'][] = $step;
            } catch (\Throwable $e) {
                $report['steps'][] = [
                    'name' => 'download_probe',
                    'success' => false,
                    'duration_ms' => (int) round((microtime(true) - $downloadStart) * 1000),
                    'error' => $e->getMessage(),
                    'path' => $probePath,
                ];
            }
        }

        $report['overall_success'] = collect($report['steps'])->every(fn ($s) => $s['success'] ?? false);
        return $report;
    }
}
