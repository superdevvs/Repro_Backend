<?php

namespace App\Jobs;

use App\Jobs\Middleware\StudioWorkspaceLock;
use App\Models\IguideLabImportUpload;
use App\Services\IguideLab\LabImportUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssembleIguideLabImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $timeout = 600; public int $tries = 40;
    public function __construct(public string $uploadId) { $this->onConnection('studio'); $this->onQueue('studio'); }
    public function middleware(): array { return [(new StudioWorkspaceLock('iguide-source-import'))->shared()->releaseAfter(15)->expireAfter(660)]; }
    public function handle(LabImportUploads $service): void { if ($upload = IguideLabImportUpload::find($this->uploadId)) $service->assemble($upload); }
    public function failed(?\Throwable $exception): void { if ($upload = IguideLabImportUpload::find($this->uploadId)) app(LabImportUploads::class)->fail($upload, 'The upload assembly worker stopped.'); }
}
