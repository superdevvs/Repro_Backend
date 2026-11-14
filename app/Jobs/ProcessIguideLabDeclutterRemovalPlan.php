<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabDeclutterRemovalPlan;
use App\Services\IguideLab\LabDeclutterRemovalPlans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabDeclutterRemovalPlan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 4;
    public function __construct(public string $assessmentId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-declutter-removal-plan:'.$this->assessmentId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabDeclutterRemovalPlans $service): void
    {
        if ($record = IguideLabDeclutterRemovalPlan::find($this->assessmentId)) {
            $service->process($record);
            if ($record->fresh()->status === 'received') $this->release(5);
        }
    }
    public function failed(?\Throwable $error): void { if ($record = IguideLabDeclutterRemovalPlan::find($this->assessmentId)) app(LabDeclutterRemovalPlans::class)->attention($record, 'worker_stopped_unknown_outcome'); }
}
