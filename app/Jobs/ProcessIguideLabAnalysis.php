<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabAnalysis;
use App\Services\IguideLab\LabAnalyses;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 660;
    public int $tries = 3;
    public function __construct(public string $analysisId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-analysis:'.$this->analysisId))->releaseAfter(15)->expireAfter(720)]; }
    public function handle(LabAnalyses $service): void
    {
        if ($record = IguideLabAnalysis::find($this->analysisId)) $service->process($record);
    }
    public function failed(?\Throwable $exception): void
    {
        if ($record = IguideLabAnalysis::find($this->analysisId)) app(LabAnalyses::class)->attention($record, 'This analysis needs operator review; no paid request will be repeated.');
    }
}
