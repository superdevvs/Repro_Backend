<?php

namespace Tests\MySqlWriters\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Records one committed effect per job. A second run hits the unique key and fails the job. */
final class EffectJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $number) {}

    public function handle(): void
    {
        DB::table('_writer_policy_effects')->insert(['job_no' => $this->number, 'worker_pid' => getmypid()]);
    }
}
