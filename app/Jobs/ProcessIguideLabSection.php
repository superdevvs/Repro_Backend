<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabSection;
use App\Services\IguideLab\LabSections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIguideLabSection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 300;
    public int $tries = 40;
    public function __construct(public string $sectionId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-native-geometry'))->shared()->releaseAfter(15)->expireAfter(360)]; }
    public function handle(LabSections $service): void { if ($record = IguideLabSection::find($this->sectionId)) $service->process($record); }
    public function failed(?\Throwable $exception): void
    {
        if ($record = IguideLabSection::find($this->sectionId)) app(LabSections::class)->attention($record, 'Section execution needs operator review.');
    }
}
