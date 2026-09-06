<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuthSecurityLimits extends Command
{
    protected $signature = 'auth:prune-security-limits {--limit=1000 : Maximum expired rows to remove}';
    protected $description = 'Remove a bounded batch of expired authentication counters';

    public function handle(): int
    {
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $keys = DB::table('auth_security_limits')->where('expires_at', '<=', now()->timestamp)->orderBy('expires_at')->limit($limit)->pluck('key');
        // Recheck expiry so a concurrently renewed counter is never deleted.
        $count = DB::table('auth_security_limits')->whereIn('key', $keys)->where('expires_at', '<=', now()->timestamp)->delete();
        $this->info('Expired counters removed: '.$count);
        return self::SUCCESS;
    }
}
