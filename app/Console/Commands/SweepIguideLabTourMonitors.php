<?php
namespace App\Console\Commands;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Jobs\MonitorIguideLabTourRun;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourQueue;
use App\Services\IguideLab\LabTourRuns;
use App\Services\IguideLab\LabTourSurveys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every minute without overlap (routes/console.php, spec 9.9), so background recovery never depends on
 * client polling (D4): the lab-wide survey sweep, then a new monitor chain for every analysis or run whose chain is
 * dead (heartbeat stale or never written, and no job of that monitor waiting or reserved in the jobs table).
 * Pushes happen at the baseline transaction level, each in its own try; no cache gate is needed at one run a minute.
 */
class SweepIguideLabTourMonitors extends Command
{
    protected $signature = 'iguide-lab:tour-monitors';
    protected $description = 'Run the lab-wide tour survey sweep and restart dead tour monitor chains (one JSON line).';

    public function handle(): int
    {
        $summary = ['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 0, 'runsKicked' => 0];
        if (! LabTourConfig::analysisEnabled()) {
            $this->line(json_encode($summary));

            return self::SUCCESS;
        }
        $failed = false;
        try {
            $summary['surveysChanged'] = app(LabTourSurveys::class)->sweep() === true;
        } catch (\Throwable $e) {
            // The monitor kicks below still run; the exit code reports the failed sweep to the scheduler.
            $failed = true;
            Log::error('Tour survey sweep failed; monitor chains are still checked.', ['exception' => $e::class]);
        }
        $connection = LabTourConfig::queueConnection();
        $queue = LabTourConfig::queue();
        $stale = now()->subSeconds(LabTourConfig::heartbeatStaleSeconds());
        $dead = fn ($query) => $query->whereNull('monitor_heartbeat_at')->orWhere('monitor_heartbeat_at', '<', $stale);

        $analysisIds = IguideLabTourAnalysis::whereIn('status', ['queued', 'analysing'])->where('monitor_idle', false)->where($dead)
            ->orderBy('created_at')->orderBy('id')->pluck('id');
        foreach ($analysisIds as $id) {
            if (LabTourQueue::pendingJobExists($connection, $queue, 'MonitorIguideLabTourAnalysis', $id)) continue;
            if ($this->push(fn () => new MonitorIguideLabTourAnalysis($id), 'analysisId', $id)) $summary['analysesKicked']++;
        }

        $runs = app(LabTourRuns::class);
        // A completed run never needs a monitor; LabTourRuns::needsMonitor() applies the 9.2 step 7 lifetime rule.
        foreach (IguideLabTourRun::whereIn('status', ['creating', 'running', 'cancelled', 'failed'])->where($dead)->orderBy('created_at')->orderBy('id')->get() as $run) {
            try {
                if (! $runs->needsMonitor($run, false)) continue;
            } catch (\Throwable $e) {
                Log::warning('Tour run monitor check failed.', ['runId' => $run->id, 'exception' => $e::class]);
                continue;
            }
            if (LabTourQueue::pendingJobExists($connection, $queue, 'MonitorIguideLabTourRun', $run->id)) continue;
            if ($this->push(fn () => new MonitorIguideLabTourRun($run->id), 'runId', $run->id)) $summary['runsKicked']++;
        }

        $this->line(json_encode($summary));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** A new chain (null token, 9.8) through LabTourQueue::push; a failure is logged and the sweep goes on. */
    private function push(callable $job, string $key, string $id): bool
    {
        try {
            LabTourQueue::push($job());

            return true;
        } catch (\Throwable $e) {
            Log::warning('Tour monitor push failed; the next sweep retries it.', [$key => $id, 'exception' => $e::class]);

            return false;
        }
    }
}
