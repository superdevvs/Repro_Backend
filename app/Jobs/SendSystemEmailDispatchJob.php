<?php

namespace App\Jobs;

use App\Models\SystemEmailDispatch;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSystemEmailDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [15, 60, 180, 300];

    public int $timeout = 120;

    public function __construct(public int $dispatchId)
    {
        $this->onQueue('default');
    }

    public function handle(SystemEmailOrchestrator $orchestrator): void
    {
        $dispatch = SystemEmailDispatch::query()->find($this->dispatchId);

        if ($dispatch) {
            $orchestrator->processQueued($dispatch);
        }
    }
}
