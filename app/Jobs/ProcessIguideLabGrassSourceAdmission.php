<?php
namespace App\Jobs;
use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabGrassSourceAdmission;
use App\Services\IguideLab\LabGrassSourceAdmissions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class ProcessIguideLabGrassSourceAdmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 4;
    public function __construct(public string $assessmentId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-grass-source-admission:'.$this->assessmentId))->releaseAfter(15)->expireAfter(630)]; }
    public function handle(LabGrassSourceAdmissions $service): void
    {
        if ($record = IguideLabGrassSourceAdmission::find($this->assessmentId)) {
            $service->process($record);
            if ($record->fresh()->status === 'received') $this->release(5);
        }
    }
    public function failed(?\Throwable $error): void { if ($record = IguideLabGrassSourceAdmission::find($this->assessmentId)) app(LabGrassSourceAdmissions::class)->attention($record, 'worker_stopped_unknown_outcome'); }
}
