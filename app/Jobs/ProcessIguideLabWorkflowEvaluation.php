<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabWorkflowEvaluation;
use App\Services\IguideLab\LabWorkflowEvaluations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabWorkflowEvaluation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 1;
    public function __construct(public string $evaluationId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-evaluation:'.$this->evaluationId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabWorkflowEvaluations $service): void
    {
        if ($record = IguideLabWorkflowEvaluation::find($this->evaluationId)) $service->process($record);
    }
    public function failed(?\Throwable $error): void
    {
        if ($record = IguideLabWorkflowEvaluation::find($this->evaluationId)) app(LabWorkflowEvaluations::class)->attention($record, 'The evaluation worker stopped; an unconfirmed request will not be repeated.');
    }
}
