<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabGrassAssessment;
use App\Services\IguideLab\LabGrassAssessments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabGrassAssessment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 4;
    public function __construct(public string $assessmentId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-grass-assessment:'.$this->assessmentId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabGrassAssessments $service): void
    {
        if ($record = IguideLabGrassAssessment::find($this->assessmentId)) {
            $service->process($record);
            if ($record->fresh()->status === 'received') $this->release(5);
        }
    }
    public function failed(?\Throwable $error): void { if ($record = IguideLabGrassAssessment::find($this->assessmentId)) app(LabGrassAssessments::class)->attention($record, 'worker_stopped_unknown_outcome'); }
}
