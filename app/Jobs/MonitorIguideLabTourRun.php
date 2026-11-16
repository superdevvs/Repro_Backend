<?php
namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourQueue;
use App\Services\IguideLab\LabTourRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Self-rescheduling run monitor (spec 9.8, D4). A chain is identified by its token; each job claims tick seq before it
 * works (LabTourQueue::claimMonitor), so two chains never both proceed and a retried tick re-claims its own seq.
 */
class MonitorIguideLabTourRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    /** Lock releases never fail the job; three exceptions do (spec 5.15, 9.8). */
    public int $tries = 0;
    public int $maxExceptions = 3;
    public array $backoff = [10, 30, 60];

    /** A null token starts a new chain (create, kick, sweeper). */
    public function __construct(public string $runId, public ?string $token = null, public ?int $seq = null)
    {
        $this->token ??= (string) Str::uuid();
        $this->onConnection(LabTourConfig::queueConnection());
        $this->onQueue(LabTourConfig::queue());
    }

    public function middleware(): array
    {
        return [(new StudioWorkspaceLock('iguide-tour-run-monitor:'.$this->runId))->releaseAfter(30)->expireAfter(330)];
    }

    public function handle(LabTourRuns $service): void
    {
        $seq = LabTourQueue::claimMonitor('iguide_lab_tour_runs', $this->runId, (string) $this->token, $this->seq, $this->attempts() > 1);
        if ($seq === null) return;                      // another live chain owns this monitor
        if (! $service->tick($this->runId)) return;     // tick() clears heartbeat and token when it stops, flag off included
        // The push is the last statement: a failed push throws and the job is retried (maxExceptions 3).
        LabTourQueue::push((new self($this->runId, $this->token, $seq + 1))->delay(now()->addSeconds(LabTourConfig::monitorInterval())));
    }
}
