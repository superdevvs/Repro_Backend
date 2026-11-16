<?php
namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabTourSurvey;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourSurveys;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Paid survey job (spec 5.15; template ProcessIguideLabGrassSourceAdmission.php:14-25). The paid unit is the cached
 * survey row, so one job per row. Pushed only through LabTourQueue::push at the baseline transaction level.
 */
class ProcessIguideLabTourSurvey implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    /** Lock releases never fail the job: a blocked duplicate is released, and every release counts as an attempt. */
    public int $tries = 0;
    /** Real exceptions are bounded. retryUntil() is not used: it is fixed at dispatch (Queue.php:171,259). */
    public int $maxExceptions = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public string $surveyId)
    {
        $this->onConnection(LabTourConfig::queueConnection());
        $this->onQueue(LabTourConfig::queue());
    }

    public function middleware(): array
    {
        return [$this->lock()->releaseAfter(15)->expireAfter(630)];
    }

    public function lock(): StudioWorkspaceLock
    {
        return new StudioWorkspaceLock('iguide-tour-survey:'.$this->surveyId);
    }

    public function handle(LabTourSurveys $service): void
    {
        if (! LabTourConfig::analysisEnabled()) {
            $service->releaseDispatch($this->surveyId);

            return;
        }
        if ($record = IguideLabTourSurvey::find($this->surveyId)) {
            $service->process($record);
            if ($record->fresh()?->status === 'received') $this->release(5);
        }
    }

    /** Acts only while holding the survey's own lock, so it never touches a survey that a live job is processing. */
    public function failed(?\Throwable $error): void
    {
        $lock = Cache::store('database')->lock($this->lock()->getLockKey($this), 630);
        if (! $lock->get()) return;
        try {
            if ($record = IguideLabTourSurvey::find($this->surveyId)) app(LabTourSurveys::class)->workerStopped($record);
        } finally {
            $lock->release();
        }
    }
}
