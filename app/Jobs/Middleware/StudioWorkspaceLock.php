<?php

namespace App\Jobs\Middleware;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

/** Queue locks must be shared by HTTP and queue users without file-cache ownership dependencies. */
class StudioWorkspaceLock extends WithoutOverlapping
{
    public function handle($job, $next)
    {
        $lock = Cache::store('database')->lock($this->getLockKey($job), $this->expiresAfter);
        if (! $lock->get()) {
            $job->release($this->releaseAfter);
            return;
        }
        try {
            return $next($job);
        } finally {
            $lock->release();
        }
    }
}
