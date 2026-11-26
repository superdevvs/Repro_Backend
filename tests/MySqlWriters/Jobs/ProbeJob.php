<?php

namespace Tests\MySqlWriters\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Increments one probe row; optionally leaves its transaction open, as a buggy job could, and then fails. */
final class ProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $row, public bool $leaveTransactionOpen = false, public bool $failAfter = false) {}

    public function handle(): void
    {
        if ($this->leaveTransactionOpen) {
            DB::beginTransaction();
        }
        DB::table('_writer_policy_probe')->where('id', $this->row)->increment('counter');
        if ($this->failAfter) {
            throw new RuntimeException('The probe job failed.');
        }
    }
}
