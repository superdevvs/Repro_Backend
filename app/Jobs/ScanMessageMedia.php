<?php

namespace App\Jobs;

use App\Models\MessageMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanMessageMedia implements ShouldQueue
{
    use Queueable;
    public int $tries = 4;
    public int $timeout = 600;
    public function __construct(public string $fileID) { $this->afterCommit(); }
    public function backoff(): array { return [15, 60, 180]; }
    public function handle(): void
    {
        if ($file = MessageMedia::find($this->fileID)) { app(\App\Services\Messaging\MessageMediaService::class)->scan($file); }
    }
    public function failed(?\Throwable $error): void
    {
        MessageMedia::whereKey($this->fileID)->where('status', 'scanning')->update(['status' => 'scan_failed']);
    }
}
