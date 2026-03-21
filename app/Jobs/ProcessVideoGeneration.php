<?php

namespace App\Jobs;

use App\Http\Controllers\API\HiggsFieldController;
use App\Models\AiVideoGenerationJob;
use App\Models\ShootFile;
use App\Services\HiggsFieldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 3;
    public $backoff = [60, 300, 600];

    public function __construct(public AiVideoGenerationJob $videoJob)
    {
    }

    public function handle(HiggsFieldService $higgsFieldService): void
    {
        try {
            Log::info('ProcessVideoGeneration: Starting', [
                'job_id' => $this->videoJob->id,
            ]);

            $this->videoJob->markAsGenerating();

            // For horizontal: read images from disk and convert to base64 for Higgsfield API
            // For vertical: use the selected variant URLs (already hosted by Higgsfield)
            if ($this->videoJob->aspect_ratio === 'vertical') {
                $startFrameUrl = $this->videoJob->selected_start_frame_url;
                $endFrameUrl = $this->videoJob->selected_end_frame_url;
            } else {
                // Read from local disk as base64 — Higgsfield can't reach our server URLs
                $startFrameUrl = $this->resolveImageForApi($this->videoJob->start_frame_file_id);
                $endFrameUrl = $this->videoJob->end_frame_file_id
                    ? $this->resolveImageForApi($this->videoJob->end_frame_file_id)
                    : null;
            }

            if (!$startFrameUrl) {
                $this->videoJob->markAsFailed('No start frame image available — could not read from disk');
                return;
            }

            // Submit video generation
            $result = $higgsFieldService->generateVideo(
                $startFrameUrl,
                $endFrameUrl,
                $this->videoJob->preset_prompt
            );

            if (!$result || !isset($result['request_id'])) {
                $this->videoJob->markAsFailed('Failed to submit video generation to Higgsfield');
                return;
            }

            $this->videoJob->higgsfield_video_request_id = $result['request_id'];
            $this->videoJob->save();

            Log::info('ProcessVideoGeneration: Submitted to Higgsfield', [
                'job_id' => $this->videoJob->id,
                'request_id' => $result['request_id'],
            ]);

            // Poll for completion
            $this->pollForCompletion($higgsFieldService);

        } catch (\Exception $e) {
            Log::error('ProcessVideoGeneration: Exception', [
                'job_id' => $this->videoJob->id,
                'error' => $e->getMessage(),
            ]);

            $this->videoJob->incrementRetry();

            if ($this->videoJob->retry_count >= $this->tries) {
                $this->videoJob->markAsFailed('Max retries reached: ' . $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    private function pollForCompletion(HiggsFieldService $higgsFieldService): void
    {
        $maxPolls = 60;
        $pollInterval = 10;
        $polls = 0;

        while ($polls < $maxPolls) {
            $status = $higgsFieldService->getRequestStatus(
                $this->videoJob->higgsfield_video_request_id
            );

            if (!$status) {
                Log::warning('ProcessVideoGeneration: Failed to get status', [
                    'job_id' => $this->videoJob->id,
                    'poll' => $polls,
                ]);
                sleep($pollInterval);
                $polls++;
                continue;
            }

            $requestStatus = $status['status'] ?? 'unknown';

            Log::info('ProcessVideoGeneration: Status check', [
                'job_id' => $this->videoJob->id,
                'status' => $requestStatus,
                'poll' => $polls,
            ]);

            if ($requestStatus === 'completed') {
                $videoUrl = $status['video_url'] ?? null;

                if ($videoUrl) {
                    $this->videoJob->markAsCompleted($videoUrl);
                    Log::info('ProcessVideoGeneration: Completed', [
                        'job_id' => $this->videoJob->id,
                        'video_url' => $videoUrl,
                    ]);
                } else {
                    $this->videoJob->markAsFailed('Video URL not found in completed response');
                }
                return;
            }

            if (in_array($requestStatus, ['failed', 'nsfw'])) {
                $error = $status['error'] ?? 'Video generation failed with status: ' . $requestStatus;
                $this->videoJob->markAsFailed($error);
                return;
            }

            sleep($pollInterval);
            $polls++;
        }

        Log::warning('ProcessVideoGeneration: Max polls reached', [
            'job_id' => $this->videoJob->id,
        ]);
    }

    /**
     * Resolve a shoot file to a base64 data URI for sending to Higgsfield API.
     * Falls back to the stored public URL if file can't be read from disk.
     */
    private function resolveImageForApi(?int $fileId): ?string
    {
        if (!$fileId) return null;

        try {
            $shootFile = ShootFile::find($fileId);
            if (!$shootFile) {
                Log::warning('ProcessVideoGeneration: ShootFile not found', ['file_id' => $fileId]);
                return null;
            }

            // Try base64 from disk first (most reliable for external APIs)
            $base64 = HiggsFieldController::readImageAsBase64($shootFile);
            if ($base64) {
                Log::info('ProcessVideoGeneration: Using base64 image', [
                    'file_id' => $fileId,
                    'base64_length' => strlen($base64),
                ]);
                return $base64;
            }

            // Fall back to stored URL
            $url = $this->videoJob->start_frame_file_id === $fileId
                ? $this->videoJob->original_start_frame_url
                : $this->videoJob->original_end_frame_url;

            Log::warning('ProcessVideoGeneration: Could not read file from disk, using stored URL', [
                'file_id' => $fileId,
                'url' => $url,
            ]);

            return $url;
        } catch (\Exception $e) {
            Log::error('ProcessVideoGeneration: Error resolving image', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoGeneration: Job failed permanently', [
            'job_id' => $this->videoJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->videoJob->markAsFailed('Video generation failed: ' . $exception->getMessage());
    }
}
