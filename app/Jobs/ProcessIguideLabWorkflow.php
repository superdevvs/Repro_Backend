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
    public ?int $maxExceptions = null;
    public function __construct(public string $workflowId, public ?int $deadlineAt=null, public ?string $policySha256=null) {
        $this->onConnection('studio'); $this->onQueue('studio');
        if($deadlineAt!==null)$this->maxExceptions=3;
    }
    // Old serialized jobs predate these promoted properties entirely.
    public function retryUntil(): ?int { return $this->deadlineAt ?? null; }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-workflow:'.$this->workflowId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabWorkflows $service): void
    {
        if($record=IguideLabWorkflow::find($this->workflowId)){
            $scheduler=app(\App\Services\IguideLab\LabWorkflowScheduler::class);
            $scheduler->verifyJob($record,$this->deadlineAt??null,$this->policySha256??null);
            if(!$service->process($record))$this->release($scheduler->releaseDelay($record));
        }
    }
    public function failed(?\Throwable $error): void
    {
        if ($record = IguideLabWorkflow::find($this->workflowId)) app(LabWorkflows::class)->stop($record, 'workflow_interrupted', 'Processing stopped safely; existing requests and results were retained.');
    }
}
