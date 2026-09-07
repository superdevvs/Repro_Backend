<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Retired queue adapter. Existing serialized mirror jobs finish without remote work. */
class SyncShootFileToDropboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $shootFileId;

    public function __construct(int $shootFileId) { $this->shootFileId = $shootFileId; }
    public function handle(): void {}
}
