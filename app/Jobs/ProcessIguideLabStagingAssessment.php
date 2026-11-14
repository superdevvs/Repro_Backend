<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabStagingAssessment;
use App\Services\IguideLab\LabStagingAssessments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabStagingAssessment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 4;
    public function __construct(public string $assessmentId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-staging-assessment:'.$this->assessmentId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabStagingAssessments $service): void
    {
        if ($record = IguideLabStagingAssessment::find($this->assessmentId)) {
            $service->process($record);
            if ($record->fresh()->status === 'received') $this->release(5);
        }
    }
    public function failed(?\Throwable $error): void { if ($record = IguideLabStagingAssessment::find($this->assessmentId)) app(LabStagingAssessments::class)->attention($record, 'worker_stopped_unknown_outcome'); }
}
