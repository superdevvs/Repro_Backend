<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabRun;
use App\Services\IguideLab\LabService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 125;

    public function __construct(public string $runId)
    {
        $this->onConnection(config('services.fal.workspace_queue_connection', 'studio'));
        $this->onQueue('studio');
    }

    public function middleware(): array
    {
        return [(new StudioWorkspaceLock('iguide-lab:'.$this->runId))->releaseAfter(15)->expireAfter(660)];
    }

    public function handle(LabService $service): void
    {
        $run = IguideLabRun::find($this->runId);
        if ($run && ! $service->process($run)) $this->release(15);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($run = IguideLabRun::find($this->runId)) app(LabService::class)->attention($run, 'The worker could not confirm completion. This run needs operator review.');
    }
}
