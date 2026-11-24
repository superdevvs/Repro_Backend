<?php

namespace App\Console\Commands;

use App\Services\Recruiting\RecruitExpiryReminders;
use Illuminate\Console\Command;

/** 03:15 daily: client health scores, expired quotes and licence/insurance expiry reminders. */
class CrmNightly extends Command
{
    protected $signature = 'crm:nightly';

    protected $description = 'Sales CRM nightly jobs: client health and expiry reminders.';

    public function handle(RecruitExpiryReminders $expiry): int
    {
        if (! config('crm.enabled')) {
            $this->line('CRM is switched off.');

            return self::SUCCESS;
        }
        $out = [];
        $health = 'App\\Services\\Crm\\CrmClientHealth';
        if (class_exists($health)) {
            try {
                $out['health'] = app($health)->run();
            } catch (\Throwable $e) {
                report($e);
                $out['health'] = 'failed';
            }
        }
        try {
            $out['quotes_expired'] = app(\App\Services\Crm\CrmQuoteService::class)->expireDue();
        } catch (\Throwable $e) {
            report($e);
            $out['quotes_expired'] = 'failed';
        }
        try {
            $out['expiry_reminders'] = $expiry->run();
        } catch (\Throwable $e) {
            report($e);
            $out['expiry_reminders'] = 'failed';
        }
        $this->line('Nightly: '.json_encode($out));

        return self::SUCCESS;
    }
}
