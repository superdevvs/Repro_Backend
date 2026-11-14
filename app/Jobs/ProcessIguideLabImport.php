<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabImport;
use App\Services\IguideLab\LabImports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600;
    public int $tries = 40;
    public function __construct(public string $importId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-source-import'))->shared()->releaseAfter(15)->expireAfter(660)]; }
    public function handle(LabImports $service): void { if ($record = IguideLabImport::find($this->importId)) $service->process($record); }
    public function failed(?\Throwable $exception): void { if ($record = IguideLabImport::find($this->importId)) app(LabImports::class)->attention($record, 'The source import worker stopped. No paid work was submitted.'); }
}
