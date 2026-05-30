<?php

namespace App\Jobs;

use App\Models\AiEditingJob;
use App\Models\ShootFile;
use App\Services\AutoenhanceService;
use App\Services\RawThumbnailService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessAutoenhanceEditingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 3;
    public $backoff = [60, 300, 600];

    public function __construct(public AiEditingJob $editingJob)
    {
    }

    public function handle(
        AutoenhanceService $autoenhanceService,
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService
    ): void {
        try {
            Log::info('ProcessAutoenhanceEditingJob: Starting', [
                'job_id' => $this->editingJob->id,
                'autoenhance_image_id' => $this->editingJob->autoenhance_image_id,
            ]);

            if (!$this->editingJob->autoenhance_image_id) {
                $this->submitJob($autoenhanceService, $fileAccessService, $rawThumbnailService);
            }

            if (!$this->editingJob->autoenhance_image_id) {
                if (!$this->editingJob->isFailed()) {
                    $this->editingJob->markAsFailed($this->editingJob->error_message ?: 'Failed to submit job to Autoenhance');
                }
                return;
            }

            $this->pollForCompletion($autoenhanceService);
        } catch (\Exception $e) {
            Log::error('ProcessAutoenhanceEditingJob: Exception', [
                'job_id' => $this->editingJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->editingJob->incrementRetry();
            if ($this->editingJob->retry_count >= $this->tries) {
                $this->editingJob->markAsFailed('Max retries reached: ' . $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    private function submitJob(
        AutoenhanceService $autoenhanceService,
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService
    ): void {
        $this->editingJob->markAsProcessing();

        $params = $this->editingJob->editing_params ?? [];
        $sourceFile = $this->editingJob->shootFile;
        if ($sourceFile) {
            $params['image_name'] = $sourceFile->filename;
            $params['mime_type'] = $sourceFile->mime_type ?? $sourceFile->file_type ?? null;
        }

        // Autoenhance natively supports most RAW formats, but a few compressed
        // variants (Nikon HE/HE*, Canon CRAW, Sony lossless) cannot be decoded
        // and come back as corrupted/partially-rendered images. For ONLY those
        // files, substitute a full-resolution JPEG before upload.
        $result = $this->submitWithRawSubstitution(
            $autoenhanceService,
            $fileAccessService,
            $rawThumbnailService,
            $sourceFile,
            $params
        );

        $imageId = $result['image_id'] ?? $result['job_id'] ?? ($result['data']['image_id'] ?? null);
        if ($result && $imageId) {
            $this->editingJob->autoenhance_image_id = (string) $imageId;
            $this->editingJob->provider_job_id = (string) $imageId;
            $this->editingJob->provider_order_id = $result['order_id'] ?? ($result['data']['order_id'] ?? null);
            $this->editingJob->provider = 'autoenhance';
            $this->editingJob->provider_payload = $result['data'] ?? $result;
            $this->editingJob->save();

            Log::info('ProcessAutoenhanceEditingJob: Job submitted', [
                'job_id' => $this->editingJob->id,
                'autoenhance_image_id' => $this->editingJob->autoenhance_image_id,
            ]);
        } else {
            $this->editingJob->provider_result = $result;
            $this->editingJob->save();
            $this->editingJob->markAsFailed($this->formatSubmissionFailure($result));

            Log::error('ProcessAutoenhanceEditingJob: Failed to submit to Autoenhance', [
                'job_id' => $this->editingJob->id,
                'result' => $result,
            ]);
        }
    }

    /**
     * Submit the source to Autoenhance, transparently substituting a full-size
     * JPEG when the source is a RAW variant Autoenhance cannot decode.
     *
     * Supported RAW (standard NEF, CR2, ARW, DNG, TIFF, ...) is uploaded
     * natively via the existing URL flow to preserve full RAW latitude.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function submitWithRawSubstitution(
        AutoenhanceService $autoenhanceService,
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService,
        ?ShootFile $sourceFile,
        array $params
    ): ?array {
        $filename = $sourceFile->filename ?? null;

        // Only RAW files are candidates for substitution.
        if (!$sourceFile || !$filename || !$rawThumbnailService->isRawFile($filename)) {
            return $autoenhanceService->submitEditingJob(
                $this->editingJob->original_image_url,
                $this->editingJob->editing_type,
                $params
            );
        }

        $localPath = $fileAccessService->findLocalFilePath($sourceFile);
        if (!$localPath) {
            // Fall back to Dropbox if the file isn't present locally.
            $localPath = $fileAccessService->downloadFromDropbox($sourceFile);
            $downloadedTemp = $localPath;
        }

        // If we can't get bytes, or the RAW is a supported variant, upload natively.
        if (!$localPath || !$rawThumbnailService->autoenhanceNeedsJpegSubstitution($localPath)) {
            if (!empty($downloadedTemp) && file_exists($downloadedTemp)) {
                @unlink($downloadedTemp);
            }
            return $autoenhanceService->submitEditingJob(
                $this->editingJob->original_image_url,
                $this->editingJob->editing_type,
                $params
            );
        }

        Log::info('ProcessAutoenhanceEditingJob: Substituting JPEG for unsupported compressed RAW', [
            'job_id' => $this->editingJob->id,
            'filename' => $filename,
        ]);

        $jpegPath = $rawThumbnailService->extractFullSizeJpeg($localPath);
        if (!empty($downloadedTemp) && file_exists($downloadedTemp)) {
            @unlink($downloadedTemp);
        }

        if (!$jpegPath) {
            Log::warning('ProcessAutoenhanceEditingJob: JPEG substitution failed; uploading RAW natively', [
                'job_id' => $this->editingJob->id,
                'filename' => $filename,
            ]);
            return $autoenhanceService->submitEditingJob(
                $this->editingJob->original_image_url,
                $this->editingJob->editing_type,
                $params
            );
        }

        try {
            $jpegName = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            $contents = file_get_contents($jpegPath);
            $params['image_name'] = $jpegName;
            $params['content_type'] = 'image/jpeg';
            $params['mime_type'] = 'image/jpeg';

            return $autoenhanceService->submitEditingJobFromBuffer(
                $contents,
                $jpegName,
                'image/jpeg',
                $this->editingJob->editing_type,
                $params
            );
        } finally {
            if (file_exists($jpegPath)) {
                @unlink($jpegPath);
            }
        }
    }

    private function formatSubmissionFailure(?array $result): string
    {
        if (!$result) {
            return 'Failed to submit job to Autoenhance';
        }

        $stage = $result['stage'] ?? 'submit';
        $status = $result['status'] ?? null;
        $error = $result['error'] ?? $result['message'] ?? null;

        $parts = ['Autoenhance submission failed'];
        if ($stage) {
            $parts[] = "during {$stage}";
        }
        if ($status) {
            $parts[] = "(HTTP {$status})";
        }

        $message = implode(' ', $parts);
        if ($error) {
            $message .= ': ' . $error;
        }

        return mb_substr($message, 0, 1000);
    }

    private function pollForCompletion(AutoenhanceService $autoenhanceService): void
    {
        $maxPolls = 60;
        $pollInterval = 10;
        $polls = 0;

        while ($polls < $maxPolls) {
            $status = $autoenhanceService->getJobStatus($this->editingJob->autoenhance_image_id);
            if (!$status) {
                sleep($pollInterval);
                $polls++;
                continue;
            }

            $jobStatus = strtolower((string) ($status['status'] ?? 'processing'));
            $this->editingJob->provider_result = $status;
            $this->editingJob->save();

            if ($jobStatus === 'completed') {
                $this->handleJobCompletion($autoenhanceService, $status);
                return;
            }

            if (in_array($jobStatus, ['failed', 'error', 'cancelled', 'rejected'], true)) {
                $errorMessage = $status['error'] ?? $status['message'] ?? $status['status_reason'] ?? 'Autoenhance job failed';
                $this->editingJob->markAsFailed($errorMessage);
                return;
            }

            sleep($pollInterval);
            $polls++;
        }

        Log::warning('ProcessAutoenhanceEditingJob: Max polls reached', [
            'job_id' => $this->editingJob->id,
            'autoenhance_image_id' => $this->editingJob->autoenhance_image_id,
        ]);
    }

    private function handleJobCompletion(AutoenhanceService $autoenhanceService, array $status): void
    {
        $editedImageUrl = $status['enhanced_image_url']
            ?? $status['result_url']
            ?? $status['image_url']
            ?? $status['edited_image_url']
            ?? null;

        if (!$editedImageUrl) {
            $editedImageUrl = $autoenhanceService->downloadEditedImage($this->editingJob->autoenhance_image_id);
        }

        if (!$editedImageUrl) {
            $this->editingJob->markAsFailed('Enhanced image URL not found in Autoenhance response');
            return;
        }

        $storedPath = $this->storeEnhancedImage($editedImageUrl);
        $this->editingJob->markAsCompleted($storedPath ?: $editedImageUrl);

        Log::info('ProcessAutoenhanceEditingJob: Job completed successfully', [
            'job_id' => $this->editingJob->id,
            'autoenhance_image_id' => $this->editingJob->autoenhance_image_id,
            'edited_image_url' => $editedImageUrl,
            'stored_path' => $storedPath,
        ]);
    }

    private function storeEnhancedImage(string $editedImageUrl): ?string
    {
        try {
            $sourceFile = $this->editingJob->shootFile;
            $shoot = $this->editingJob->shoot;
            if (!$shoot) {
                return null;
            }

            $binary = null;
            $mimeType = 'image/jpeg';
            if (str_starts_with($editedImageUrl, 'data:')) {
                [$meta, $payload] = explode(',', $editedImageUrl, 2);
                if (preg_match('/^data:([^;]+)/', $meta, $matches)) {
                    $mimeType = $matches[1];
                }
                $binary = base64_decode($payload);
            } else {
                $response = \Illuminate\Support\Facades\Http::timeout(120)->get($editedImageUrl);
                if (!$response->successful()) {
                    return null;
                }
                $binary = $response->body();
                $mimeType = $response->header('Content-Type', $mimeType);
            }

            if (!$binary) {
                return null;
            }

            $extension = match (strtolower($mimeType)) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $baseName = pathinfo($sourceFile?->filename ?: 'autoenhance-image', PATHINFO_FILENAME);
            $filename = Str::slug($baseName) . '-autoenhance-' . $this->editingJob->id . '.' . $extension;
            $path = 'shoots/' . $shoot->id . '/autoenhance/' . $filename;
            Storage::disk('public')->put($path, $binary);
            $publicPath = 'storage/' . $path;

            ShootFile::create([
                'shoot_id' => $shoot->id,
                'filename' => $filename,
                'stored_filename' => $filename,
                'path' => $publicPath,
                'storage_path' => $publicPath,
                'file_type' => $mimeType,
                'mime_type' => $mimeType,
                'media_type' => 'edited',
                'file_size' => strlen($binary),
                'uploaded_by' => $this->editingJob->user_id,
                'uploaded_at' => now(),
                'workflow_stage' => ShootFile::STAGE_COMPLETED,
                'ai_editing_job_id' => $this->editingJob->id,
                'is_ai_edited' => true,
                'ai_editing_metadata' => [
                    'provider' => 'autoenhance',
                    'source_file_id' => $sourceFile?->id,
                    'editing_type' => $this->editingJob->editing_type,
                    'completed_at' => now()->toIso8601String(),
                ],
            ]);

            $shoot->workflowLogs()->create([
                'user_id' => $this->editingJob->user_id,
                'action' => 'autoenhance_completed',
                'details' => "Autoenhance completed for '{$filename}'",
                'metadata' => [
                    'ai_editing_job_id' => $this->editingJob->id,
                    'source_file_id' => $sourceFile?->id,
                    'path' => $publicPath,
                ],
            ]);

            return $publicPath;
        } catch (\Throwable $e) {
            Log::error('ProcessAutoenhanceEditingJob: Failed to store enhanced image', [
                'job_id' => $this->editingJob->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAutoenhanceEditingJob: Job failed permanently', [
            'job_id' => $this->editingJob->id,
            'error' => $exception->getMessage(),
        ]);
        $this->editingJob->markAsFailed('Job processing failed: ' . $exception->getMessage());
    }
}
