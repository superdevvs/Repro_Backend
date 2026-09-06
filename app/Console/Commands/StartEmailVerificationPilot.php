<?php

namespace App\Console\Commands;

use App\Services\Users\EmailVerificationPilot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StartEmailVerificationPilot extends Command
{
    protected $signature = 'auth:start-email-verification-pilot {--apply : Persist the start of the 14-day pilot}';
    protected $description = 'Preview or start current-email verification for newly created and changed-email accounts';

    public function handle(): int
    {
        $start = app(EmailVerificationPilot::class)->startedAt();
        if ($start) {
            $this->info('Pilot already started: '.$start->toIso8601String().'; enforcement: '.$start->copy()->addDays(14)->toIso8601String());
            return self::SUCCESS;
        }
        if (!$this->option('apply')) {
            $this->info('Dry run: start a 14-day soft pilot for new accounts and changed email addresses. No accounts or settings changed.');
            return self::SUCCESS;
        }
        DB::table('auth_security_rollouts')->insertOrIgnore(['name' => 'email-verification', 'started_at' => now()]);
        $start = app(EmailVerificationPilot::class)->startedAt();
        $this->info('Pilot started: '.$start->toIso8601String().'; enforcement: '.$start->copy()->addDays(14)->toIso8601String());
        return self::SUCCESS;
    }
}
