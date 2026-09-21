<?php

namespace App\Console\Commands;

use App\Services\Voice\VoiceBrowserCallService;
use Illuminate\Console\Command;

class ReconcileVoiceBrowserCalls extends Command
{
    protected $signature = 'voice-browser:reconcile';

    protected $description = 'Recover stale browser call offers and revoke expired browser credentials';

    public function handle(VoiceBrowserCallService $calls): int
    {
        $this->info('Reconciled '.$calls->reconcile().' browser call connections.');

        return self::SUCCESS;
    }
}
