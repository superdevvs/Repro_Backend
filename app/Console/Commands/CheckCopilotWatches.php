<?php

namespace App\Console\Commands;

use App\Services\Copilot\CopilotWatches;
use Illuminate\Console\Command;

final class CheckCopilotWatches extends Command
{
    protected $signature = 'copilot:check-watches';

    protected $description = 'Notify connected Repro users of meaningful changes to explicitly watched shoots';

    public function handle(CopilotWatches $watches): int
    {
        $this->info('Watched shoot changes notified: '.$watches->check());

        return self::SUCCESS;
    }
}
