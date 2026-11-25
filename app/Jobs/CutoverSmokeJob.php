<?php

namespace App\Jobs;

use App\Support\Cutover\CutoverSmokeFixture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The no-op job of the MySQL cutover write smoke (design v2, 5.3). It always
 * goes to database:cutover-smoke and does nothing: no query, no cache, no
 * dispatch. Its only trace is in the memory of the process that ran it, so
 * `cutover:smoke --write`, which runs the worker in-process, can tell that it
 * ran. The worker deletes its jobs row; a failure would leave a failed_jobs row.
 */
class CutoverSmokeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @var array<string, true> */
    private static array $ran = [];

    public function __construct(public readonly string $token)
    {
        $this->onConnection(CutoverSmokeFixture::QUEUE_CONNECTION)->onQueue(CutoverSmokeFixture::QUEUE);
    }

    public function handle(): void
    {
        self::$ran[$this->token] = true;
    }

    public static function ran(string $token): bool
    {
        return isset(self::$ran[$token]);
    }
}
