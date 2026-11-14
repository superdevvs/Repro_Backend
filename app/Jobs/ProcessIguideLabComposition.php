<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabComposition;
use App\Services\IguideLab\LabCompositions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabComposition implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 300;
    public int $tries = 40;
    public function __construct(public string $compositionId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-native-geometry'))->shared()->releaseAfter(15)->expireAfter(360)]; }
    public function handle(LabCompositions $service): void { if ($record = IguideLabComposition::find($this->compositionId)) $service->process($record); }
    public function failed(?\Throwable $exception): void
    {
        if ($record = IguideLabComposition::find($this->compositionId)) app(LabCompositions::class)->attention($record, 'Local composition needs operator review.');
    }
}
