<?php

namespace App\Console\Commands;

use App\Services\Crm\CrmFollowUpDigest;
use Illuminate\Console\Command;

/** Weekday 07:00: each person's follow-ups due today and overdue. */
class CrmFollowUpDigestCommand extends Command
{
    protected $signature = 'crm:follow-up-digest';

    protected $description = 'Email each Sales CRM user their follow-ups for today.';

    public function handle(CrmFollowUpDigest $digest): int
    {
        if (! config('crm.enabled')) {
            $this->line('CRM is switched off.');

            return self::SUCCESS;
        }
        $this->line('Digest: '.json_encode($digest->run()));

        return self::SUCCESS;
    }
}
