<?php

namespace App\Jobs;

use App\Exceptions\FalTerminalException;
use App\Exceptions\StudioClientAccessPaused;
use App\Models\StudioWorkspace;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessStudioWorkspace implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $workspaceId, public string $operationId)
    {
        // Never run expensive provider work inside an HTTP request, including sync-configured installs.
        $this->onConnection(config('services.fal.workspace_queue_connection', 'studio'));
        $this->onQueue('studio');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('studio-workspace:'.$this->workspaceId))->releaseAfter(30)->expireAfter(7260)];
    }

    public function handle(WorkspaceProcessor $processor): void
    {
        $workspace = StudioWorkspace::find($this->workspaceId);
        if (! $workspace || data_get($workspace->operation, 'id') !== $this->operationId || ! $workspace->isBusy()) {
            return;
        }
        try {
            $processor->process($workspace, $this->operationId);
        } catch (FalTerminalException|StudioClientAccessPaused $exception) {
            // Invalid provider requests and a paused rollout cannot recover on an automatic retry.
            // Persist the friendly failure even when handle() runs without a queue job.
            $this->failed($exception);
            $this->fail($exception);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $workspace = StudioWorkspace::find($this->workspaceId);
        if ($workspace?->isBusy() && data_get($workspace->operation, 'id') === $this->operationId) {
            $message = $exception instanceof FalTerminalException || $exception instanceof StudioClientAccessPaused
                ? $exception->getMessage()
                : 'The image provider or video renderer could not finish this operation. Retry to resume saved progress.';
            $workspace->update(['status' => 'failed', 'error' => $message, 'version' => $workspace->version + 1]);
        }
    }
}
