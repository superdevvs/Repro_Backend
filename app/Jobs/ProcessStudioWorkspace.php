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
use App\Jobs\Middleware\StudioWorkspaceLock;
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
        return [(new StudioWorkspaceLock('studio-workspace:'.$this->workspaceId))->releaseAfter(30)->expireAfter(7260)];
    }

    public function handle(WorkspaceProcessor $processor): void
    {
        $workspace = StudioWorkspace::find($this->workspaceId);
        if (! $workspace || data_get($workspace->operation, 'id') !== $this->operationId) {
            return;
        }
        if ($workspace->status === 'completed') {
            // A database contention after saving outputs must still be able to finish the shoot lanes.
            app(\App\Services\Studio\WorkspaceShootPublisher::class)->completeServices($workspace);
            if ($workspace->listing_studio_trial_id) {
                app(\App\Services\ListingStudio\ListingStudioGuestTrialService::class)->syncWorkspace($workspace);
            }
            return;
        }
        if (! $workspace->isBusy()) {
            return;
        }
        try {
            if ($workspace->listing_studio_trial_id) {
                app(\App\Services\ListingStudio\ListingStudioTrialMonitoring::class)->workerStarted($workspace->listing_studio_trial_id);
                $operation = $workspace->operation;
                $operation['trialStartedAt'] ??= now()->toIso8601String();
                $workspace->update(['operation' => $operation]);
                app(\App\Services\ListingStudio\ListingStudioGuestTrialService::class)->syncWorkspace($workspace->fresh());
            }
            $processor->process($workspace, $this->operationId);
        } catch (\App\Services\Studio\Providers\FotelloException $exception) {
            if ($exception->retryable && ! $exception->ambiguousOutcome) {
                throw $exception;
            }
            $this->failed($exception);
            $this->fail($exception);
        } catch (FalTerminalException|StudioClientAccessPaused|\App\Exceptions\StudioProviderException|\App\Exceptions\OpenAiImageException $exception) {
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
            $message = $exception instanceof FalTerminalException || $exception instanceof StudioClientAccessPaused || $exception instanceof \App\Exceptions\StudioProviderException || $exception instanceof \App\Exceptions\OpenAiImageException || $exception instanceof \App\Services\Studio\Providers\FotelloException
                ? $exception->getMessage()
                : 'The image provider or video renderer could not finish this operation. Retry to resume saved progress.';
            $changes = ['status' => 'failed', 'error' => $message, 'version' => $workspace->version + 1];
            if ($workspace->listing_studio_trial_id) {
                $operation = $workspace->operation;
                $operation['trialFailureAmbiguous'] = ($exception instanceof \App\Exceptions\StudioProviderException && $exception->ambiguous)
                    || ($exception instanceof \App\Exceptions\OpenAiImageException && $exception->ambiguous)
                    || ($exception instanceof \App\Services\Studio\Providers\FotelloException && $exception->ambiguousOutcome)
                    || ($exception instanceof FalTerminalException && ! $exception->canDiscardRequest() && ! empty($operation['requests']));
                if (! ($exception instanceof FalTerminalException || $exception instanceof StudioClientAccessPaused
                    || $exception instanceof \App\Exceptions\StudioProviderException || $exception instanceof \App\Exceptions\OpenAiImageException
                    || $exception instanceof \App\Services\Studio\Providers\FotelloException)) {
                    $operation['trialFailureAmbiguous'] = app(\App\Services\ListingStudio\ListingStudioTrialPipeline::class)->hasProviderCheckpoint($workspace);
                }
                $changes['operation'] = $operation;
            }
            $workspace->update($changes);
            if ($workspace->listing_studio_trial_id) {
                app(\App\Services\ListingStudio\ListingStudioGuestTrialService::class)->syncWorkspace($workspace->fresh());
            }
            if ($workspace->editing_dispatch_id) {
                \App\Models\ShootEditingDispatchItem::where('workspace_id', $workspace->id)->whereNull('primary_version_id')->update(['status' => 'failed', 'error' => $message]);
                app(\App\Services\Shoots\ScopedEditingDispatch::class)->reconcile($workspace->editing_dispatch_id);
            }
        }
    }
}
