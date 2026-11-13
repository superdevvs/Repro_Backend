<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabPipeline;
use App\Services\IguideLab\LabPipelines;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 125;
    public function __construct(public string $pipelineId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-pipeline:'.$this->pipelineId))->releaseAfter(15)->expireAfter(660)]; }
    public function handle(LabPipelines $service): void
    {
        if (($pipeline = IguideLabPipeline::find($this->pipelineId)) && ! $service->process($pipeline)) $this->release(15);
    }
    public function failed(?\Throwable $exception): void
    {
        if ($pipeline = IguideLabPipeline::find($this->pipelineId)) app(LabPipelines::class)->attention($pipeline, 'The pipeline needs operator review; no paid request will be repeated automatically.');
    }
}
