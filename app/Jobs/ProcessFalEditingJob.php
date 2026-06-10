<?php

namespace App\Jobs;

use App\Models\AiEditingJob;
use App\Models\ShootFile;
use App\Services\FalService;
use App\Services\RawThumbnailService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProcessFalEditingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    public function __construct(public AiEditingJob $editingJob)
    {
    }

    public function handle(
        FalService $falService,
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService
    ): void {
        try {
            if (!$this->editingJob->provider_job_id) {
                $this->submitJob($falService, $fileAccessService, $rawThumbnailService);
            }

            if (!$this->editingJob->provider_job_id) {
                $this->editingJob->markAsFailed($this->editingJob->error_message ?: 'Failed to submit job to fal.ai');
                return;
            }

            $this->pollForCompletion($falService);
        } catch (\Throwable $e) {
            Log::error('ProcessFalEditingJob: Exception', [
                'job_id' => $this->editingJob->id,
                'error' => $e->getMessage(),
            ]);

            $this->editingJob->incrementRetry();
            if ($this->editingJob->retry_count >= $this->tries) {
                $this->editingJob->markAsFailed('Max retries reached: ' . $e->getMessage());
                return;
            }

            throw $e;
        }
    }

    private function submitJob(
        FalService $falService,
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService
    ): void {
        $this->editingJob->markAsProcessing();

        $params = $this->editingJob->editing_params ?? [];
        $source = $this->resolveSource($fileAccessService, $rawThumbnailService);
        $params = array_merge($params, [
            'image_name' => $source['name'],
            'content_type' => $source['content_type'],
        ]);

        $result = isset($source['contents'])
            ? $falService->submitImageEditFromBuffer(
                $source['contents'],
                $source['name'],
                $source['content_type'],
                $this->editingJob->editing_type,
                $params
            )
            : $falService->submitImageEdit(
                $source['url'],
                $this->editingJob->editing_type,
                $params
            );

        $requestId = $result['request_id'] ?? $result['job_id'] ?? null;
        if (!$requestId) {
            $this->editingJob->provider_result = $result;
            $this->editingJob->save();
            $this->editingJob->markAsFailed('fal.ai submission did not return a request id');
            return;
        }

        $this->editingJob->provider = 'fal';
        $this->editingJob->provider_job_id = (string) $requestId;
        $this->editingJob->provider_payload = $result;
        $this->editingJob->status = AiEditingJob::STATUS_PROCESSING;
        $this->editingJob->started_at = now();
        $this->editingJob->save();
    }

    private function pollForCompletion(FalService $falService): void
    {
        $maxPolls = 60;
        $pollInterval = 10;

        for ($polls = 0; $polls < $maxPolls; $polls++) {
            $status = $falService->imageEditStatus((string) $this->editingJob->provider_job_id);
            if (!$status) {
                sleep($pollInterval);
                continue;
            }

            $normalized = strtolower((string) ($status['status'] ?? 'processing'));
            $this->editingJob->provider_result = array_merge($this->editingJob->provider_result ?? [], [
                'status' => $status,
            ]);
            $this->editingJob->save();

            if ($normalized === 'completed') {
                $result = $falService->imageEditResult((string) $this->editingJob->provider_job_id);
                $editedImageUrl = $result['edited_image_url'] ?? $result['image_url'] ?? null;
                if (!$editedImageUrl) {
                    $this->editingJob->markAsFailed('fal.ai result did not include an edited image URL');
                    return;
                }

                $stored = $this->storeEditedImage($editedImageUrl);
                $this->editingJob->provider_result = array_merge($this->editingJob->provider_result ?? [], [
                    'result' => $result,
                ]);
                $this->editingJob->markAsCompleted($stored ?: $editedImageUrl);
                return;
            }

            if (in_array($normalized, ['failed', 'error', 'cancelled'], true)) {
                $this->editingJob->markAsFailed((string) ($status['error'] ?? 'fal.ai image edit failed'));
                return;
            }

            sleep($pollInterval);
        }
    }

    private function resolveSource(
        ShootFileAccessService $fileAccessService,
        RawThumbnailService $rawThumbnailService
    ): array {
        $sourceFile = $this->editingJob->shootFile;
        if (!$sourceFile) {
            $url = $this->editingJob->original_image_url;
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('No source image was available for fal.ai editing');
            }

            return [
                'url' => $url,
                'name' => basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg'),
                'content_type' => 'image/jpeg',
            ];
        }

        $filename = $sourceFile->filename ?: 'image.jpg';
        $localPath = $fileAccessService->findLocalFilePath($sourceFile);
        $downloadedTemp = null;
        if (!$localPath) {
            $localPath = $fileAccessService->downloadFromDropbox($sourceFile);
            $downloadedTemp = $localPath;
        }

        if ($localPath && is_file($localPath)) {
            $readPath = $localPath;
            $readName = $filename;
            $contentType = $sourceFile->mime_type ?? $sourceFile->file_type ?? $this->mimeForName($filename);
            $extractedJpeg = null;

            if ($rawThumbnailService->isRawFile($filename)) {
                $extractedJpeg = $rawThumbnailService->extractFullSizeJpeg($localPath);
                if ($extractedJpeg && is_file($extractedJpeg)) {
                    $readPath = $extractedJpeg;
                    $readName = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
                    $contentType = 'image/jpeg';
                }
            }

            try {
                $contents = file_get_contents($readPath);
                if ($contents === false || $contents === '') {
                    throw new RuntimeException("Could not read {$readName}");
                }

                return [
                    'contents' => $contents,
                    'name' => $readName,
                    'content_type' => $contentType,
                ];
            } finally {
                if ($extractedJpeg && is_file($extractedJpeg)) {
                    @unlink($extractedJpeg);
                }
                if ($downloadedTemp && is_file($downloadedTemp)) {
                    @unlink($downloadedTemp);
                }
            }
        }

        if ($this->editingJob->original_image_url && filter_var($this->editingJob->original_image_url, FILTER_VALIDATE_URL)) {
            return [
                'url' => $this->editingJob->original_image_url,
                'name' => $filename,
                'content_type' => $sourceFile->mime_type ?? $sourceFile->file_type ?? $this->mimeForName($filename),
            ];
        }

        throw new RuntimeException('Could not resolve source image for fal.ai editing');
    }

    private function storeEditedImage(string $editedImageUrl): ?string
    {
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
            $response = Http::timeout(120)->get($editedImageUrl);
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
        $sourceFile = $this->editingJob->shootFile;
        $baseName = pathinfo($sourceFile?->filename ?: 'fal-image', PATHINFO_FILENAME);
        $filename = Str::slug($baseName) . '-fal-' . $this->editingJob->id . '.' . $extension;
        $path = 'shoots/' . $shoot->id . '/fal-ai/' . $filename;
        Storage::disk('public')->put($path, $binary, 'public');
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
                'provider' => 'fal',
                'source_file_id' => $sourceFile?->id,
                'editing_type' => $this->editingJob->editing_type,
                'completed_at' => now()->toIso8601String(),
            ],
        ]);

        $shoot->workflowLogs()->create([
            'user_id' => $this->editingJob->user_id,
            'action' => 'fal_edit_completed',
            'details' => "fal.ai edit completed for '{$filename}'",
            'metadata' => [
                'ai_editing_job_id' => $this->editingJob->id,
                'source_file_id' => $sourceFile?->id,
                'path' => $publicPath,
            ],
        ]);

        return $publicPath;
    }

    private function mimeForName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
