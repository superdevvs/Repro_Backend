<?php

namespace App\Jobs;

use App\Http\Controllers\API\HiggsFieldController;
use App\Models\AiVideoGenerationJob;
use App\Models\AiVideoVerticalVariant;
use App\Models\ShootFile;
use App\Services\HiggsFieldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoAspectConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 3;
    public $backoff = [60, 300, 600];

    private const VARIANT_COUNT = 3;

    public function __construct(public AiVideoGenerationJob $videoJob)
    {
    }

    public function handle(HiggsFieldService $higgsFieldService): void
    {
        try {
            Log::info('ProcessVideoAspectConversion: Starting', [
                'job_id' => $this->videoJob->id,
            ]);

            $this->videoJob->markAsConvertingAspect();

            // Read images from disk as base64 for Higgsfield API
            $startFrameUrl = $this->resolveImageForApi($this->videoJob->start_frame_file_id)
                ?? $this->videoJob->original_start_frame_url;

            // Submit conversion requests for start frame
            $this->submitConversionRequests(
                $higgsFieldService,
                $startFrameUrl,
                'start'
            );

            // Submit conversion requests for end frame if present
            if ($this->videoJob->end_frame_file_id) {
                $endFrameUrl = $this->resolveImageForApi($this->videoJob->end_frame_file_id)
                    ?? $this->videoJob->original_end_frame_url;

                if ($endFrameUrl) {
                    $this->submitConversionRequests(
                        $higgsFieldService,
                        $endFrameUrl,
                        'end'
                    );
                }
            }

            // Poll all variants until complete
            $this->pollAllVariants($higgsFieldService);

        } catch (\Exception $e) {
            Log::error('ProcessVideoAspectConversion: Exception', [
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

    private function submitConversionRequests(
        HiggsFieldService $higgsFieldService,
        string $imageUrl,
        string $frameType
    ): void {
        $requestIds = $higgsFieldService->generateVerticalImages($imageUrl, self::VARIANT_COUNT);

        foreach ($requestIds as $index => $requestId) {
            AiVideoVerticalVariant::create([
                'video_generation_job_id' => $this->videoJob->id,
                'frame_type' => $frameType,
                'variant_index' => $index,
                'higgsfield_request_id' => $requestId,
                'status' => AiVideoVerticalVariant::STATUS_PENDING,
            ]);
        }

        Log::info('ProcessVideoAspectConversion: Submitted conversion requests', [
            'job_id' => $this->videoJob->id,
            'frame_type' => $frameType,
            'request_count' => count($requestIds),
        ]);
    }

    private function pollAllVariants(HiggsFieldService $higgsFieldService): void
    {
        $maxPolls = 60;
        $pollInterval = 10;
        $polls = 0;

        while ($polls < $maxPolls) {
            $pendingVariants = $this->videoJob->variants()
                ->where('status', AiVideoVerticalVariant::STATUS_PENDING)
                ->get();

            if ($pendingVariants->isEmpty()) {
                break;
            }

            foreach ($pendingVariants as $variant) {
                $status = $higgsFieldService->getRequestStatus($variant->higgsfield_request_id);

                if (!$status) {
                    continue;
                }

                $requestStatus = $status['status'] ?? 'unknown';

                if ($requestStatus === 'completed') {
                    $imageUrl = $status['image_urls'][0] ?? null;
                    if ($imageUrl) {
                        $variant->markAsCompleted($imageUrl);
                        Log::info('ProcessVideoAspectConversion: Variant completed', [
                            'job_id' => $this->videoJob->id,
                            'variant_id' => $variant->id,
                            'frame_type' => $variant->frame_type,
                        ]);
                    } else {
                        $variant->markAsFailed();
                        Log::warning('ProcessVideoAspectConversion: No image URL in completed response', [
                            'variant_id' => $variant->id,
                        ]);
                    }
                } elseif (in_array($requestStatus, ['failed', 'nsfw'])) {
                    $variant->markAsFailed();
                    Log::warning('ProcessVideoAspectConversion: Variant failed', [
                        'variant_id' => $variant->id,
                        'status' => $requestStatus,
                    ]);
                }
            }

            sleep($pollInterval);
            $polls++;
        }

        // Check results
        $completedCount = $this->videoJob->variants()
            ->where('status', AiVideoVerticalVariant::STATUS_COMPLETED)
            ->count();

        if ($completedCount > 0) {
            $this->videoJob->markAsAwaitingApproval();
            Log::info('ProcessVideoAspectConversion: Ready for approval', [
                'job_id' => $this->videoJob->id,
                'completed_variants' => $completedCount,
            ]);
        } else {
            $this->videoJob->markAsFailed('All vertical conversion variants failed');
        }
    }

    /**
     * Resolve a shoot file to a base64 data URI for sending to Higgsfield API.
     */
    private function resolveImageForApi(?int $fileId): ?string
    {
        if (!$fileId) return null;

        try {
            $shootFile = ShootFile::find($fileId);
            if (!$shootFile) return null;

            return HiggsFieldController::readImageAsBase64($shootFile);
        } catch (\Exception $e) {
            Log::error('ProcessVideoAspectConversion: Error resolving image', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoAspectConversion: Job failed permanently', [
            'job_id' => $this->videoJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->videoJob->markAsFailed('Aspect conversion failed: ' . $exception->getMessage());
    }
}
