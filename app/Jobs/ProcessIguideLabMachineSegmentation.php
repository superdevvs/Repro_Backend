<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabMachineSegmentation;
use App\Services\IguideLab\LabMachineSegmentations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabMachineSegmentation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600; public int $tries = 125;
    public function __construct(public string $segmentationId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-machine-segmentation:'.$this->segmentationId))->releaseAfter(15)->expireAfter(660)]; }
    public function handle(LabMachineSegmentations $service): void
    {
        if (($record = IguideLabMachineSegmentation::find($this->segmentationId)) && ! $service->process($record)) $this->release(15);
    }
    public function failed(?\Throwable $error): void
    {
        if ($record = IguideLabMachineSegmentation::find($this->segmentationId)) app(LabMachineSegmentations::class)->stop($record, 'The segmentation worker stopped; no paid request will be repeated.');
    }
}
