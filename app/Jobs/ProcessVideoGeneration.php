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

            // For vertical: use the selected variant URLs (already hosted by Higgsfield)
            // For all others: use the public web URL (Higgsfield fetches via HTTP)
            if ($this->videoJob->aspect_ratio === 'vertical') {
                $startFrameUrl = $this->videoJob->selected_start_frame_url;
                $endFrameUrl = $this->videoJob->selected_end_frame_url;
            } else {
                $startFrameUrl = $this->resolveWebUrl($this->videoJob->start_frame_file_id)
                    ?? $this->videoJob->original_start_frame_url;
                $endFrameUrl = $this->videoJob->end_frame_file_id
                    ? ($this->resolveWebUrl($this->videoJob->end_frame_file_id)
                        ?? $this->videoJob->original_end_frame_url)
                    : null;
            }

            if (!$startFrameUrl) {
                $this->videoJob->markAsFailed('No start frame image available — could not read from disk');
                return;
            }

            // Map aspect_ratio to API format
            $apiAspectRatio = match ($this->videoJob->aspect_ratio) {
                'horizontal' => '16:9',
                'vertical'   => '9:16',
                'square'     => '1:1',
                'standard'   => '4:3',
                default      => '16:9',
            };

            // Submit video generation
            $result = $higgsFieldService->generateVideo(
                $startFrameUrl,
                $endFrameUrl,
                $this->videoJob->preset_prompt,
                5,
                $apiAspectRatio
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
     * Resolve a shoot file to its web-sized public URL for Higgsfield API.
     * Prefers web_path (smaller/optimized) over full storage_path.
     */
    private function resolveWebUrl(?int $fileId): ?string
    {
        if (!$fileId) return null;

        try {
            $shootFile = ShootFile::find($fileId);
            if (!$shootFile) return null;

            // Prefer web_path (optimized size) over storage_path (full size)
            $path = $shootFile->web_path ?? $shootFile->storage_path ?? $shootFile->path;
            if (!$path) return null;

            // If already a full URL, use directly
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                return $path;
            }

            // Build public URL
            $cleanPath = ltrim($path, '/');
            $url = url('storage/' . $cleanPath);

            Log::info('ProcessVideoGeneration: Using web URL', [
                'file_id' => $fileId,
                'path_type' => $shootFile->web_path ? 'web_path' : 'storage_path',
                'url' => $url,
            ]);

            return $url;
        } catch (\Exception $e) {
            Log::error('ProcessVideoGeneration: Error resolving web URL', [
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
