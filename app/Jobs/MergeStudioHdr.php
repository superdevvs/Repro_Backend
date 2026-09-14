<?php

namespace App\Jobs;

use App\Services\Studio\WorkspaceHdrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class MergeStudioHdr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public array $media, public int $userId, public int $teamId)
    {
        $this->onConnection(config('services.fal.workspace_queue_connection', 'studio'));
        $this->onQueue('studio');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->media['id']))->dontRelease()->expireAfter(660)];
    }

    public function handle(WorkspaceHdrService $hdr): void
    {
        $hdr->process($this->media, $this->userId, $this->teamId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(WorkspaceHdrService::class)->failed($this->media);
    }
}
