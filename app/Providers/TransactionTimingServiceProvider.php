<?php

namespace App\Providers;

use App\Support\TransactionTimingLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registers the slow-transaction log only while TRANSACTION_TIMING_ENABLED is on. */
class TransactionTimingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TransactionTimingLog::class);
    }

    public function boot(): void
    {
        if (! config('transaction_timing.enabled')) {
            return;
        }

        $log = $this->app->make(TransactionTimingLog::class);
        Event::listen(TransactionBeginning::class, [$log, 'began']);
        Event::listen(QueryExecuted::class, [$log, 'queried']);
        Event::listen(TransactionCommitting::class, [$log, 'committing']);
        Event::listen(TransactionCommitted::class, [$log, 'committed']);
        Event::listen(TransactionRolledBack::class, [$log, 'rolledBack']);
    }
}
