<?php
namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Self-rescheduling analysis monitor (spec 9.7-9.8, D4). One chain per analysis: a null token starts a new chain
 * (create, resume, GET kick, sweeper), which takes over only a stopped or stale monitor; each successor carries the same
 * token and the next seq. A tick that stops (finished, idle, flag off) clears the heartbeat and token itself.
 */
class MonitorIguideLabTourAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 0;                  // lock releases never fail the job (5.15)
    public int $maxExceptions = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public string $analysisId, public ?string $token = null, public ?int $seq = null)
    {
        $this->token ??= (string) Str::uuid(); // null token = a new chain
        $this->onConnection(LabTourConfig::queueConnection());
        $this->onQueue(LabTourConfig::queue());
    }

    public function middleware(): array
    {
        return [(new StudioWorkspaceLock('iguide-tour-analysis-monitor:'.$this->analysisId))->releaseAfter(30)->expireAfter(330)];
    }

    public function handle(LabTourAnalyses $service): void
    {
        $seq = LabTourQueue::claimMonitor('iguide_lab_tour_analyses', $this->analysisId, $this->token, $this->seq, $this->attempts() > 1);
        if ($seq === null) return;                                   // another live chain owns this monitor
        if (! $service->tick($this->analysisId)) return;             // tick() clears heartbeat and token when it stops, flag off included
        LabTourQueue::push((new self($this->analysisId, $this->token, $seq + 1))->delay(now()->addSeconds(LabTourConfig::monitorInterval())));
        // the push is the last statement; if it fails it throws, and the job is retried (maxExceptions 3)
    }
}
