<?php

namespace App\Jobs;

use App\Models\AiVideoGenerationJob;
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

            // Determine frame URLs based on aspect ratio
            if ($this->videoJob->aspect_ratio === 'vertical') {
                $startFrameUrl = $this->videoJob->selected_start_frame_url;
                $endFrameUrl = $this->videoJob->selected_end_frame_url;
            } else {
                $startFrameUrl = $this->videoJob->original_start_frame_url;
                $endFrameUrl = $this->videoJob->original_end_frame_url;
            }

            if (!$startFrameUrl) {
                $this->videoJob->markAsFailed('No start frame URL available');
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

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoGeneration: Job failed permanently', [
            'job_id' => $this->videoJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->videoJob->markAsFailed('Video generation failed: ' . $exception->getMessage());
    }
}
