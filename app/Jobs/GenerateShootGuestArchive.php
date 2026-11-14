<?php

namespace App\Jobs;

use App\Services\Media\ArchiveQueue;
use App\Services\Shoots\ShootGuestArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class GenerateShootGuestArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public int $shareId, public string $archiveId, int $shootId)
    {
        $this->onQueue(ArchiveQueue::name($shootId));
        if ($this->queue === 'media-archives') $this->onConnection('database');
        $this->afterCommit();
    }

    public function handle(ShootGuestArchiveService $archives): void { $archives->generate($this->shareId, $this->archiveId); }

    public function failed(\Throwable $error): void { app(ShootGuestArchiveService::class)->failed($this->shareId, $this->archiveId); }
}
