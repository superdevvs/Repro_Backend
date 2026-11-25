<?php

namespace Tests\MySqlWriters\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Increments one probe row; optionally leaves its transaction open, as a buggy job could. */
final class ProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $row, public bool $leaveTransactionOpen = false) {}

    public function handle(): void
    {
        if ($this->leaveTransactionOpen) {
            DB::beginTransaction();
        }
        DB::table('_writer_policy_probe')->where('id', $this->row)->increment('counter');
    }
}
