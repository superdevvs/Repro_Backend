<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Stand-in for App\Jobs\MonitorIguideLabTourRun (WP4) with its spec 9.8 constructor, aliased by
 * TourLayerDoubles::aliasMissingMonitorJobs() only while the real class does not exist. It is never run.
 */
class TourRunMonitorStub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $runId, public ?string $token = null, public ?int $seq = null) {}

    public function handle(): void {}
}
