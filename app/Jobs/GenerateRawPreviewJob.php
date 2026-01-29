<?php

namespace App\Jobs;

use App\Services\RawPreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateRawPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    protected string $filePath;
    protected ?string $outputName;
    protected ?string $callbackUrl;

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath, ?string $outputName = null, ?string $callbackUrl = null)
    {
        $this->filePath = $filePath;
        $this->outputName = $outputName;
        $this->callbackUrl = $callbackUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(RawPreviewService $rawPreviewService): void
    {
        Log::info("GenerateRawPreviewJob: Starting", ['file' => $this->filePath]);

        $result = $rawPreviewService->generatePreview($this->filePath, $this->outputName);

        if ($result) {
            Log::info("GenerateRawPreviewJob: Success", [
                'file' => $this->filePath,
                'preview' => $result['previewUrl'] ?? null,
            ]);

            // Send callback if URL provided
            if ($this->callbackUrl) {
                $this->sendCallback($result);
            }
        } else {
            Log::error("GenerateRawPreviewJob: Failed", ['file' => $this->filePath]);

            if ($this->callbackUrl) {
                $this->sendCallback([
                    'success' => false,
                    'file' => $this->filePath,
                    'error' => 'Preview generation failed',
                ]);
            }
        }
    }

    /**
     * Send callback notification
     */
    protected function sendCallback(array $data): void
    {
        try {
            Http::timeout(10)->post($this->callbackUrl, [
                'file_path' => $this->filePath,
                'result' => $data,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            Log::warning("GenerateRawPreviewJob: Callback failed", [
                'url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateRawPreviewJob: Job failed permanently", [
            'file' => $this->filePath,
            'error' => $exception->getMessage(),
        ]);

        if ($this->callbackUrl) {
            $this->sendCallback([
                'success' => false,
                'file' => $this->filePath,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
