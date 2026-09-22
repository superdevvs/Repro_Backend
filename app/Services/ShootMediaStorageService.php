<?php

namespace App\Services;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Jobs\ScanShootFileJob;
use App\Jobs\SyncShootFileToR2Job;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\FileScanService;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Canonical local media intake, scanning and workflow storage. */
class ShootMediaStorageService
{
    /**
     * Attempts, first try included, for saving one media record. More than
     * LockedWrite's default because the competing writers are the image-processing
     * workers, whose commits are frequent while a batch of RAW files is landing,
     * and giving up means a photographer re-sending a 50MB frame.
     */
    public const RECORD_WRITE_ATTEMPTS = 6;

    /** Intake diagnostics channel, kept visible under the production log level. */
    public const LOG_CHANNEL = 'uploads';

    protected RawThumbnailService $rawThumbnailService;

    public function __construct(?RawThumbnailService $rawThumbnailService = null)
    {
        $this->rawThumbnailService = $rawThumbnailService ?: new RawThumbnailService;
    }

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
                        if (! empty($exif[$field])) {
                            $metadata['captured_at'] = $exif[$field];
                            break;
                        }
                    }
                    // Store camera info if available
                    if (! empty($exif['Make'])) {
                        $metadata['camera_make'] = $exif['Make'];
                    }
                    if (! empty($exif['Model'])) {
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
                if (! array_key_exists($key, $metadata) || empty($metadata[$key])) {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }

    protected function extractMetadataWithExifTool(?string $path): array
    {
        if (! $path || ! file_exists($path) || ! $this->commandExists('exiftool')) {
            return [];
        }

        $cmd = sprintf(
            'exiftool -j -DateTimeOriginal -CreateDate -ModifyDate -ImageWidth -ImageHeight -Make -Model %s 2>&1',
            escapeshellarg($path)
        );
        $output = [];
        exec($cmd, $output, $code);

        if ($code !== 0 || empty($output)) {
            return [];
        }

        $rows = json_decode(implode("\n", $output), true);
        if (! is_array($rows) || ! isset($rows[0]) || ! is_array($rows[0])) {
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

        if (! empty($row['Make']) && is_string($row['Make'])) {
            $metadata['camera_make'] = $row['Make'];
        }

        if (! empty($row['Model']) && is_string($row['Model'])) {
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

    /** Prepare the local shoot identity without creating external folders. */
    public function createShootFolders(Shoot $shoot): void
    {
        if (! $shoot->property_slug) {
            $shoot->property_slug = $shoot->generatePropertySlug();
            $shoot->save();
        }
    }

    public function uploadToTodo(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        $serviceCategory = null,
        ?string $mediaTypeOverride = null,
        ?int $shootServiceId = null,
        ?array $metadataOverride = null
    ) {
        $mediaType = $mediaTypeOverride
            ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), 'raw', $serviceCategory);

        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO, $mediaType, $shootServiceId, $metadataOverride);
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
     *                                                    is found to be infected — the upload is rejected and never stored.
     */
    private function scanUploadSynchronously(UploadedFile $file): string
    {
        if (! config('clamav.scan_on_upload', true)) {
            return 'unavailable';
        }

        // Tests run without a clamd daemon; the async dispatch path (and the
        // dedicated ClamAv integration test) covers scanning there.
        if (app()->runningUnitTests()) {
            return 'unavailable';
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
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

        if (! $result->isClean()) {
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
     * Store an upload on local storage and enqueue its scan.
     *
     * Two phases, deliberately. The bytes are staged first with no transaction
     * open, because scanning, EXIF extraction and moving a RAW into place take
     * seconds. The record is then saved in a transaction short enough to retry
     * when SQLite refuses the write because a queue worker committed meanwhile.
     * See {@see StagedShootUpload} for the failure this replaces. Retrying is
     * safe: nothing inside the transaction touches the staged bytes.
     *
     * If the record still cannot be saved, the staged bytes are removed so a
     * failed upload leaves nothing behind, and the exception surfaces unchanged.
     */
    private function storeLocally(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        string $stage,
        ?string $mediaTypeOverride = null,
        ?int $shootServiceId = null,
        ?array $metadataOverride = null
    ): ShootFile {
        $stagingStartedAt = microtime(true);
        $staged = $this->stageLocally($shoot, $file, $userId, $stage, $mediaTypeOverride, $shootServiceId, $metadataOverride);
        $stagingMs = (int) round((microtime(true) - $stagingStartedAt) * 1000);

        $attempts = 0;
        try {
            $shootFile = LockedWrite::run(
                function () use ($staged, &$attempts) {
                    $attempts++;

                    return DB::transaction(fn () => $this->persistStagedUpload($staged));
                },
                "shoot.{$shoot->id}.store-media.{$staged->originalFilename}",
                self::recordWriteAttempts()
            );
        } catch (\Throwable $e) {
            $staged->discard();

            Log::channel(self::LOG_CHANNEL)->error('Media record could not be saved; staged bytes discarded.', [
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $shootServiceId,
                'file_name' => $staged->originalFilename,
                'stage' => $stage,
                'staging_ms' => $stagingMs,
                'db_attempts' => $attempts,
                'lock_contention' => LockedWrite::isLockContention($e),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($attempts > 1) {
            // Landed, but only after losing a race. Worth a line of its own because
            // a rising count here is the early warning that the queue workers are
            // contending with intake again, well before anything fails outright.
            Log::channel(self::LOG_CHANNEL)->warning('Media record saved after retrying a locked database.', [
                'shoot_id' => $shoot->id,
                'shoot_file_id' => $shootFile->id,
                'file_name' => $staged->originalFilename,
                'db_attempts' => $attempts,
                'staging_ms' => $stagingMs,
            ]);
        }

        return $shootFile;
    }

    /**
     * How many times the record write may run.
     *
     * Retrying is only sound at the outermost transaction level. Inside a
     * caller's open transaction Laravel turns the inner transaction into a
     * savepoint and, on a concurrency error, deliberately does not roll that
     * savepoint back — the statements may or may not have applied, so running
     * them again could duplicate the row. In that situation the write gets one
     * attempt and the caller's own transaction decides the outcome.
     */
    public static function recordWriteAttempts(): int
    {
        return DB::transactionLevel() > 0 ? 1 : self::RECORD_WRITE_ATTEMPTS;
    }

    /**
     * Everything about intake that touches disk or another process, and nothing
     * that writes to the database. Kept transaction-free on purpose: see
     * {@see StagedShootUpload} for why holding one open across this work made
     * raw uploads fail intermittently.
     */
    private function stageLocally(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        string $stage,
        ?string $mediaTypeOverride = null,
        ?int $shootServiceId = null,
        ?array $metadataOverride = null
    ): StagedShootUpload {
        $isOpaqueIguidePackage = $mediaTypeOverride === ShootFile::MEDIA_TYPE_IGUIDE
            && data_get($metadataOverride, 'kind') === ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND;
        $storageMediaType = in_array($mediaTypeOverride, ['extra', 'floorplan', 'virtual_staging', 'green_grass', 'twilight', 'drone', ShootFile::MEDIA_TYPE_IGUIDE], true)
            ? $mediaTypeOverride
            : null;
        $prefix = match ($storageMediaType) {
            'extra' => 'EXTRA_',
            'floorplan' => 'FLOORPLAN_',
            'virtual_staging' => 'VIRTUAL_STAGING_',
            'green_grass' => 'GREEN_GRASS_',
            'twilight' => 'TWILIGHT_',
            'drone' => 'DRONE_',
            ShootFile::MEDIA_TYPE_IGUIDE => 'IGUIDE_',
            default => $stage === ShootFile::STAGE_COMPLETED ? 'COMPLETED_' : 'TODO_',
        };
        $filename = $prefix.str_replace('.', '_', uniqid('', true)).'_'.$file->getClientOriginalName();
        $dir = match ($storageMediaType) {
            'extra' => "shoots/{$shoot->id}/extra",
            'floorplan' => "shoots/{$shoot->id}/floorplan",
            'virtual_staging' => "shoots/{$shoot->id}/virtual_staging",
            'green_grass' => "shoots/{$shoot->id}/green_grass",
            'twilight' => "shoots/{$shoot->id}/twilight",
            'drone' => "shoots/{$shoot->id}/drone",
            ShootFile::MEDIA_TYPE_IGUIDE => "secure/iguide-packages/{$shoot->id}",
            default => "shoots/{$shoot->id}/".($stage === ShootFile::STAGE_COMPLETED ? 'completed' : 'todo'),
        };
        $serverPath = $dir.'/'.$filename;
        $storageDisk = $isOpaqueIguidePackage
            ? 'local'
            : (config('media.r2_only')
                ? (string) config('media.remote_disk', 'media')
                : app(\App\Services\Media\MediaStorage::class)->writeDiskName($serverPath));
        $defaultMediaType = $storageMediaType
            ?? ($stage === ShootFile::STAGE_COMPLETED ? 'edited' : 'raw');
        $mediaType = $mediaTypeOverride ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), $defaultMediaType);

        // Re-uploading a filename replaces that file in place, which is how a corrected
        // frame is handed in. The match has to be scoped to the execution row, though,
        // because a filename is only unique per camera, not per shoot: two photographers
        // working one shoot both hand in DSC_0001.jpg. Matching on (shoot, filename,
        // stage) alone meant the second service's frame overwrote the first service's
        // file, leaving one row with the wrong attribution and one service silently a
        // frame short. Unassigned uploads form their own bucket, mirroring how bracket
        // stacking already partitions them.
        // A replacement iGUIDE must not overwrite the currently-ready ZIP before
        // the new package clears quarantine. Every attempt therefore receives a
        // new row; the lifecycle pointer changes only after a clean verdict.
        $existingFile = $isOpaqueIguidePackage
            ? null
            : ShootFile::where('shoot_id', $shoot->id)
                ->where('filename', $file->getClientOriginalName())
                ->where('workflow_stage', $stage)
                ->when(
                    $shootServiceId !== null,
                    fn ($query) => $query->where('shoot_service_id', $shootServiceId),
                    fn ($query) => $query->whereNull('shoot_service_id')
                )
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
        $metadata = array_replace(
            $isOpaqueIguidePackage ? [] : $this->extractImageMetadata($file),
            $metadataOverride ?? []
        );

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
        // Large offline packages are scanned member-by-member by ScanShootFileJob.
        // Whole-ZIP INSTREAM scanning commonly exceeds clamd's default stream
        // limit and would keep a legitimate package retrying forever.
        $syncScanVerdict = $isOpaqueIguidePackage
            ? 'unavailable'
            : $this->scanUploadSynchronously($file);

        // Now store the file (this may move the temp file)
        $storedPath = Storage::disk($storageDisk)->putFileAs($dir, $file, $filename);
        if ($storedPath === false || $storedPath === '') {
            throw new \RuntimeException('The uploaded file could not be written to quarantine storage.');
        }
        $serverPath = $storedPath;

        // Attribute the row to its execution row at creation. The caller also sets this,
        // but doing it here means the row is never briefly unattributed, which is what
        // the duplicate lookup above has to match against on a subsequent upload.
        $identity = array_filter([
            'shoot_id' => $shoot->id,
            'filename' => $file->getClientOriginalName(),
            'workflow_stage' => $stage,
            'shoot_service_id' => $shootServiceId,
        ], fn ($value) => $value !== null);

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
            'metadata' => ! empty($metadata) ? $metadata : null,
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

        // Decided from what the row will hold once filled, so the answer is fixed
        // here and does not depend on the record having been saved yet.
        $requiresImageProcessing = $this->shouldProcessImage($file)
            && (
                $isReplacement
                || ! $processedAt
                || ! $thumbnailPath
                || ! $webPath
                || ! $placeholderPath
            );

        return new StagedShootUpload(
            shoot: $shoot,
            existingFile: $existingFile,
            identity: $identity,
            attributes: $attributes,
            stage: $stage,
            originalFilename: $file->getClientOriginalName(),
            storedFilename: $filename,
            storedPath: $serverPath,
            storageDisk: $storageDisk,
            syncScanVerdict: $syncScanVerdict,
            isOpaqueIguidePackage: $isOpaqueIguidePackage,
            requiresImageProcessing: $requiresImageProcessing,
        );
    }

    /**
     * Save the media record for bytes that {@see stageLocally()} already put in place.
     *
     * Only database work and job dispatch happen here, which is what lets
     * {@see storeLocally()} run it in a short transaction and retry that
     * transaction when SQLite reports another writer. Running it again for the
     * same staged upload after a rolled-back attempt is safe: the bytes are
     * untouched and the row is created or filled afresh each time.
     */
    private function persistStagedUpload(StagedShootUpload $staged): ShootFile
    {
        $shoot = $staged->shoot;
        $serverPath = $staged->storedPath;

        $shootFile = $staged->existingFile ?: new ShootFile($staged->identity);
        $shootFile->fill($staged->attributes);
        $shootFile->save();

        if ($staged->requiresImageProcessing && app()->runningUnitTests()) {
            // Inline image processing for tests so derived asset paths are
            // populated immediately. Real environments rely on the queued
            // ProcessImageJob dispatched by FileScanService::release once the
            // scan verdict is clean (task 13.5/13.6).
            $generatedPaths = app(ImageProcessingService::class)->processImageFromPath(
                $shoot->id,
                $shootFile->filename,
                Storage::disk($staged->storageDisk)->path($serverPath)
            );

            if (! empty($generatedPaths)) {
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
        // Downstream gating inside image processing jobs
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
        if ($staged->syncScanVerdict === 'clean' && ! app()->runningUnitTests()) {
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
                // Dispatch while the controller can still contain a sync-driver
                // failure. `afterResponse()` runs outside this try/catch and can
                // append an exception payload to an already-sent JSON response.
                // Real queue drivers enqueue immediately; the sync driver runs
                // here and leaves the file quarantined if clamd is unavailable.
                ScanShootFileJob::dispatch($shootFile->id);
            } catch (\Throwable $e) {
                Log::warning('Scan job dispatch failed after local upload', [
                    'shoot_id' => $shoot->id,
                    'file_id' => $shootFile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mirror the upload to Cloudflare R2 when the dual-write/R2-only cutover
        // is enabled (config/media.php). The job is idempotent and re-dispatched
        // after image processing/watermarking so derived assets sync too.
        if (! $staged->isOpaqueIguidePackage && (config('media.dual_write') || config('media.r2_only'))) {
            try {
                SyncShootFileToR2Job::dispatch($shootFile->id);
            } catch (\Throwable $e) {
                Log::warning('R2 sync dispatch failed after local upload', [
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

        Log::info('Stored shoot media locally', [
            'shoot_id' => $shoot->id,
            'filename' => $staged->storedFilename,
            'path' => $serverPath,
            'stage' => $staged->stage,
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
            if ($storedPath) {
                app(\App\Services\Media\MediaStorage::class)->delete($storedPath);
            }
        }
    }

    public function moveToCompleted(ShootFile $shootFile, $userId): bool
    {
        $shootFile->moveToCompleted($userId);
        return true;
    }

    public function moveToFinal(ShootFile $shootFile, $userId)
    {
        $shoot = $shootFile->shoot;

        try {
            $this->copyLocalCompletedToFinal($shootFile);

            // Mark verified after the best-effort local copy
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
            // Last-resort fallback: even if the local final copy failed,
            // mark the file as verified so finalize can complete. The file remains
            // accessible via its existing stored path through the read
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
        if (! $shoot) {
            return;
        }

        $currentPath = $shootFile->path;
        if (! $currentPath) {
            return;
        }

        $media = app(\App\Services\Media\MediaStorage::class);
        if (! $media->exists($currentPath)) {
            // Source missing locally; nothing to copy. Read path will resolve
            // via existing local or configured R2 preview pipelines.
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
            $sourceDisk = $media->diskFor($currentPath);
            $key = $media->normalizeKey($currentPath);
            if ($sourceDisk === null || $key === null) {
                return;
            }

            $stream = $sourceDisk->readStream($key);
            if ($stream === false || $stream === null) {
                return;
            }

            try {
                if (!$media->put($serverPath, $stream)) {
                    throw new \RuntimeException('The final original could not be stored.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

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

    public function uploadToCompleted(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        $serviceCategory = null,
        ?string $mediaTypeOverride = null,
        ?int $shootServiceId = null,
        ?array $metadataOverride = null
    ) {
        $mediaType = $mediaTypeOverride
            ?? $this->resolveMediaType($file->getClientOriginalName(), $file->getMimeType(), 'edited', $serviceCategory);

        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_COMPLETED, $mediaType, $shootServiceId, $metadataOverride);
    }

    /**
     * Store a manual iGUIDE package outside the photo workflow while reusing the
     * same quarantine, scan and audit-capable ShootFile pipeline.
     */
    public function uploadIguideOfflinePackage(
        Shoot $shoot,
        UploadedFile $file,
        $userId,
        array $metadata
    ): ShootFile {
        if (data_get($metadata, 'kind') !== ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND) {
            throw new \InvalidArgumentException('Missing iGUIDE offline package metadata marker.');
        }

        return $this->storeLocally(
            $shoot,
            $file,
            $userId,
            ShootFile::STAGE_ARCHIVED,
            ShootFile::MEDIA_TYPE_IGUIDE,
            null,
            $metadata
        );
    }

    public function uploadToExtra(Shoot $shoot, UploadedFile $file, $userId)
    {
        return $this->storeLocally($shoot, $file, $userId, ShootFile::STAGE_TODO, 'extra');
    }

}
