<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabWorkflow;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 480;
    public function __construct(public string $workflowId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-workflow:'.$this->workflowId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabWorkflows $service): void
    {
        if (($record = IguideLabWorkflow::find($this->workflowId)) && ! $service->process($record)) $this->release(15);
    }
    public function failed(?\Throwable $error): void
    {
        if ($record = IguideLabWorkflow::find($this->workflowId)) app(LabWorkflows::class)->stop($record, 'workflow_interrupted', 'Processing stopped safely; existing requests and results were retained.');
    }
}
