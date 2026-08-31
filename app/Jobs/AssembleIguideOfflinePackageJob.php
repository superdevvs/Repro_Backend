<?php

namespace App\Jobs;

use App\Services\DropboxWorkflowService;
use App\Services\IguideOfflineChunkUploadService;
use App\Services\IguideOfflinePackageService;
use App\Services\UploadValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssembleIguideOfflinePackageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 1800;

    public int $uniqueFor = 10800;

    public readonly string $assemblyToken;

    public function __construct(public readonly string $uploadSessionId, ?string $assemblyToken = null)
    {
        $this->assemblyToken = $assemblyToken ?? (string) Str::uuid();
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->uploadSessionId;
    }

    public function handle(
        IguideOfflineChunkUploadService $uploads,
        UploadValidationService $uploadValidation,
        IguideOfflinePackageService $packages,
        DropboxWorkflowService $dropbox
    ): void {
        if (! $uploads->claimAssembly($this->uploadSessionId, $this->assemblyToken)) {
            $retryAfter = $uploads->assemblyClaimRetryAfter($this->uploadSessionId);
            if ($retryAfter !== null) {
                $this->release($retryAfter);
            }

            return;
        }

        try {
            try {
                $uploads->finalizeAssembly(
                    $this->uploadSessionId,
                    $uploadValidation,
                    $packages,
                    $dropbox
                );
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first()
                    ?: 'The assembled iGUIDE ZIP failed validation.';
                $uploads->markValidationFailed($this->uploadSessionId, (string) $message);
            }
        } finally {
            $uploads->releaseAssembly($this->uploadSessionId, $this->assemblyToken);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            app(IguideOfflineChunkUploadService::class)->markAssemblyFailed(
                $this->uploadSessionId,
                $exception?->getMessage() ?: 'The package could not be assembled after multiple attempts.'
            );
        } catch (Throwable $failure) {
            Log::error('Unable to record resumable iGUIDE assembly failure.', [
                'upload_session_id' => $this->uploadSessionId,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
