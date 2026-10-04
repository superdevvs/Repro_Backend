<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class EditingDispatchWriteLock
{
    /** Acquire SQLite's write reservation before taking a read snapshot. */
    public static function acquire(int $shootId): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // A no-op write waits under busy_timeout. A read-first transaction
            // instead fails immediately on promotion if another writer commits.
            // Laravel still owns the transaction and its queue/rollback hooks.
            DB::table('shoots')->where('id', $shootId)->update(['id' => DB::raw('id')]);
        }
    }
}
