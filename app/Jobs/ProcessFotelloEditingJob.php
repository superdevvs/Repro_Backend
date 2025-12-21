<?php

namespace App\Jobs;

use App\Models\AiEditingJob;
use App\Services\FotelloService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFotelloEditingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $backoff = [60, 300, 600]; // Retry after 1min, 5min, 10min

    public function __construct(public AiEditingJob $editingJob)
    {
    }

    public function handle(FotelloService $fotelloService): void
    {
        try {
            Log::info('ProcessFotelloEditingJob: Starting', [
                'job_id' => $this->editingJob->id,
                'fotello_job_id' => $this->editingJob->fotello_job_id,
            ]);

            // If job doesn't have a Fotello job ID yet, submit it
            if (!$this->editingJob->fotello_job_id) {
                $this->submitJob($fotelloService);
            }

            // If still no Fotello job ID, something went wrong
            if (!$this->editingJob->fotello_job_id) {
                $this->editingJob->markAsFailed('Failed to submit job to Fotello');
                return;
            }

            // Poll for job completion
            $this->pollForCompletion($fotelloService);

        } catch (\Exception $e) {
            Log::error('ProcessFotelloEditingJob: Exception', [
                'job_id' => $this->editingJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->editingJob->incrementRetry();
            
            if ($this->editingJob->retry_count >= $this->tries) {
                $this->editingJob->markAsFailed('Max retries reached: ' . $e->getMessage());
            } else {
                // Re-queue the job
                throw $e;
            }
        }
    }

    /**
     * Submit the job to Fotello
     */
    private function submitJob(FotelloService $fotelloService): void
    {
        $this->editingJob->markAsProcessing();

        $result = $fotelloService->submitEditingJob(
            $this->editingJob->original_image_url,
            $this->editingJob->editing_type,
            $this->editingJob->editing_params ?? []
        );

        // Extract job ID from result (handle different response formats)
        $jobId = $result['job_id'] 
            ?? $result['id'] 
            ?? $result['enhance_id'] 
            ?? $result['enhanceId']
            ?? ($result['enhance']['id'] ?? null)
            ?? ($result['data']['job_id'] ?? $result['data']['id'] ?? null);
        
        if ($result && $jobId) {
            $this->editingJob->fotello_job_id = (string)$jobId;
            $this->editingJob->save();
            
            Log::info('ProcessFotelloEditingJob: Job ID extracted', [
                'job_id' => $this->editingJob->id,
                'fotello_job_id' => $this->editingJob->fotello_job_id,
                'response_structure' => array_keys($result),
            ]);

            Log::info('ProcessFotelloEditingJob: Job submitted to Fotello', [
                'job_id' => $this->editingJob->id,
                'fotello_job_id' => $this->editingJob->fotello_job_id,
            ]);
        } else {
            Log::error('ProcessFotelloEditingJob: Failed to submit to Fotello', [
                'job_id' => $this->editingJob->id,
                'result' => $result,
            ]);
        }
    }

    /**
     * Poll Fotello for job completion
     */
    private function pollForCompletion(FotelloService $fotelloService): void
    {
        $maxPolls = 60; // Poll up to 60 times
        $pollInterval = 10; // Wait 10 seconds between polls
        $polls = 0;

        while ($polls < $maxPolls) {
            $status = $fotelloService->getJobStatus($this->editingJob->fotello_job_id);

            if (!$status) {
                Log::warning('ProcessFotelloEditingJob: Failed to get status', [
                    'job_id' => $this->editingJob->id,
                    'fotello_job_id' => $this->editingJob->fotello_job_id,
                    'poll' => $polls,
                ]);
                
                // Wait before next poll
                sleep($pollInterval);
                $polls++;
                continue;
            }

            $jobStatus = $status['status'] ?? $status['state'] ?? 'unknown';

            Log::info('ProcessFotelloEditingJob: Status check', [
                'job_id' => $this->editingJob->id,
                'fotello_job_id' => $this->editingJob->fotello_job_id,
                'status' => $jobStatus,
                'poll' => $polls,
            ]);

            // Check if job is completed
            // Fotello API may use different status values
            if (in_array(strtolower($jobStatus), ['completed', 'done', 'finished', 'success', 'ready'])) {
                $this->handleJobCompletion($fotelloService, $status);
                return;
            }

            // Check if job failed
            if (in_array(strtolower($jobStatus), ['failed', 'error', 'cancelled', 'rejected'])) {
                $errorMessage = $status['error'] ?? $status['message'] ?? $status['error_message'] ?? 'Job failed';
                $this->editingJob->markAsFailed($errorMessage);
                return;
            }

            // Job is still processing, wait and poll again
            sleep($pollInterval);
            $polls++;
        }

        // Max polls reached, job is taking too long
        Log::warning('ProcessFotelloEditingJob: Max polls reached', [
            'job_id' => $this->editingJob->id,
            'fotello_job_id' => $this->editingJob->fotello_job_id,
        ]);

        // Don't mark as failed, just log - might complete later
        // Could implement webhook support for better handling
    }

    /**
     * Handle job completion
     */
    private function handleJobCompletion(FotelloService $fotelloService, array $status): void
    {
        // Get the edited image URL from various possible fields
        $editedImageUrl = $status['enhanced_image_url'] 
            ?? $status['enhancedImageUrl']
            ?? $status['result_url']
            ?? $status['resultUrl']
            ?? $status['image_url']
            ?? $status['edited_image_url']
            ?? null;

        // If not in status, try to download it
        if (!$editedImageUrl) {
            $editedImageUrl = $fotelloService->downloadEditedImage($this->editingJob->fotello_job_id);
        }

        if (!$editedImageUrl) {
            Log::error('ProcessFotelloEditingJob: No edited image URL found', [
                'job_id' => $this->editingJob->id,
                'fotello_job_id' => $this->editingJob->fotello_job_id,
                'status' => $status,
            ]);
            $this->editingJob->markAsFailed('Edited image URL not found in response');
            return;
        }

        // Mark job as completed
        $this->editingJob->markAsCompleted($editedImageUrl);

        Log::info('ProcessFotelloEditingJob: Job completed successfully', [
            'job_id' => $this->editingJob->id,
            'fotello_job_id' => $this->editingJob->fotello_job_id,
            'edited_image_url' => $editedImageUrl,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFotelloEditingJob: Job failed permanently', [
            'job_id' => $this->editingJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->editingJob->markAsFailed('Job processing failed: ' . $exception->getMessage());
    }
}

