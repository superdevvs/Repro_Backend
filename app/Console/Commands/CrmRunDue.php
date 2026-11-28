<?php

namespace App\Console\Commands;

use App\Services\Crm\Email\CrmCampaignService;
use App\Services\Crm\Email\CrmDripService;
use Illuminate\Console\Command;

/** Sends due drip steps and scheduled campaigns. Runs every five minutes. */
class CrmRunDue extends Command
{
    protected $signature = 'crm:send-due {--limit=200}';

    protected $description = 'Send due Sales CRM drip steps and scheduled campaigns.';

    public function handle(CrmDripService $drips, CrmCampaignService $campaigns): int
    {
        if (! config('crm.enabled')) {
            $this->line('CRM is switched off.');

            return self::SUCCESS;
        }
        if (config('crm.outbound_hold')) {
            $this->line('CRM outbound is held (CRM_OUTBOUND_HOLD). Nothing was claimed.');

            return self::SUCCESS;
        }
        $d = $drips->runDue((int) $this->option('limit'));
        $c = $campaigns->runDue();
        $this->line('Drips: '.json_encode($d).' Campaigns: '.json_encode($c));

        return self::SUCCESS;
    }
}
